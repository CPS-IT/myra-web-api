<?php

declare(strict_types=1);

/**
 * Harmless WRITE probes against the real API, through this package — run by hand:
 *
 *     php tests/Smoke/write.php --domain=<an unused domain of the account>
 *
 * Creates, changes and deletes a probe object per area and records each exchange
 * (anonymised in memory, leak-checked) to .cache/captures/writes/ for review.
 *
 * Safeguards: refuses MYRA_DOMAIN and any domain not in the account; writes only to one
 * active, editable vhost of that domain; a guard refuses every write outside the probed
 * paths before it is sent; probes match only /__probe__/… paths; everything created is
 * deleted in `finally`, leftovers are reported with their id.
 *
 * Areas: redirects (a redirect of the exact path /__probe__/redirect, created disabled —
 * v1 may ignore `enabled`, then it still only affects that path).
 */

namespace Myracloud\WebApi\Tests\Smoke;

use GuzzleHttp\Psr7\HttpFactory;
use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Auth\HmacSignature;
use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Exception\RequestRejectedException;
use Myracloud\WebApi\Model\Redirect;
use Myracloud\WebApi\V1;
use Myracloud\WebApi\V2;
use Psr\Http\Message\RequestInterface;
use Throwable;

require __DIR__ . '/../../vendor/autoload.php';

$root = dirname(__DIR__, 2);
$env = SmokeSession::env($root);
$options = getopt('', ['domain:']);
$domain = strtolower(rtrim(is_array($options) && is_string($options['domain'] ?? null) ? $options['domain'] : '', '.'));
if ($domain === '' || $env('MYRA_KEY') === '' || $env('MYRA_SECRET') === '' || $env('MYRA_TOKEN') === '') {
    fwrite(STDERR, "Usage: php tests/Smoke/write.php --domain=<unused domain>  (credentials in .smoke.env)\n");
    exit(2);
}
if ($domain === strtolower(rtrim($env('MYRA_DOMAIN'), '.'))) {
    fwrite(STDERR, "Refused: --domain is MYRA_DOMAIN, the domain of the read smoke run. Writes go to an unused domain only.\n");
    exit(2);
}

$anonymizer = SmokeSession::anonymizer($root);
$out = $root . '/.cache/captures/writes';
is_dir($out) || mkdir($out, 0o700, true);

// ------------------------------------------------------------------ target: one vhost of $domain

/** @var WriteGuard|null $guard set once the target vhost is known; until then every write is refused */
$guard = null;
$http = new RecordingClient(static function (RequestInterface $request) use (&$guard): bool {
    return $guard !== null && $guard->allows($request);
});
$v1 = new V1\Client(new HmacSignature($env('MYRA_KEY'), $env('MYRA_SECRET')), httpClient: $http);
$v2 = new V2\Client(new BearerToken($env('MYRA_TOKEN')), httpClient: $http);

try {
    $domainId = $v2->domainId($domain);
} catch (MyraException $e) {
    fwrite(STDERR, 'Refused: the domain is not in the account (' . $e::class . ").\n");
    exit(2);
}
$vhosts = [];
$request = (new BearerToken($env('MYRA_TOKEN')))->authenticate((new HttpFactory())->createRequest('GET', 'https://apiv2.myracloud.com/domain/' . $domainId . '/subdomains')->withHeader('Accept', 'application/json'));
$answer = json_decode((string) $http->sendRequest($request)->getBody(), true);
$http->take();
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
$target = ['vhost' => $vhosts[0], 'id' => $domainId];
$guard = new WriteGuard($target['vhost'], $domainId, ['redirects']);
$shownVhost = $anonymizer->value($target['vhost']);
$shownId = $anonymizer->value($domainId, 'id');
printf("Target: %s (domain %s, %d candidate vhosts)\n", is_string($shownVhost) ? $shownVhost : '?', is_int($shownId) ? (string) $shownId : '?', count($vhosts));

// ------------------------------------------------------------------ recording

$number = 0;
$record = static function (string $version, string $step) use ($http, $anonymizer, $out, &$number): void {
    foreach ($http->take() as [$request, $response]) {
        $number++;
        $requestBody = (string) $request->getBody();
        $decodedRequest = json_decode($requestBody, true);
        $raw = (string) $response->getBody();
        $decoded = json_decode($raw, true);
        $result = [
            'area' => 'redirects',
            'apiVersion' => $version,
            'auth' => $version === 'v1' ? 'HMAC' : 'Bearer',
            'step' => $step,
            'request' => [
                'method' => $request->getMethod(),
                'path' => $anonymizer->path($request->getUri()->getPath() . ($request->getUri()->getQuery() === '' ? '' : '?' . $request->getUri()->getQuery())),
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
            file_put_contents(sprintf('%s/%s-%02d-%s.json', $out, $version, $number, $step), $json . "\n");
        }
        printf("  %-4s %-6s %-52s %d %s\n", $version, $request->getMethod(), $result['request']['path'], $response->getStatusCode(), $leaks === [] ? '' : 'NOT WRITTEN: ' . implode('; ', $leaks));
    }
};

// ------------------------------------------------------------------ redirects

$failures = 0;
$leftovers = [];
foreach (['v1' => $v1, 'v2' => $v2] as $version => $client) {
    echo "redirects, $version\n";
    $redirects = $client->redirects();
    $created = null;
    $current = null;
    try {
        $created = $current = $redirects->create(new Redirect($target['vhost'], '/__probe__/redirect', 'https://' . $target['vhost'] . '/__probe__/target', Redirect::TYPE_REDIRECT, Redirect::MATCH_EXACT, enabled: false));
        $record($version, 'create');
        if ($version === 'v2') {
            $v2->redirects()->get($target['vhost'], (int) $created->id());
            $record($version, 'get');
        }
        $current = $redirects->update($created->withDestination('https://' . $target['vhost'] . '/__probe__/target2'));
        $record($version, 'update');
        try {
            $redirects->update($created->withDestination('https://' . $target['vhost'] . '/__probe__/stale'));
            echo "  !! an update with the stale modified was ACCEPTED (no optimistic lock)\n";
            $current = null; // version unknown now: delete via a fresh read below
        } catch (RequestRejectedException $e) {
            echo '  stale modified rejected: ' . $anonymizer->text($e->getMessage()) . "\n";
        }
        $record($version, 'update-stale');
    } catch (Throwable $e) {
        $failures++;
        $record($version, 'failed');
        echo '  FAIL ' . $e::class . ': ' . $anonymizer->text($e->getMessage()) . "\n";
    } finally {
        if ($created !== null) {
            try {
                foreach ($redirects->list($target['vhost']) as $redirect) {
                    if ($redirect->id() === $created->id()) {
                        $current = $redirect; // the latest version, whatever happened above
                    }
                }
                $record($version, 'list');
                if ($current !== null) {
                    $redirects->delete($current);
                    $record($version, 'delete');
                }
                $gone = true;
                foreach ($redirects->list($target['vhost']) as $redirect) {
                    $gone = $gone && $redirect->id() !== $created->id();
                }
                $record($version, 'verify');
                echo $gone ? "  probe deleted, verified\n" : "  !! probe still listed\n";
                if (!$gone) {
                    $leftovers[] = "$version redirect id " . $created->id();
                }
            } catch (Throwable $e) {
                $leftovers[] = "$version redirect id " . $created->id();
                echo '  !! cleanup failed: ' . $e::class . ': ' . $anonymizer->text($e->getMessage()) . "\n";
            }
        }
    }
}

echo $leftovers === [] ? "No leftovers.\n" : 'LEFTOVERS — delete by hand: ' . implode(', ', $leftovers) . "\n";
exit($failures === 0 && $leftovers === [] ? 0 : 1);
