<?php

declare(strict_types=1);

/**
 * Harmless WRITE probes against the real API, through this package — run by hand:
 *
 *     php tests/Smoke/write.php --domain=<an unused domain of the account> [--areas=redirects,ipfilter]
 *
 * Creates, changes and deletes probe objects and records each exchange (anonymised in
 * memory, leak-checked) to .cache/captures/writes/ for review.
 *
 * Safeguards: refuses MYRA_DOMAIN and any domain not in the account; writes only to one
 * active, editable vhost of that domain; a guard ({@see WriteGuard}) refuses every write
 * outside the probed areas before it is sent; everything created is deleted in `finally` —
 * found again by id and by its probe marker — and verified gone; leftovers are reported.
 *
 * Probes:
 * - redirects: a DISABLED redirect of the exact path /__probe__/redirect;
 * - ipfilter: a DISABLED blacklist entry for 192.0.2.1 (documentation range, never a real
 *   client); v2 import of 192.0.2.2 / 192.0.2.3 (blacklist, documentation range) plus one
 *   invalid line — imported entries are enabled, but block only an address that never exists.
 */

namespace Myracloud\WebApi\Tests\Smoke;

use Closure;
use GuzzleHttp\Psr7\HttpFactory;
use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Auth\HmacSignature;
use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Exception\RequestRejectedException;
use Myracloud\WebApi\Model\IpFilter;
use Myracloud\WebApi\Model\Redirect;
use Myracloud\WebApi\V1;
use Myracloud\WebApi\V2;
use Psr\Http\Message\RequestInterface;
use Throwable;

require __DIR__ . '/../../vendor/autoload.php';

$root = dirname(__DIR__, 2);
$env = SmokeSession::env($root);
$options = getopt('', ['domain:', 'areas:']);
$options = is_array($options) ? $options : [];
$domain = strtolower(rtrim(is_string($options['domain'] ?? null) ? $options['domain'] : '', '.'));
$areas = explode(',', is_string($options['areas'] ?? null) ? $options['areas'] : 'redirects,ipfilter');
if ($domain === '' || $env('MYRA_KEY') === '' || $env('MYRA_SECRET') === '' || $env('MYRA_TOKEN') === '') {
    fwrite(STDERR, "Usage: php tests/Smoke/write.php --domain=<unused domain> [--areas=redirects,ipfilter]  (credentials in .smoke.env)\n");
    exit(2);
}
if ($domain === strtolower(rtrim($env('MYRA_DOMAIN'), '.'))) {
    fwrite(STDERR, "Refused: --domain is MYRA_DOMAIN, the domain of the read smoke run. Writes go to an unused domain only.\n");
    exit(2);
}

$anonymizer = SmokeSession::anonymizer($root);
$out = $root . '/.cache/captures/writes';
is_dir($out) || mkdir($out, 0o700, true);
$hmac = new HmacSignature($env('MYRA_KEY'), $env('MYRA_SECRET'));
$token = new BearerToken($env('MYRA_TOKEN'));

/** @var WriteGuard|null $guard set once the target vhost is known; until then every write is refused */
$guard = null;
$http = new RecordingClient(static function (RequestInterface $request) use (&$guard): bool {
    return $guard !== null && $guard->allows($request);
});
$v1 = new V1\Client($hmac, httpClient: $http);
$v2 = new V2\Client($token, httpClient: $http);

// ------------------------------------------------------------------ target: one vhost of $domain

try {
    $domainId = $v2->domainId($domain);
} catch (MyraException $e) {
    fwrite(STDERR, 'Refused: the domain is not in the account (' . $e::class . ").\n");
    exit(2);
}
$factory = new HttpFactory();
$answer = json_decode((string) $http->sendRequest($token->authenticate($factory->createRequest('GET', "https://apiv2.myracloud.com/domain/$domainId/subdomains")->withHeader('Accept', 'application/json')))->getBody(), true);
$http->take();
$vhosts = [];
foreach (is_array($answer) && is_array($answer['data'] ?? null) ? $answer['data'] : [] as $vhost) {
    if (is_array($vhost) && ($vhost['isActive'] ?? false) === true && ($vhost['access'] ?? false) === true
        && is_array($vhost['permissions'] ?? null) && ($vhost['permissions']['edit'] ?? false) === true
        && is_string($vhost['label'] ?? null) && ($vhost['domainName'] ?? null) === $domain) {
        $vhosts[] = strtolower(rtrim($vhost['label'], '.'));
    }
}
if ($vhosts === []) {
    fwrite(STDERR, "Refused: no active, editable vhost in that domain.\n");
    exit(2);
}
$vhost = $vhosts[0];
$guard = new WriteGuard($vhost, $domainId, ['redirects', 'ipfilter', 'ip-filters'], ['import']);
$shownVhost = $anonymizer->value($vhost);
$shownId = $anonymizer->value($domainId, 'id');
printf("Target: %s (domain %s)\n", is_string($shownVhost) ? $shownVhost : '?', is_int($shownId) ? (string) $shownId : '?');

// ------------------------------------------------------------------ harness

$number = 0;
$record = static function (string $area, string $version, string $step) use ($http, $anonymizer, $out, &$number): void {
    foreach ($http->take() as [$request, $response]) {
        $number++;
        $requestBody = (string) $request->getBody();
        $decodedRequest = json_decode($requestBody, true);
        $raw = (string) $response->getBody();
        $decoded = json_decode($raw, true);
        $result = [
            'area' => $area,
            'apiVersion' => $version,
            'auth' => str_starts_with($request->getHeaderLine('Authorization'), 'MYRA ') ? 'HMAC' : 'Bearer',
            'step' => $step,
            'request' => [
                'method' => $request->getMethod(),
                'path' => $anonymizer->path($request->getUri()->getPath() . ($request->getUri()->getQuery() === '' ? '' : '?' . $request->getUri()->getQuery())),
                'contentType' => $request->getHeaderLine('Content-Type'),
                'body' => $requestBody === '' ? null : (is_array($decodedRequest) ? $anonymizer->value($decodedRequest) : $anonymizer->text($requestBody)),
            ],
            'response' => [
                'status' => $response->getStatusCode(),
                'contentType' => $response->getHeaderLine('Content-Type') === '' ? null : $response->getHeaderLine('Content-Type'),
                'body' => is_array($decoded) ? $anonymizer->value($decoded) : $anonymizer->text($raw),
            ],
            'finding' => '',
        ];
        $json = (string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $leaks = $anonymizer->leaks($json, json_decode($json, true));
        if ($leaks === []) {
            file_put_contents(sprintf('%s/%s-%s-%02d-%s.json', $out, $area, $version, $number, $step), $json . "\n");
        }
        printf("  %-4s %-6s %-58s %d %s\n", $version, $request->getMethod(), $result['request']['path'], $response->getStatusCode(), $leaks === [] ? '' : 'NOT WRITTEN: ' . implode('; ', $leaks));
    }
};
$say = static function (string $message) use ($anonymizer): void {
    echo '  ' . $anonymizer->text($message) . "\n";
};
$failures = 0;
/** @var list<string> $leftovers */
$leftovers = [];
/**
 * Runs a probe; $cleanup always runs after it and returns what could not be removed.
 *
 * @param Closure(): void $probe
 * @param Closure(): list<string> $cleanup
 */
$run = static function (string $area, string $version, Closure $probe, Closure $cleanup) use ($record, $say, &$failures, &$leftovers): void {
    echo "$area, $version\n";
    try {
        $probe();
    } catch (Throwable $e) {
        $failures++;
        $record($area, $version, 'failed');
        $say('FAIL ' . $e::class . ': ' . $e->getMessage());
    } finally {
        try {
            $left = array_values(array_filter((array) $cleanup(), 'is_string'));
            $record($area, $version, 'cleanup');
            $say($left === [] ? 'probes deleted, verified' : '!! still there: ' . implode(', ', $left));
            array_push($leftovers, ...$left);
        } catch (Throwable $e) {
            $leftovers[] = "$area $version (cleanup failed)";
            $say('!! cleanup failed: ' . $e::class . ': ' . $e->getMessage());
        }
    }
};
/** Updates with a stale version must be rejected: Myra's optimistic lock. */
$stale = static function (Closure $update) use ($say): void {
    try {
        $update();
        $say('!! an update with the stale modified was ACCEPTED (no optimistic lock)');
    } catch (RequestRejectedException $e) {
        $say('stale modified rejected: ' . $e->getMessage());
    }
};

// ------------------------------------------------------------------ redirects

if (in_array('redirects', $areas, true)) {
    foreach (['v1' => $v1, 'v2' => $v2] as $version => $client) {
        $redirects = $client->redirects();
        $isProbe = static fn(Redirect $r): bool => str_starts_with($r->source(), '/__probe__/');
        $run('redirects', $version, static function () use ($redirects, $client, $version, $vhost, $record, $stale): void {
            $created = $redirects->create(new Redirect($vhost, '/__probe__/redirect', "https://$vhost/__probe__/target", Redirect::TYPE_REDIRECT, Redirect::MATCH_EXACT, enabled: false));
            $record('redirects', $version, 'create');
            if ($client instanceof V2\Client) {
                $client->redirects()->get($vhost, (int) $created->id());
                $record('redirects', $version, 'get');
            }
            $redirects->update($created->withDestination("https://$vhost/__probe__/target2"));
            $record('redirects', $version, 'update');
            $stale(static fn() => $redirects->update($created->withDestination("https://$vhost/__probe__/stale")));
            $record('redirects', $version, 'update-stale');
        }, static function () use ($redirects, $vhost, $isProbe): array {
            foreach (array_filter($redirects->list($vhost), $isProbe) as $probe) {
                $redirects->delete($probe);
            }

            return array_values(array_map(static fn(Redirect $r): string => 'redirect ' . $r->id(), array_filter($redirects->list($vhost), $isProbe)));
        });
    }
}

// ------------------------------------------------------------------ IP filter

if (in_array('ipfilter', $areas, true)) {
    $probeValues = ['192.0.2.1/32', '192.0.2.2/32', '192.0.2.3/32'];
    foreach (['v1' => $v1, 'v2' => $v2] as $version => $client) {
        $filters = $client->ipFilters();
        $isProbe = static fn(IpFilter $f): bool => in_array($f->value(), $probeValues, true) && $f->type() === IpFilter::TYPE_BLACKLIST;
        $run('ipfilter', $version, static function () use ($filters, $client, $version, $vhost, $domainId, $record, $stale, $say, $http, $factory, $token, $hmac): void {
            $created = $filters->create(new IpFilter($vhost, IpFilter::TYPE_BLACKLIST, '192.0.2.1/32', enabled: false, comment: '__probe__'));
            $record('ipfilter', $version, 'create');
            if ($client instanceof V2\Client) {
                $client->ipFilters()->get($vhost, (int) $created->id());
                $record('ipfilter', $version, 'get');
            }
            $filters->update($created->withComment('__probe__ updated'));
            $record('ipfilter', $version, 'update');
            $stale(static fn() => $filters->update($created->withComment('__probe__ stale')));
            $record('ipfilter', $version, 'update-stale');
            if ($client instanceof V2\Client) {
                $client->ipFilters()->export($vhost, IpFilter::TYPE_BLACKLIST);
                $record('ipfilter', $version, 'export');
                // import: not in the package yet — raw, to capture the answer (token and HMAC)
                foreach (['token' => [$token, "192.0.2.2/32,2099-12-31T23:59:59+0100,__probe__\nnot-an-ip,,__probe__\n"], 'hmac' => [$hmac, "192.0.2.3/32,,__probe__\n"]] as $name => [$auth, $csv]) {
                    $request = $factory->createRequest('POST', "https://apiv2.myracloud.com/domain/$domainId/ip-filters/" . rawurlencode($vhost) . '/import?type=BLACKLIST')
                        ->withHeader('Accept', 'application/json')->withHeader('Content-Type', 'text/csv')->withBody($factory->createStream($csv));
                    $response = $http->sendRequest($auth->authenticate($request));
                    $record('ipfilter', $version, "import-$name");
                    $say("import ($name): HTTP " . $response->getStatusCode());
                }
            }
        }, static function () use ($filters, $vhost, $isProbe): array {
            foreach (array_filter($filters->list($vhost), $isProbe) as $probe) {
                $filters->delete($probe);
            }

            return array_values(array_map(static fn(IpFilter $f): string => 'IP filter ' . $f->id(), array_filter($filters->list($vhost), $isProbe)));
        });
    }
}

$leftovers = array_filter($leftovers, 'is_string');
echo $leftovers === [] ? "No leftovers.\n" : 'LEFTOVERS — delete by hand: ' . implode(', ', $leftovers) . "\n";
exit($failures === 0 && $leftovers === [] ? 0 : 1);
