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
 * - dns: a TXT record __probe__.<domain> and an A record myra-web-api-probe.<domain> →
 *   192.0.2.1 (documentation range), both inactive (not proxied), the A record with changed
 *   load balancing settings; both deleted again;
 * - maintenance: a maintenance page for 2099 (v2: also active=false) — deleted at once and
 *   the run aborted if Myra reports it active; v2 maintenance templates (stored HTML only);
 * - errorpages: an error page for a code the vhost has NO page for yet (413, else 405, else
 *   400 — never one that exists), set, replaced and deleted; cleanup deletes only that code;
 * - settings: the vhost's OWN subdomain settings, read and posted back UNCHANGED (a no-op
 *   whether Myra replaces or merges them), read again and compared — restored if different;
 * - cachesettings: a DISABLED rule for the exact path /__probe__/ (ttl -1 = no cache);
 * - waf (v2): a DISABLED WAF rule that matches only the exact path /__probe__/waf and only
 *   logs (processNext: later rules still run) — deleted at once and the run aborted if Myra
 *   reports it enabled; the guard allows its update and delete only once it exists;
 * - ipfilter: a DISABLED blacklist entry for 192.0.2.1 (documentation range, never a real
 *   client); v2 import of 192.0.2.2 / 192.0.2.3 (blacklist, documentation range) plus one
 *   invalid line — imported entries are enabled, but block only an address that never exists.
 */

namespace Myracloud\WebApi\Tests\Smoke;

use ArrayObject;
use Closure;
use GuzzleHttp\Psr7\HttpFactory;
use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Auth\HmacSignature;
use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Exception\RequestRejectedException;
use Myracloud\WebApi\Http\JsonObject;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\CacheSetting;
use Myracloud\WebApi\Model\DnsRecord;
use Myracloud\WebApi\Model\ErrorPage;
use Myracloud\WebApi\Model\IpFilter;
use Myracloud\WebApi\Model\Maintenance;
use Myracloud\WebApi\Model\MaintenanceTemplate;
use Myracloud\WebApi\Model\Page;
use Myracloud\WebApi\Model\Redirect;
use Myracloud\WebApi\V1;
use Myracloud\WebApi\V2;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use stdClass;
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
$wafRules = "domain/$domainId/" . rawurlencode($vhost) . '/waf-rules';
$guard = new WriteGuard($vhost, $domainId, ['redirects', 'ipfilter', 'ip-filters', 'cacheSettings', 'cache-settings', 'subdomainSetting', 'settings', 'maintenance', 'maintenances'], ['import'], $domain, ['dnsRecords', 'dns-records', 'maintenance-templates', 'errorpages'], ["POST /$wafRules"]);
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
                // an empty JSON object stays an object ({}, not [] as a PHP array would encode)
                'body' => $requestBody === '' ? null : (trim($requestBody) === '{}' ? new stdClass() : (is_array($decodedRequest) ? $anonymizer->value($decodedRequest) : $anonymizer->text($requestBody))),
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
// ------------------------------------------------------------------ DNS records and load balancing

if (in_array('dns', $areas, true)) {
    foreach (['v1' => $v1, 'v2' => $v2] as $version => $client) {
        $records = $client->dnsRecords();
        $isProbe = static fn(DnsRecord $r): bool => str_starts_with($r->name(), '__probe__.') || str_starts_with($r->name(), 'myra-web-api-probe.');
        $run('dns', $version, static function () use ($records, $client, $version, $domain, $record, $stale, $say): void {
            $txt = $records->create($domain, new DnsRecord("__probe__.$domain", 'TXT', 'myra-web-api probe', 300, comment: '__probe__'));
            $record('dns', $version, 'create-txt');
            if ($client instanceof V2\Client) {
                $client->dnsRecords()->get($domain, (int) $txt->id());
                $record('dns', $version, 'get');
            }
            $records->update($domain, $txt->withValue('myra-web-api probe 2'));
            $record('dns', $version, 'update');
            $stale(static fn() => $records->update($domain, $txt->withValue('myra-web-api probe stale')));
            $record('dns', $version, 'update-stale');
            $a = $records->create($domain, new DnsRecord("myra-web-api-probe.$domain", 'A', '192.0.2.1', 300, comment: '__probe__'));
            $record('dns', $version, 'create-a');
            $records->loadBalancing($domain);
            $record('dns', $version, 'load-balancing');
            $options = $a->upstreamOptions();
            $say('A record upstreamOptions: ' . ($options === null ? 'none' : sprintf('id %s, weight %d', $options->id() === null ? '-' : 'set', $options->weight())));
            if ($options !== null) {
                // through the normal update, in both versions
                $changed = $records->update($domain, $a->withUpstreamOptions($options->withWeight(2)));
                $record('dns', $version, 'update-load-balancing');
                $say('weight after update(): ' . ($changed->upstreamOptions()?->weight() ?? 'none'));
                if ($client instanceof V2\Client) {
                    // the dedicated v2 endpoint (answered HTTP 500 for an inactive record before)
                    try {
                        $client->dnsRecords()->updateLoadBalancing($domain, $changed->withUpstreamOptions(($changed->upstreamOptions() ?? $options)->withWeight(3)));
                        $say('PUT …/load-balancing: accepted');
                    } catch (MyraException $e) {
                        $say('PUT …/load-balancing: ' . $e->getMessage());
                    }
                    $record('dns', $version, 'load-balancing-endpoint');
                }
            }
        }, static function () use ($records, $domain, $isProbe): array {
            foreach (array_filter($records->list($domain), $isProbe) as $probe) {
                $records->delete($domain, $probe);
            }

            return array_values(array_map(static fn(DnsRecord $r): string => 'DNS record ' . $r->id(), array_filter($records->list($domain), $isProbe)));
        });
    }
}

// ------------------------------------------------------------------ maintenance

if (in_array('maintenance', $areas, true)) {
    foreach (['v1' => $v1, 'v2' => $v2] as $version => $client) {
        $maintenances = $client->maintenances();
        $isProbe = static fn(Maintenance $m): bool => str_starts_with($m->start(), '2099-') && str_contains((string) $m->content(), '__probe__');
        $run('maintenance', $version, static function () use ($maintenances, $client, $version, $vhost, $domain, $record, $stale): void {
            $created = $maintenances->create(new Maintenance($vhost, '2099-01-01T00:00:00+0000', '2099-01-01T01:00:00+0000', '<html>__probe__</html>', false));
            $record('maintenance', $version, 'create');
            if ($created->isActive()) {
                $maintenances->delete($created);
                $record('maintenance', $version, 'emergency-delete');
                throw new RuntimeException('Myra reported the 2099 probe maintenance ACTIVE — deleted at once.');
            }
            $updated = $maintenances->update($created->withContent('<html>__probe__ updated</html>'));
            $record('maintenance', $version, 'update');
            $stale(static fn() => $maintenances->update($created->withContent('<html>__probe__ stale</html>')));
            $record('maintenance', $version, 'update-stale');
            if ($client instanceof V2\Client) {
                $client->maintenances()->get($domain, (int) $updated->id());
                $record('maintenance', $version, 'get');
                $client->maintenances()->forDomain($domain, true);
                $record('maintenance', $version, 'for-domain');
                $templates = $client->maintenanceTemplates();
                $template = $templates->create($domain, new MaintenanceTemplate('__probe__', '<html>__probe__</html>'));
                $record('maintenance', $version, 'template-create');
                $template = $templates->update($domain, $template->withContent('<html>__probe__ updated</html>'));
                $record('maintenance', $version, 'template-update');
                $templates->list($domain);
                $record('maintenance', $version, 'template-list');
                $templates->delete($domain, $template);
                $record('maintenance', $version, 'template-delete');
            }
        }, static function () use ($maintenances, $client, $vhost, $domain, $isProbe): array {
            foreach (array_filter($maintenances->list($vhost), $isProbe) as $probe) {
                $maintenances->delete($probe);
            }
            $left = array_values(array_map(static fn(Maintenance $m): string => 'maintenance ' . $m->id(), array_filter($maintenances->list($vhost), $isProbe)));
            if ($client instanceof V2\Client) {
                $templates = $client->maintenanceTemplates();
                foreach (array_filter($templates->list($domain), static fn(MaintenanceTemplate $t): bool => $t->name() === '__probe__') as $template) {
                    $templates->delete($domain, $template);
                    $left[] = 'maintenance template ' . $template->id() . ' (deleted in cleanup)';
                }
            }

            return array_values(array_filter($left, static fn(string $l): bool => !str_ends_with($l, '(deleted in cleanup)')));
        });
    }
}

// ------------------------------------------------------------------ error pages

if (in_array('errorpages', $areas, true)) {
    foreach (['v1' => $v1, 'v2' => $v2] as $version => $client) {
        $pages = $client->errorPages();
        /** @var ArrayObject<string, int> $state 'code': the code that was free before the probe */
        $state = new ArrayObject();
        // the codes the vhost has a page for
        $codesOf = static function (array $list) use ($vhost): array {
            $codes = [];
            foreach ($list as $page) {
                if ($page instanceof ErrorPage && $page->subdomain() === $vhost) {
                    $codes[] = $page->errorCode();
                }
            }

            return $codes;
        };
        $run('errorpages', $version, static function () use ($pages, $client, $version, $vhost, $domain, $record, $say, $state, $codesOf): void {
            $taken = $codesOf($pages->list($domain));
            $record('errorpages', $version, 'list-before');
            $free = array_values(array_diff([413, 405, 400], $taken));
            if ($free === []) {
                $say('skipped: the vhost has pages for 413, 405 and 400 already');

                return;
            }
            $code = $free[0];
            $state['code'] = $code;
            $say("probing code $code (free on this vhost)");
            $pages->set($domain, '<html>__probe__</html>', [$vhost => [$code]]);
            $record('errorpages', $version, 'set');
            $probe = array_values(array_filter($pages->list($domain), static fn(ErrorPage $p): bool => $p->subdomain() === $vhost && $p->errorCode() === $code))[0] ?? null;
            $record('errorpages', $version, 'list');
            if ($probe !== null) {
                $pages->get($domain, $probe->id());
                $record('errorpages', $version, 'get');
            }
            $pages->set($domain, '<html>__probe__ replaced</html>', [$vhost => [$code]]);
            $record('errorpages', $version, 'replace');
            if ($client instanceof V2\Client) {
                $client->errorPages()->grouped($domain);
                $record('errorpages', $version, 'grouped');
            }
            $pages->delete($domain, [$vhost => [$code]]);
            $record('errorpages', $version, 'delete');
        }, static function () use ($pages, $vhost, $domain, $state, $codesOf): array {
            $code = $state['code'] ?? null;
            if ($code === null) {
                return [];
            }
            if (in_array($code, $codesOf($pages->list($domain)), true)) {
                $pages->delete($domain, [$vhost => [$code]]);
            }

            return in_array($code, $codesOf($pages->list($domain)), true) ? ["error page $code of the vhost"] : [];
        });
    }
}

// ------------------------------------------------------------------ subdomain settings

if (in_array('settings', $areas, true)) {
    foreach (['v1' => $v1, 'v2' => $v2] as $version => $client) {
        $settings = $client->subdomainSettings();
        /** @var ArrayObject<string, array<string, mixed>> $state 'original': the own settings read before the write */
        $state = new ArrayObject();
        // the same settings, whatever order Myra sends them in
        $same = static function (array $a, array $b): bool {
            ksort($a);
            ksort($b);

            return $a === $b;
        };
        $run('settings', $version, static function () use ($settings, $vhost, $version, $record, $say, $state): void {
            $before = $settings->get($vhost);
            $record('settings', $version, 'get');
            $settings->effective($vhost);
            $record('settings', $version, 'get-flat');
            $state['original'] = $before->own();
            $say(sprintf('own settings: %d — posting them back unchanged', count($before->own())));
            $settings->update($vhost, $before->own());
            $record('settings', $version, 'update-unchanged');
        }, static function () use ($settings, $vhost, $version, $record, $say, $state, $same): array {
            $original = $state['original'] ?? null;
            if ($original === null) {
                return []; // nothing was written
            }
            $now = $settings->get($vhost)->own();
            if ($same($now, $original)) {
                $record('settings', $version, 'verify');

                return [];
            }
            $say('!! own settings changed — restoring the original');
            $settings->update($vhost, $original);
            $record('settings', $version, 'restore');

            $now = $settings->get($vhost)->own(); // read again after the restore

            return $same($now, $original) ? [] : ["$version settings of the vhost (could not restore)"];
        });
    }
}

// ------------------------------------------------------------------ cache settings

if (in_array('cachesettings', $areas, true)) {
    foreach (['v1' => $v1, 'v2' => $v2] as $version => $client) {
        $settings = $client->cacheSettings();
        $isProbe = static fn(CacheSetting $s): bool => str_starts_with($s->path(), '/__probe__/');
        $run('cachesettings', $version, static function () use ($settings, $version, $vhost, $record, $stale): void {
            $created = $settings->create(new CacheSetting($vhost, '/__probe__/', CacheSetting::TTL_NO_CACHE, CacheSetting::TYPE_EXACT, sort: 999, enabled: false));
            $record('cachesettings', $version, 'create');
            $settings->update($created->withTtl(CacheSetting::TTL_LIKE_ORIGIN));
            $record('cachesettings', $version, 'update');
            $stale(static fn() => $settings->update($created->withTtl(60)));
            $record('cachesettings', $version, 'update-stale');
            $settings->list($vhost);
            $record('cachesettings', $version, 'list');
        }, static function () use ($settings, $vhost, $isProbe): array {
            foreach (array_filter($settings->list($vhost), $isProbe) as $probe) {
                $settings->delete($probe);
            }

            return array_values(array_map(static fn(CacheSetting $s): string => 'cache setting ' . $s->id(), array_filter($settings->list($vhost), $isProbe)));
        });
    }
}

// ------------------------------------------------------------------ WAF rules (v2 only; no package API yet: raw)

if (in_array('waf', $areas, true)) {
    $raw = new Transport($token, 'https://apiv2.myracloud.com', $http);
    // one object of `data`, whether Myra sends a list or an object
    $one = static function (JsonObject $answer): JsonObject {
        $data = $answer->toArray()['data'] ?? null;

        return is_array($data) && array_is_list($data) ? $answer->first('data') : $answer->object('data');
    };
    $probeRules = static fn(): array => array_values(array_filter(
        Page::collect(static fn(int $page): Page => Page::fromJson($raw->send('GET', "domain/$domainId/waf-rules", $page === 1 ? [] : ['page' => $page]), 'data', static fn(JsonObject $rule): JsonObject => $rule)),
        static fn(JsonObject $rule): bool => ($rule->toArray()['name'] ?? null) === '__probe__',
    ));
    $run('waf', 'v2', static function () use ($raw, $one, $wafRules, $domainId, $vhost, $record, $say, &$guard): void {
        $rule = [
            'ruleType' => 'domain', 'name' => '__probe__', 'description' => '__probe__ (myra-web-api): disabled, only /__probe__/waf, logs only',
            'logIdentifier' => '__probe__', 'subDomainName' => $vhost, 'direction' => 'in', 'sort' => 9999,
            'enabled' => false, 'processNext' => true,
            'conditions' => [['name' => 'url', 'matchingType' => 'EXACT', 'value' => '/__probe__/waf']],
            'actions' => [['name' => 'Log', 'type' => 'log']],
        ];
        $created = $one($raw->send('POST', $wafRules, body: $rule));
        $record('waf', 'v2', 'create');
        $id = $created->int('id');
        $guard = $guard->allowing("PUT /$wafRules/$id", "DELETE /domain/waf-rules/$id");
        if (($created->toArray()['enabled'] ?? true) !== false) {
            $raw->send('DELETE', "domain/waf-rules/$id");
            $record('waf', 'v2', 'emergency-delete');
            throw new RuntimeException('Myra reported the probe WAF rule ENABLED — deleted at once.');
        }
        $raw->send('GET', "domain/$domainId/waf-rules");
        $record('waf', 'v2', 'list');
        $raw->send('GET', "domain/$domainId/waf-rules/list", ['subDomain' => $vhost]);
        $record('waf', 'v2', 'list-for-vhost');
        $raw->send('GET', "domain/waf-rules/$id");
        $record('waf', 'v2', 'get');
        $updated = $one($raw->send('PUT', "$wafRules/$id", body: ['description' => '__probe__ (myra-web-api): updated'] + $created->toArray()));
        $record('waf', 'v2', 'update');
        if (($updated->toArray()['enabled'] ?? true) !== false) {
            throw new RuntimeException('Myra reported the probe WAF rule ENABLED after the update.');
        }
        try {
            $raw->send('PUT', "$wafRules/$id", body: ['description' => '__probe__ (myra-web-api): stale'] + $created->toArray());
            $say('!! an update with the stale modified was ACCEPTED (no optimistic lock)');
        } catch (RequestRejectedException $e) {
            $say('stale modified rejected: ' . $e->getMessage());
        }
        $record('waf', 'v2', 'update-stale');
        $raw->send('DELETE', "domain/waf-rules/$id");
        $record('waf', 'v2', 'delete');
    }, static function () use ($raw, $probeRules, &$guard): array {
        foreach ($probeRules() as $rule) {
            $id = $rule->int('id');
            $guard = $guard->allowing("DELETE /domain/waf-rules/$id");
            $raw->send('DELETE', "domain/waf-rules/$id");
        }

        return array_map(static fn(JsonObject $rule): string => 'WAF rule ' . $rule->int('id'), $probeRules());
    });
}

echo $leftovers === [] ? "No leftovers.\n" : 'LEFTOVERS — delete by hand: ' . implode(', ', $leftovers) . "\n";
exit($failures === 0 && $leftovers === [] ? 0 : 1);
