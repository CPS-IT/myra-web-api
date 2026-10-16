<?php

declare(strict_types=1);

/**
 * Statistics READ probe against the real API — run by hand:
 *
 *     php tests/Smoke/statistics.php            # credentials and MYRA_DOMAIN: .smoke.env
 *     STATS_ONLY=6,7 STATS_DOMAIN=<domain> php tests/Smoke/statistics.php   # some probes, another domain
 *
 * Statistics are queried with POST, so capture.php (GET only) cannot take them. This script
 * sends a fixed list of statistic queries for MYRA_DOMAIN over two hours of yesterday — the
 * guard of the {@see RecordingClient} allows exactly these POST paths and nothing else.
 *
 * Stricter than the other captures, in memory before anything is written: every metric
 * number except 0 is replaced by a fake of the same type (a site's traffic is nobody's business),
 * URLs become /path-N, lists are cut to 3 entries; then the usual anonymiser and leak check.
 * Timestamps (bucket keys, x, time) and status codes stay: they are structure.
 */

namespace Myracloud\WebApi\Tests\Smoke;

use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Psr7\HttpFactory;
use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Auth\HmacSignature;
use Psr\Http\Message\RequestInterface;
use Throwable;

require __DIR__ . '/../../vendor/autoload.php';

$root = dirname(__DIR__, 2);
$env = SmokeSession::env($root);
// STATS_DOMAIN: another domain of the account, e.g. a quiet one for empty answers
$override = getenv('STATS_DOMAIN');
$domain = strtolower(rtrim(is_string($override) && $override !== '' ? $override : $env('MYRA_DOMAIN'), '.'));
if ($domain === '' || $env('MYRA_KEY') === '' || $env('MYRA_SECRET') === '' || $env('MYRA_TOKEN') === '') {
    fwrite(STDERR, "statistics.php needs MYRA_KEY, MYRA_SECRET, MYRA_TOKEN and MYRA_DOMAIN in .smoke.env\n");
    exit(2);
}

/** POST paths that only read: the statistic queries of both versions. */
const READ_ONLY_POSTS = '#^/(en/rapi/statistic/query|statistic/query|statistics/(total-domain-requests|http-status-codes|top-urls|cached-urls|world-map|histogram)/\d+/\d+(/[a-z_-]+/[a-z]+|/[a-z-]+/[a-z0-9]+/[01]/[01])?)$#';

$http = new RecordingClient(static fn(RequestInterface $request): bool => $request->getMethod() === 'POST' && preg_match(READ_ONLY_POSTS, $request->getUri()->getPath()) === 1);
$factory = new HttpFactory();
$anonymizer = SmokeSession::anonymizer($root);
$out = $root . '/.cache/captures';
is_dir($out) || mkdir($out, 0o700, true);

$berlin = new DateTimeZone('Europe/Berlin');
$start = new DateTimeImmutable('yesterday 10:00', $berlin);
$end = $start->modify('+2 hours -1 second');
$query = [
    'query' => [
        'aggregationInterval' => 'hour',
        'dataSources' => [
            'requests' => ['source' => 'requests', 'type' => 'stats'],
            'requests_histogram' => ['source' => 'requests', 'type' => 'histogram'],
            'response_codes' => ['source' => 'response_codes', 'type' => 'stats'],
        ],
        'startDate' => $start->format(DATE_ATOM),
        'endDate' => $end->format(DATE_ATOM),
        'fqdn' => ['ALL:' . $domain],
        'type' => 'fqdn',
    ],
];
$selection = ['domains' => [$domain], 'subDomains' => []];
$window = $start->getTimestamp() . '/' . $end->getTimestamp();
$probes = [
    ['v1', 'POST', 'https://api.myracloud.com/en/rapi/statistic/query', $query],
    ['v2', 'POST', 'https://apiv2.myracloud.com/statistic/query', $query],
    ['v2', 'GET', "https://apiv2.myracloud.com/statistics/total-domain-requests/$window", null],
    ['v2', 'GET', "https://apiv2.myracloud.com/statistics/total-domain-traffic/$window", null],
    ['v2', 'POST', "https://apiv2.myracloud.com/statistics/total-domain-requests/$window", $selection],
    ['v2', 'POST', "https://apiv2.myracloud.com/statistics/http-status-codes/$window", $selection],
    ['v2', 'POST', "https://apiv2.myracloud.com/statistics/top-urls/$window", $selection],
    ['v2', 'POST', "https://apiv2.myracloud.com/statistics/cached-urls/$window", $selection],
    ['v2', 'POST', "https://apiv2.myracloud.com/statistics/world-map/$window/total_requests/desc", $selection],
    ['v2', 'POST', "https://apiv2.myracloud.com/statistics/histogram/$window/total-requests/hourly/0/1", $selection],
    // failures: how do they come?
    ['v2', 'POST', "https://apiv2.myracloud.com/statistics/top-urls/$window", ['domains' => ['does-not-exist.invalid'], 'subDomains' => []]],
    ['v1', 'POST', 'https://api.myracloud.com/en/rapi/statistic/query', ['query' => ['dataSources' => ['x' => ['source' => 'no_such_source', 'type' => 'stats']]] + $query['query']]],
    ['v2', 'POST', "https://apiv2.myracloud.com/statistics/histogram/$window/no-such-chart/hourly/0/1", $selection],
];

final class ScrubCounter
{
    public static int $numbers = 0;
    public static int $urls = 0;
}

/**
 * Metric numbers → fakes of the same type, URLs → /path-N, lists → 3 entries.
 */
function scrub(mixed $value, string $key = ''): mixed
{
    if (is_array($value)) {
        if (array_is_list($value) && count($value) > 3) {
            $value = array_slice($value, 0, 3);
        }
        $result = [];
        foreach ($value as $k => $item) {
            $result[$k] = scrub($item, is_string($k) ? $k : $key);
        }

        return $result;
    }
    $structural = in_array($key, ['x', 'time', 'status_code', 'statusCode', 'code', 'id', 'page', 'pageSize', 'sum_flag'], true) || $key === 'country_code';
    if ((is_int($value) || is_float($value)) && !$structural && $value !== 0 && $value !== 0.0) { // 0 = no data, kept
        $number = ++ScrubCounter::$numbers;

        return is_int($value) ? 1000 + $number : $number + 0.5;
    }
    if (is_string($value) && in_array($key, ['url', 'path', 'uri'], true)) {
        return '/path-' . ++ScrubCounter::$urls;
    }

    return $value;
}

/**
 * Keys and types only, no values (to look at an answer that was withheld).
 */
function keysAndTypes(mixed $value): mixed
{
    if (is_array($value)) {
        $result = [];
        foreach (array_slice($value, 0, 8, true) as $k => $item) {
            $result[is_string($k) ? $k : '#'] = keysAndTypes($item);
        }

        return $result + (count($value) > 8 ? ['…' => count($value)] : []);
    }

    return get_debug_type($value) . (is_string($value) ? '(' . strlen($value) . ')' : '');
}

$written = 0;
$only = getenv('STATS_ONLY');
foreach ($probes as $index => [$version, $method, $url, $body]) {
    if ($only !== false && !in_array((string) ($index + 1), explode(',', $only), true)) {
        continue;
    }
    $count = $index + 1;
    $auth = $version === 'v1' ? new HmacSignature($env('MYRA_KEY'), $env('MYRA_SECRET')) : new BearerToken($env('MYRA_TOKEN'));
    $request = $factory->createRequest($method, $url)->withHeader('Accept', 'application/json')->withHeader('Content-Type', 'application/json');
    if ($body !== null) {
        $request = $request->withBody($factory->createStream((string) json_encode($body, JSON_UNESCAPED_SLASHES)));
    }
    try {
        $response = $http->sendRequest($auth->authenticate($request));
    } catch (Throwable $e) {
        printf("%3d %s %s %s  %s\n", $count, $version, $method, $anonymizer->path($request->getUri()->getPath()), $e::class);
        continue;
    }
    $http->take();
    $raw = (string) $response->getBody();
    $decoded = json_decode($raw, true);
    $path = $anonymizer->path($request->getUri()->getPath());
    $result = (string) json_encode([
        'area' => 'statistics',
        'apiVersion' => $version,
        'auth' => $version === 'v1' ? 'HMAC' : 'Bearer',
        'request' => ['method' => $method, 'path' => $path, 'body' => $body === null ? null : scrub($anonymizer->value($body))],
        'response' => [
            'status' => $response->getStatusCode(),
            'contentType' => $response->getHeaderLine('Content-Type') === '' ? null : $response->getHeaderLine('Content-Type'),
            'body' => is_array($decoded) ? scrub($anonymizer->value($decoded)) : $anonymizer->text($raw),
        ],
        'finding' => 'two hours of yesterday; metric numbers, URLs replaced, lists cut to 3',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $leaks = $anonymizer->leaks($result, json_decode($result, true));
    if (getenv('STATS_SHAPE') !== false && is_array($decoded)) {
        echo json_encode(keysAndTypes(scrub($anonymizer->value($decoded))), JSON_PRETTY_PRINT), "\n";
    }
    if ($leaks === []) {
        file_put_contents(sprintf('%s/%s-stat-%02d-%s.json', $out, $version, $count, trim((string) preg_replace('/[^a-z0-9]+/i', '-', explode('/1', $path)[0]), '-')), $result . "\n");
        $written++;
    }
    printf("%3d %s %-4s %-62s %d %s%s\n", $count, $version, $method, $path, $response->getStatusCode(), is_array($decoded) ? implode(',', array_keys($decoded)) : strlen($raw) . ' bytes', $leaks === [] ? '' : '   NOT WRITTEN: ' . implode('; ', $leaks));
}
printf("\n%d written to .cache/captures/ (scrubbed, anonymised); one line above per request.\n", $written);
