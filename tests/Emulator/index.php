<?php

declare(strict_types=1);

/**
 * Myra API emulator (v1 and v2) for integration tests and local development.
 *
 *     php -S 127.0.0.1:8080 tests/Emulator/index.php
 *
 * Answers come from tests/Fixtures (anonymised captures, see docs/captures): the success
 * bodies, and the failures as Myra sends them — v1 `text/plain` 403 for a wrong secret,
 * empty 403 for an unknown v1 domain or a wrong v2 token, 401 with an error string without
 * a token, violations for rejected requests.
 *
 * ASSUMED, not captured (each answered like its captured neighbour):
 * - v1 without Authorization header or with a wrong token → the v1 wrong-secret answer;
 * - v2 with wrong HMAC credentials → the v2 wrong-token answer;
 * - v1 unknown domain on PUT cacheClear → the GET dnsRecords answer;
 * - v1 `recursive` not a boolean → the v2 wrong-type answer;
 * - paging (MYRA_EMULATOR_PAGE_SIZE cuts the captured lists), the echo of a cache clear
 *   (built like the captured ones) and the 404 "Not emulated" envelope;
 * - writes of per-vhost areas (redirects, IP filter, cache settings) are echoed in the captured shapes but
 *   stateless: nothing is stored, a stale `modified` is not detected; v2 lists of
 *   www.example.com are the v1 capture's objects in a v2 envelope; the IP filter export is
 *   built from the captured list.
 *
 * Authentication is checked independently of the package's own code: HMAC recomputed
 * after the v2 spec (docs/v2/openapi.json, info.description §2.1.2), or a Bearer token.
 * Both work with both versions, like at Myra.
 *
 * Environment (all optional):
 *   MYRA_EMULATOR_KEY / MYRA_EMULATOR_SECRET   HMAC credentials (test-key / test-secret)
 *   MYRA_EMULATOR_TOKEN                        Bearer token (test-token)
 *   MYRA_EMULATOR_PAGE_SIZE                    items per page (50, like Myra)
 *   MYRA_EMULATOR_LOG                          JSON-lines call log (sys_get_temp_dir()/myra-emulator.jsonl)
 *
 * Control (not logged): GET /_emulator/calls, POST /_emulator/reset.
 */

namespace Myracloud\WebApi\Tests\Emulator;

use RuntimeException;

const FIXTURES = __DIR__ . '/../Fixtures/';

/**
 * One emulated answer: status, content type (null = none), body (string = raw, array = JSON).
 */
final readonly class Answer
{
    /**
     * @param array<mixed>|string $body
     */
    public function __construct(
        public int $status,
        public ?string $contentType,
        public array|string $body,
    ) {}

    public static function fixture(string $name): self
    {
        $json = file_get_contents(FIXTURES . $name . '.json');
        $capture = json_decode($json === false ? '' : $json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($capture) || !is_array($capture['response'] ?? null)) {
            throw new RuntimeException('Broken fixture ' . $name);
        }
        $response = $capture['response'];
        $status = $response['status'] ?? null;
        $contentType = $response['contentType'] ?? null;
        $body = $response['body'] ?? null;

        return new self(
            is_int($status) ? $status : 500,
            is_string($contentType) ? $contentType : null,
            is_array($body) || is_string($body) ? $body : '',
        );
    }

    /**
     * The fixture's list, cut to one page.
     */
    public static function page(string $name, string $key, int $page): self
    {
        $answer = self::fixture($name);
        $body = is_array($answer->body) ? $answer->body : [];
        $items = is_array($body[$key] ?? null) ? array_values($body[$key]) : [];
        $size = max(1, (int) Emulator::env('MYRA_EMULATOR_PAGE_SIZE', '50'));
        $body[$key] = array_slice($items, ($page - 1) * $size, $size);
        $body['page'] = $page;
        $body['count'] = count($items);
        $body['pageSize'] = $size;

        return new self(200, 'application/json', $body);
    }

    /**
     * @param array<mixed> $content
     */
    public static function success(string $key, array $content): self
    {
        return new self(200, 'application/json', ['error' => false, 'violationList' => [], 'warningList' => [], $key => $content]);
    }

    public function rename(string $from, string $to): self
    {
        $body = is_array($this->body) ? $this->body : [];
        $body[$to] = $body[$from] ?? [];
        unset($body[$from]);

        return new self($this->status, $this->contentType, $body);
    }

    public function send(): void
    {
        http_response_code($this->status);
        if ($this->contentType !== null) {
            header('Content-Type: ' . $this->contentType);
        }
        echo is_array($this->body) ? json_encode($this->body, JSON_UNESCAPED_SLASHES) : $this->body;
    }
}

final class Emulator
{
    /** The emulated account: domain name → id, as in the fixtures. */
    private const DOMAINS = ['example.com' => 1000, 'example.org' => 1140];
    /**
     * Areas configured per vhost: fixture directory, object type, captured lists by vhost
     * (vhosts not listed have none). Both v1 path names map here, and so do the v2 ones.
     */
    private const AREAS = [
        'redirects' => ['dir' => 'redirects', 'objectType' => 'DnsRedirectVO', 'lists' => ['example.com' => 'list-apex', 'www.example.com' => 'list-www'], 'get' => 90392],
        'ipfilter' => ['dir' => 'ipfilter', 'objectType' => 'IpFilterVO', 'lists' => ['example.com' => 'list-apex', 'www.example.com' => 'list-empty'], 'get' => 90393],
        'cachesettings' => ['dir' => 'cachesettings', 'objectType' => 'CacheSettingVO', 'lists' => ['example.com' => 'list-apex', 'www.example.com' => 'list-empty'], 'get' => 0],
    ];
    private const V1_AREAS = ['redirects' => 'redirects', 'ipfilter' => 'ipfilter', 'cacheSettings' => 'cachesettings'];
    private const V2_AREAS = ['redirects' => 'redirects', 'ip-filters' => 'ipfilter', 'cache-settings' => 'cachesettings'];
    /** v1 answers for a subdomain that is no vhost, where captured (otherwise an empty list). */
    private const V1_UNKNOWN = ['cachesettings' => 'v1/cachesettings/unknown-subdomain'];

    /**
     * @param array<string, string> $headers lower-cased names
     */
    public function __construct(
        private readonly string $method,
        private readonly string $uri,
        private readonly string $body,
        private readonly array $headers,
    ) {}

    public function handle(): Answer
    {
        $path = (string) parse_url($this->uri, PHP_URL_PATH);
        $v1 = preg_match('#^/([a-z]{2})/rapi(/.*)$#', $path, $m) === 1;
        $route = $v1 ? $m[2] : $path;

        $denied = $this->authenticate($v1);
        if ($denied !== null) {
            return $denied;
        }

        return $v1 ? $this->v1($route) : $this->v2($route);
    }

    /**
     * Null when authenticated, otherwise Myra's answer for the version.
     */
    public function authenticate(bool $v1): ?Answer
    {
        $authorization = $this->headers['authorization'] ?? '';
        if ($authorization === '') {
            return $v1 ? Answer::fixture('v1/errors/wrong-credentials') : Answer::fixture('v2/errors/no-token');
        }
        if ($this->authenticated($authorization)) {
            return null;
        }

        return $v1 ? Answer::fixture('v1/errors/wrong-credentials') : Answer::fixture('v2/errors/wrong-credentials');
    }

    public function authenticated(string $authorization): bool
    {
        if (str_starts_with($authorization, 'Bearer ')) {
            return hash_equals(self::env('MYRA_EMULATOR_TOKEN', 'test-token'), substr($authorization, 7));
        }
        if (preg_match('/^MYRA ([^:]+):(.+)$/', $authorization, $m) !== 1 || $m[1] !== self::env('MYRA_EMULATOR_KEY', 'test-key')) {
            return false;
        }

        // Independent of the package: the spec's algorithm, written out again.
        $date = $this->headers['date'] ?? '';
        // the request's own content type, as Myra does (captured: a CSV import signed over text/csv)
        $signingString = implode('#', [md5($this->body), strtoupper($this->method), $this->uri, $this->headers['content-type'] ?? 'application/json', $date]);
        $dateKey = hash_hmac('sha256', $date, 'MYRA' . self::env('MYRA_EMULATOR_SECRET', 'test-secret'));
        $signingKey = hash_hmac('sha256', 'myra-api-request', $dateKey);

        return hash_equals(base64_encode(hash_hmac('sha512', $signingString, $signingKey, true)), $m[2]);
    }

    private function v1(string $route): Answer
    {
        return match (true) {
            $this->method === 'GET' && preg_match('#^/domains/(\d+)$#', $route, $m) === 1
                => Answer::page('v1/domains/list', 'list', (int) $m[1]),
            $this->method === 'GET' && preg_match('#^/dnsRecords/([^/]+)/(\d+)$#', $route, $m) === 1
                => rawurldecode($m[1]) === 'example.com'
                    ? Answer::page('v1/dns-records/list', 'list', (int) $m[2])
                    : Answer::fixture('v1/errors/unknown-domain'),
            preg_match('#^/(redirects|ipfilter|cacheSettings)/([^/]+)(/(\d+))?$#', $route, $m) === 1
                => $this->v1Vhost(self::V1_AREAS[$m[1]], rawurldecode($m[2]), isset($m[4]) ? (int) $m[4] : null),
            $this->method === 'PUT' && preg_match('#^/cacheClear/([^/]+)$#', $route, $m) === 1
                => isset(self::DOMAINS[rawurldecode($m[1])])
                    ? $this->cacheClear('v1', rawurldecode($m[1]), 'targetObject')
                    : Answer::fixture('v1/errors/unknown-domain'),
            default => self::notEmulated($this->method, $route),
        };
    }

    private function v2(string $route): Answer
    {
        $page = $this->query()['page'] ?? '1';
        $page = max(1, is_string($page) ? (int) $page : 1);

        return match (true) {
            $this->method === 'GET' && $route === '/domains'
                => Answer::page('v2/domains/list', 'data', $page),
            preg_match('#^/domain/(\d+)/(redirects|ip-filters)/([^/]+)(/(\d+|export|import))?$#', $route, $m) === 1
                => $this->v2Vhost(self::V2_AREAS[$m[2]], (int) $m[1], rawurldecode($m[3]), $m[5] ?? null, $page),
            // areas with the vhost before the area name
            preg_match('#^/domain/(\d+)/([^/]+)/(cache-settings)(/(\d+))?$#', $route, $m) === 1
                => $this->v2Vhost(self::V2_AREAS[$m[3]], (int) $m[1], rawurldecode($m[2]), $m[5] ?? null, $page),
            preg_match('#^/domain/([^/]+)/(dns-records|cache-clear)$#', $route, $m) === 1
                => $this->v2Domain($m[1], $m[2], $page),
            default => self::notEmulated($this->method, $route),
        };
    }

    private function v2Domain(string $id, string $area, int $page): Answer
    {
        if (preg_match('/^\d+$/', $id) !== 1) {
            return Answer::fixture('v2/errors/domain-name-instead-of-id');
        }
        $name = array_search((int) $id, self::DOMAINS, true);
        if ($name === false) {
            return Answer::fixture('v2/errors/unknown-domain-id');
        }

        return match (true) {
            $this->method === 'GET' && $area === 'dns-records' => $name === 'example.com'
                ? Answer::page('v2/dns-records/list', 'data', $page)
                : Answer::page('v2/domains/page-2', 'data', 1),
            $this->method === 'PUT' && $area === 'cache-clear' => $this->cacheClear('v2', $name, 'data'),
            default => self::notEmulated($this->method, '/domain/' . $id . '/' . $area),
        };
    }

    private function v1Vhost(string $area, string $vhost, ?int $page): Answer
    {
        $config = self::AREAS[$area];
        $list = $config['lists'][$vhost] ?? null;
        if ($list === null && isset(self::V1_UNKNOWN[$area]) && !in_array($vhost, array_keys(self::DOMAINS), true)) {
            return Answer::fixture(self::V1_UNKNOWN[$area]);
        }

        return match (true) {
            $this->method === 'GET' && $page !== null => Answer::page($list !== null ? "v1/{$config['dir']}/$list" : 'v1/redirects/list-empty', 'list', $page),
            $this->method === 'PUT' || $this->method === 'POST' => $this->written($config['objectType'], 'targetObject', null),
            // captured: JSON, but declared as text/html
            $this->method === 'DELETE' => new Answer(200, 'text/html; charset=UTF-8', ['error' => false, 'targetObject' => [json_decode($this->body, true)], 'violationList' => []]),
            default => self::notEmulated($this->method, "/$area/$vhost"),
        };
    }

    private function v2Vhost(string $area, int $domainId, string $vhost, ?string $tail, int $page): Answer
    {
        $config = self::AREAS[$area];
        $name = array_search($domainId, self::DOMAINS, true);
        if ($name === false || ($vhost !== $name && !str_ends_with($vhost, '.' . $name)) || !in_array($vhost, ['example.com', 'www.example.com'], true)) {
            return Answer::fixture($name === false ? 'v2/errors/unknown-domain-id' : 'v2/redirects/unknown-vhost');
        }
        $list = $config['lists'][$vhost]; // every area lists both emulated vhosts
        $id = $tail !== null && preg_match('/^\d+$/', $tail) === 1 ? (int) $tail : null;

        return match (true) {
            $this->method === 'GET' && $tail === null => $vhost === 'example.com'
                ? Answer::page("v2/{$config['dir']}/$list", 'data', $page)
                : Answer::page("v1/{$config['dir']}/$list", 'list', $page)->rename('list', 'data'),
            $this->method === 'GET' && $id !== null => $id === $config['get'] ? Answer::fixture("v2/{$config['dir']}/get") : Answer::fixture('v2/redirects/unknown-vhost'),
            $this->method === 'GET' && $tail === 'export' => new Answer(200, 'text/csv; charset=UTF-8', $vhost === 'example.com' ? self::exportOf('v2/ipfilter/list-apex') : "IP,Expires on,Comments\n"),
            $this->method === 'POST' && $tail === 'import' => $this->import(),
            $this->method === 'POST' && $tail === null => $this->written($config['objectType'], 'data', null, 201),
            $this->method === 'PUT' && $id !== null => $this->written($config['objectType'], 'data', $id),
            $this->method === 'DELETE' && $id !== null => new Answer(204, null, ''),
            default => self::notEmulated($this->method, "/domain/$domainId/$area/$vhost"),
        };
    }

    /**
     * CSV import as captured: HTTP 201, `error: false`, one violation per invalid line.
     */
    private function import(): Answer
    {
        $violations = [];
        foreach (explode("\n", trim($this->body)) as $line) {
            $ip = explode(',', $line)[0];
            if ($line !== '' && @inet_pton(explode('/', $ip)[0]) === false) {
                $violations[] = ['propertyPath' => 'value', 'message' => 'Please enter a valid IP-Range'];
            }
        }

        return new Answer(201, 'application/json', ['error' => false, 'violationList' => $violations, 'warningList' => [], 'data' => []]);
    }

    private static function exportOf(string $fixture): string
    {
        $body = Answer::fixture($fixture)->body;
        $csv = "IP,Expires on,Comments\n";
        foreach (is_array($body) && is_array($body['data'] ?? null) ? $body['data'] : [] as $entry) {
            if (is_array($entry)) {
                $columns = array_map(static fn(mixed $v): string => is_string($v) ? $v : '', [$entry['value'] ?? '', $entry['expireDate'] ?? '', $entry['comment'] ?? '']);
                $csv .= implode(',', $columns) . "\n";
            }
        }

        return $csv;
    }

    /**
     * Echo of a write: the sent object with id and a new modified, in the captured shape.
     */
    private function written(string $objectType, string $key, ?int $id, int $status = 200): Answer
    {
        $redirect = json_decode($this->body, true);
        if (!is_array($redirect)) {
            return Answer::fixture(($key === 'data' ? 'v2' : 'v1') . '/errors/malformed-body');
        }
        $redirect = ['objectType' => $objectType] + $redirect;
        $redirect['id'] = $id ?? (is_int($redirect['id'] ?? null) ? $redirect['id'] : 4711);
        $redirect['created'] ??= '2026-10-16T12:00:00+0200';
        $redirect['modified'] = '2026-10-16T12:00:0' . (is_string($redirect['modified'] ?? null) ? '1' : '0') . '+0200';

        $answer = Answer::success($key, [$redirect]);

        return new Answer($status, $answer->contentType, $answer->body);
    }

    /**
     * Validates like Myra (captured violations) and echoes the CacheClearVO.
     */
    private function cacheClear(string $version, string $domain, string $key): Answer
    {
        $data = json_decode($this->body, true);
        if (!is_array($data)) {
            return Answer::fixture($version . '/errors/malformed-body');
        }
        if (array_key_exists('recursive', $data) && !is_bool($data['recursive'])) {
            return Answer::fixture('v2/errors/wrong-type');
        }
        $fqdn = $data['fqdn'] ?? null;
        if (!is_string($fqdn) || ($fqdn !== $domain && !str_ends_with($fqdn, '.' . $domain))) {
            return Answer::fixture($version . '/errors/fqdn-not-in-the-domain');
        }
        $bulk = is_array($data['bulk'] ?? null) ? array_values(array_filter($data['bulk'], 'is_string')) : [];
        $resource = $bulk === [] ? ($data['resource'] ?? null) : $bulk[count($bulk) - 1];

        return Answer::success($key, [[
            'objectType' => 'CacheClearVO',
            'fqdn' => $fqdn,
            'resource' => $resource,
            'recursive' => $data['recursive'] ?? false,
            'bulk' => $bulk,
        ]]);
    }

    /**
     * @return array<mixed>
     */
    private function query(): array
    {
        parse_str((string) parse_url($this->uri, PHP_URL_QUERY), $query);

        return $query;
    }

    private static function notEmulated(string $method, string $route): Answer
    {
        return new Answer(404, 'application/json', ['error' => true, 'violationList' => [['propertyPath' => '', 'message' => "Not emulated: $method $route"]]]);
    }

    public static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}

// ---------------------------------------------------------------- request handling

$method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
$body = (string) file_get_contents('php://input');
// Only the headers the emulator needs (the built-in server puts Content-Length without HTTP_).
$headers = [];
foreach (['authorization' => 'HTTP_AUTHORIZATION', 'date' => 'HTTP_DATE', 'content-length' => 'CONTENT_LENGTH', 'content-type' => 'CONTENT_TYPE'] as $name => $key) {
    if (is_string($_SERVER[$key] ?? null)) {
        $headers[$name] = $_SERVER[$key];
    }
}
$log = Emulator::env('MYRA_EMULATOR_LOG', sys_get_temp_dir() . '/myra-emulator.jsonl');

if (str_starts_with($uri, '/_emulator/')) {
    header('Content-Type: application/json');
    if ($method === 'POST' && $uri === '/_emulator/reset') {
        file_put_contents($log, '');
        echo '{"reset":true}';
    } else {
        $lines = is_file($log) ? (array) file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        echo '[' . implode(',', $lines) . ']';
    }

    return;
}

$emulator = new Emulator($method, $uri, $body, $headers);
$answer = $emulator->handle();
$authorization = $headers['authorization'] ?? '';
file_put_contents($log, json_encode([
    'method' => $method,
    'uri' => $uri,
    'auth' => str_starts_with($authorization, 'MYRA ') ? 'hmac' : (str_starts_with($authorization, 'Bearer ') ? 'bearer' : 'none'),
    'auth_ok' => $authorization !== '' && $emulator->authenticated($authorization),
    'body' => $body,
    // announced vs received: a difference means the client sent a body stream it had not rewound
    'content_length' => isset($headers['content-length']) ? (int) $headers['content-length'] : null, // string → int
    'body_bytes' => strlen($body),
    'status' => $answer->status,
], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
$answer->send();
