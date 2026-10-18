<?php

declare(strict_types=1);

/**
 * Captures READ answers of the real Myra API for fixtures — anonymised in memory.
 *
 *     php tests/Smoke/capture.php            # credentials: .smoke.env (see run.php)
 *     php tests/Smoke/capture.php --only='v2:/ip-ranges?page=2,v1:/networks/2'   # just these GETs
 *
 * GET requests only (plus nothing that could change state), v1 with HMAC, v2 with the
 * token, for every domain of the account; ids and vhost names are discovered from earlier
 * answers. Raw answers never touch the disk: each one is anonymised ({@see Anonymizer},
 * seeded from the existing fixtures via .cache/smoke) and leak-checked; only clean results
 * are written to .cache/captures/ for review before they are promoted to the docs/captures of
 * cpsit/myra-api-emulator (and from there into its fixtures).
 * The output shows shapes (fields, counts, leak checks), never values.
 */

namespace Myracloud\WebApi\Tests\Smoke;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Psr7\HttpFactory;
use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Auth\HmacSignature;
use Myracloud\WebApi\Http\Transport;
use RuntimeException;
use Throwable;

require __DIR__ . '/../../vendor/autoload.php';

$root = dirname(__DIR__, 2);
$ini = is_file($root . '/.smoke.env') ? parse_ini_file($root . '/.smoke.env') : false;
$env = static function (string $name) use ($ini): string {
    $value = is_array($ini) ? ($ini[$name] ?? '') : '';

    return is_string($value) ? trim($value) : '';
};
if ($env('MYRA_KEY') === '' || $env('MYRA_SECRET') === '' || $env('MYRA_TOKEN') === '') {
    fwrite(STDERR, "capture.php needs MYRA_KEY, MYRA_SECRET and MYRA_TOKEN in .smoke.env\n");
    exit(2);
}

/**
 * @return array<mixed>
 */
function json(string $file): array
{
    $content = file_get_contents($file);
    $data = json_decode($content === false ? '' : $content, true);
    if (!is_array($data)) {
        throw new RuntimeException('Not JSON: ' . $file);
    }

    return $data;
}

/**
 * @param array<mixed> $capture
 */
function responseBody(array $capture): mixed
{
    $response = $capture['response'] ?? null;
    $body = is_array($response) ? ($response['body'] ?? null) : null;

    return is_string($body) ? json_decode($body, true) : $body;
}

// ------------------------------------------------------------------ anonymiser, seeded

$anonymizer = new Anonymizer(Vocabulary::fromDocs($root));
$seeds = [
    ['01-GET-en-rapi-domains-1-', 'v1/domains/list'],
    ['04-GET-domains-', 'v2/domains/list'],
];
$v1Dns = glob($root . '/.cache/smoke/*-GET-en-rapi-dnsRecords-*-1-.json');
foreach ($v1Dns === false ? [] : $v1Dns as $file) {
    $seeds[] = [basename($file, '.json'), 'v1/dns-records/list'];
    break;
}
$v2Dns = glob($root . '/.cache/smoke/*-GET-domain-*-dns-records-.json');
foreach ($v2Dns === false ? [] : $v2Dns as $file) {
    $seeds[] = [basename($file, '.json'), 'v2/dns-records/list'];
    break;
}
foreach ($seeds as [$smoke, $fixture]) {
    $file = $root . '/.cache/smoke/' . $smoke . '.json';
    if (!is_file($file)) {
        fwrite(STDERR, "Missing $file — run tests/Smoke/run.php first (it records the seed answers).\n");
        exit(2);
    }
    $real = responseBody(json($file));
    $fake = responseBody(json(\CPSIT\MyraApiEmulator\Paths::fixtures() . '/' . $fixture . '.json'));
    if (str_ends_with($fixture, 'domains/list') && is_array($real) && is_array($fake)) {
        $realItems = $real['list'] ?? $real['data'] ?? [];
        $fakeItems = $fake['list'] ?? $fake['data'] ?? [];
        foreach (is_array($realItems) ? $realItems : [] as $i => $domain) {
            $fakeDomain = is_array($fakeItems) ? ($fakeItems[$i] ?? null) : null;
            if (is_array($domain) && is_array($fakeDomain) && is_string($domain['name'] ?? null) && is_string($fakeDomain['name'] ?? null)) {
                $anonymizer->zone($domain['name'], $fakeDomain['name']);
            }
        }
    }
    $anonymizer->seed($real, $fake);
}

// ------------------------------------------------------------------ sending

$factory = new HttpFactory();
$guzzle = new Guzzle(Transport::defaultOptions());
$clients = [
    'v1' => ['https://api.myracloud.com/en/rapi', new HmacSignature($env('MYRA_KEY'), $env('MYRA_SECRET')), 'HMAC'],
    'v2' => ['https://apiv2.myracloud.com', new BearerToken($env('MYRA_TOKEN')), 'Bearer'],
];
$out = $root . '/.cache/captures';
is_dir($out) || mkdir($out, 0o700, true);
$count = 0;
$written = 0;

/**
 * Sends one GET, writes the anonymised capture, returns the decoded real body (memory only).
 */
$capture = static function (string $area, string $version, string $path) use ($factory, $guzzle, $clients, $anonymizer, $out, &$count, &$written): mixed {
    [$base, $auth, $authName] = $clients[$version];
    $request = $auth->authenticate($factory->createRequest('GET', $base . $path)->withHeader('Accept', 'application/json')->withHeader('Content-Type', 'application/json'));
    usleep(300_000);
    try {
        $response = $guzzle->sendRequest($request);
    } catch (Throwable $e) {
        printf("%3d %s %-58s NETWORK %s\n", ++$count, $version, $area, $e::class);

        return null;
    }
    $status = $response->getStatusCode();
    $type = $response->getHeaderLine('Content-Type');
    $raw = (string) $response->getBody();
    $decoded = json_decode($raw, true);
    $isJson = is_array($decoded);
    $body = $isJson ? $anonymizer->value($decoded) : $anonymizer->text($raw);
    $requestPath = $anonymizer->path(($version === 'v1' ? '/en/rapi' : '') . $path);
    $result = (string) json_encode([
        'area' => $area,
        'apiVersion' => $version,
        'auth' => $authName,
        'request' => ['method' => 'GET', 'path' => $requestPath, 'body' => null],
        'response' => ['status' => $status, 'contentType' => $type === '' ? null : $type, 'body' => $body],
        'finding' => '',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $leaks = $anonymizer->leaks($result, json_decode($result, true));
    $shape = $isJson ? shape($decoded) : sprintf('%s, %d lines', $type, substr_count($raw, "\n"));
    $count++;
    if ($leaks === []) {
        $file = sprintf('%s/%s-%03d-%s.json', $out, $version, $count, trim((string) preg_replace('/[^a-z0-9]+/i', '-', $requestPath), '-'));
        file_put_contents($file, $result . "\n");
        $written++;
    }
    printf("%3d %s %-58s %d  %s%s\n", $count, $version, $requestPath, $status, $shape, $leaks === [] ? '' : '   NOT WRITTEN: ' . implode('; ', $leaks));

    return $isJson ? $decoded : null;
};

/**
 * Fields and counts, no values.
 */
function shape(mixed $body): string
{
    if (!is_array($body)) {
        return get_debug_type($body);
    }
    $content = null;
    foreach (['list', 'data', 'targetObject'] as $key) {
        if (array_key_exists($key, $body)) {
            $content = $key;
        }
    }
    $parts = ['error=' . var_export($body['error'] ?? null, true)];
    if ($content !== null) {
        $value = $body[$content];
        $parts[] = is_array($value) && array_is_list($value) ? sprintf('%s[%d]', $content, count($value)) : $content . '{' . (is_array($value) ? count($value) : 0) . ' fields}';
    } elseif (!array_is_list($body)) {
        $parts[] = 'keys: ' . implode(',', array_slice(array_keys($body), 0, 8));
    } else {
        $parts[] = sprintf('list[%d]', count($body));
    }
    $violations = $body['violationList'] ?? [];
    if (is_array($violations) && $violations !== []) {
        $parts[] = count($violations) . ' violations';
    }

    return implode(' ', $parts);
}

/**
 * @return list<array<mixed>>
 */
function items(mixed $body): array
{
    if (!is_array($body)) {
        return [];
    }
    $items = $body['data'] ?? $body['list'] ?? [];

    return is_array($items) && array_is_list($items) ? array_values(array_filter($items, 'is_array')) : (is_array($items) ? [$items] : []);
}

function firstId(mixed $body): ?int
{
    $id = items($body)[0]['id'] ?? null;

    return is_int($id) ? $id : null;
}

// ------------------------------------------------------------------ targets

$only = getopt('', ['only:'])['only'] ?? null;
if (is_string($only)) {
    foreach (explode(',', $only) as $target) {
        [$version, $path] = explode(':', $target, 2) + [1 => ''];
        if (!isset($clients[$version]) || !str_starts_with($path, '/')) {
            fwrite(STDERR, "--only takes version:/path pairs, e.g. v2:/ip-ranges — got \"$target\"\n");
            exit(2);
        }
        $capture('probe', $version, $path);
    }
    printf("\n%d requests, %d written to .cache/captures/ (anonymised), %d withheld.\n", $count, $written, $count - $written);
    exit(0);
}

$account = [
    ['v2', '/ip-ranges', 'IP ranges'], ['v1', '/networks', 'IP ranges'],
    ['v2', '/ssl-configurations', 'SSL'], ['v2', '/ssl/provider-names', 'SSL providers'],
    ['v2', '/waiting-rooms/feature', 'Waiting room'],
    ['v2', '/waf/conditions', 'WAF'], ['v2', '/waf/conditions/matching-types', 'WAF'], ['v2', '/waf/actions', 'WAF'],
    ['v2', '/waf/actions/values', 'WAF'], ['v2', '/waf/countries', 'WAF'],
    ['v2', '/subdomains', 'Subdomains'],
    ['v2', '/organization/contact-types', 'Organisation'], ['v2', '/organization/contacts', 'Organisation'], ['v2', '/organization/notes', 'Organisation'],
];
foreach ($account as [$version, $path, $area]) {
    $capture($area, $version, $path);
}

foreach (['/ssl/providers' => '/ssl/providers/%d', '/ssl/requests' => '/ssl/requests/%d', '/waiting-rooms' => '/waiting-room/%d', '/replication-dns/zones' => '/replication-dns/zones/%d'] as $list => $detail) {
    $id = firstId($capture('list+detail', 'v2', $list));
    if ($id !== null) {
        $capture('detail', 'v2', sprintf($detail, $id));
        if ($list === '/ssl/providers') {
            $capture('SSL providers', 'v2', sprintf('/ssl/providers/%d/certificates', $id));
        }
    }
}

$tags = $capture('Tags', 'v2', '/tags');
$tagId = firstId($tags);
if ($tagId !== null) {
    foreach (['/tags/%d', '/tags/%d/information', '/tag/%d/settings', '/tag/%d/cache-settings', '/tag/%d/waf-rules'] as $path) {
        $body = $capture('Tags', 'v2', sprintf($path, $tagId));
        if ($path === '/tag/%d/waf-rules' && ($rule = firstId($body)) !== null) {
            $capture('Tags', 'v2', sprintf('/tag/%d/waf-rules/%d', $tagId, $rule));
        }
    }
}

$me = $capture('Users', 'v2', '/user/me');
$userId = is_array($me) && is_array($me['data'] ?? null) && is_int($me['data']['id'] ?? null) ? $me['data']['id'] : null;
foreach (['/users', '/user/permissions', '/user/tokens', '/user/groups'] as $path) {
    $body = $capture('Users', 'v2', $path);
    if ($path === '/user/groups' && ($group = firstId($body)) !== null) {
        foreach (['/user/groups/%d', '/user/group/%d/users', '/user/group/%d/permissions'] as $detail) {
            $capture('Users', 'v2', sprintf($detail, $group));
        }
    }
}
if ($userId !== null) {
    foreach (['/users/%d', '/user/%d/groups', '/user/%d/api-keys', '/user/%d/activities', '/user/%d/permissions'] as $path) {
        $capture('Users', 'v2', sprintf($path, $userId));
    }
}

$domains = items($capture('Domains', 'v2', '/domains'));
foreach ($domains as $domain) {
    $id = $domain['id'] ?? null;
    $name = $domain['name'] ?? null;
    if (!is_int($id) || !is_string($name)) {
        continue;
    }
    // v1, by name
    foreach (["/dnsRecords/$name/1?loadbalancer=true&recordTypes=A,AAAA,CNAME", "/subdomainSetting/$name?flat", "/subdomainSetting/$name",
        "/cacheSettings/$name/1", "/redirects/$name/1", "/ipfilter/$name/1", "/maintenance/$name/1", "/certificates/$name/1",
        "/ratelimit/dns/1?subDomainName=$name", "/bind/$name", "/bindRaw/$name"] as $path) {
        $capture('v1 by domain', 'v1', $path);
    }
    $errorPages = $capture('Error pages', 'v1', "/errorpages/$name/1");
    if (($page = firstId($errorPages)) !== null) {
        $capture('Error pages', 'v1', "/errorpage/$page");
    }
    // v1, by subdomain: does v1 take a subdomain where the manual says {domain}?
    foreach (["/redirects/www.$name/1", "/ipfilter/www.$name/1", "/cacheSettings/www.$name/1", "/maintenance/www.$name/1", "/subdomainSetting/www.$name"] as $path) {
        $capture('v1 by subdomain', 'v1', $path);
    }
    // v2, by id
    foreach (["/domains/$id", "/domains/$id/bind/Myra", "/domains/$id/bind/Origin", "/domains/$id/bind-raw/Myra", "/domains/$id/bind-raw/Origin",
        "/domains/$id/verification", "/domains/$id/foreign-descendants", "/domain/$id/load-balancing", "/domain/$id/subdomains",
        "/domain/$id/errorpages-grouped", "/domain/$id/maintenance-templates", "/domain/$id/waf-rules/list"] as $path) {
        $capture('v2 by domain', 'v2', $path);
    }
    $records = items($capture('DNS records', 'v2', "/domain/$id/dns-records"));
    $record = $records[0] ?? [];
    if (is_int($record['id'] ?? null)) {
        $capture('DNS records', 'v2', "/domain/$id/dns-records/{$record['id']}");
    }
    if (is_int($record['vhostId'] ?? null)) {
        $capture('DNS records', 'v2', "/domain/$id/vhost/{$record['vhostId']}/dns-records");
    }
    foreach (['errorpages' => "/domain/$id/errorpages/%d", 'maintenances' => "/domain/$id/maintenances/%d", 'custom-configurations' => "/domain/$id/custom-configurations/%d",
        'ssl/certificates' => "/domain/$id/ssl/certificates/%d", 'waf-rules' => '/domain/waf-rules/%d'] as $list => $detail) {
        $detailId = firstId($capture('v2 by domain', 'v2', "/domain/$id/$list"));
        if ($detailId !== null) {
            $capture('v2 by domain', 'v2', sprintf($detail, $detailId));
        }
    }
    // v2, by vhost: the zone apex
    foreach (["/domain/$id/$name/cache-settings", "/domain/$id/$name/settings", "/domain/$id/$name/maintenances",
        "/domain/$id/ip-filters/$name/export", "/tags/$name", "/tags/$name/information"] as $path) {
        $capture('v2 by vhost', 'v2', $path);
    }
    foreach (['ip-filters', 'redirects'] as $area) {
        $entry = firstId($capture('v2 by vhost', 'v2', "/domain/$id/$area/$name"));
        if ($entry !== null) {
            $capture('v2 by vhost', 'v2', "/domain/$id/$area/$name/$entry");
        }
    }
}

printf("\n%d requests, %d written to .cache/captures/ (anonymised), %d withheld.\n", $count, $written, $count - $written);
