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
 *   built from the captured list;
 * - error pages: the captured answers whatever the selection; nothing is stored;
 * - maintenance: the account has none, so both vhosts answer the probe's captured list;
 * - DNS record writes are echoed in the captured shapes (stateless); the dedicated v2 load
 *   balancing PUT answers the captured HTTP 500;
 * - users and organisation: writes echoed (spec only), deletes 204, the permission check grants;
 * - domain writes: echoed (manual/spec only), deletes answer like the other deletes;
 * - zones (BIND): every domain answers the captured zone of example.org;
 * - SSL certificates: writes echoed without certificate, key and password (Myra never returns
 *   them — manual); v2 lists of example.org are the empty v1 list;
 * - subdomains: the account list ignores paging (captured) and filters like Myra; a verification
 *   check answers 409 DOMAIN_VERIFICATION_ALREADY_VERIFIED (spec; the account's domains are verified);
 * - statistics: the captured answers whatever the query or period; v2 dashboard: example.org
 *   answers the quiet domain's captures, histogram charts other than total-* are unknown;
 * - subdomain settings: v2 settings of www.example.com are the v1 capture, `?flat` answers
 *   the apex (v1) or the probed vhost (v2) capture whatever the vhost; POST is echoed.
 * - domains created with v2 (POST /domains) are kept, with the DNS records created in them,
 *   in the state file: they appear in the domain list after the captured ones and in their
 *   DNS record list, and take cache clears — the zone check as for the captured domains.
 *   Ids count from 4711. Created objects take the captured shape of the first list entry.
 *   A name that exists, or a record outside its domain, is rejected with a violation (spec,
 *   not captured). Everything else of a created domain is not emulated; v1 does not see them.
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
 *   MYRA_EMULATOR_STATE                        created domains and DNS records (sys_get_temp_dir()/myra-emulator-state.json)
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
    /**
     * @param list<array<string, mixed>> $more items after the fixture's (created objects)
     */
    public static function page(string $name, string $key, int $page, array $more = []): self
    {
        $answer = self::fixture($name);
        $body = is_array($answer->body) ? $answer->body : [];
        $items = [...(is_array($body[$key] ?? null) ? array_values($body[$key]) : []), ...$more];
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
    /** Reads of users and organisation (any id) → their capture. */
    private const USER_READS = [
        '/user/me' => 'users/me', '/users' => 'users/list', '/users/{id}' => 'users/get',
        '/user/{id}/groups' => 'users/groups-of-user', '/user/{id}/api-keys' => 'users/api-keys',
        '/user/{id}/activities' => 'users/activities', '/user/{id}/permissions' => 'users/permissions-of-user',
        '/user/permissions' => 'users/permissions', '/user/tokens' => 'users/tokens', '/user/groups' => 'users/groups',
        '/user/groups/{id}' => 'users/group', '/user/group/{id}/users' => 'users/group-users',
        '/user/group/{id}/permissions' => 'users/group-permissions', '/organization/contacts' => 'organization/contacts',
        '/organization/contact-types' => 'organization/contact-types', '/organization/notes' => 'organization/notes',
    ];
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
        // the account has no maintenance pages: both vhosts answer the probe's captured list
        'maintenance' => ['dir' => 'maintenance', 'objectType' => 'MaintenanceVO', 'lists' => ['example.com' => 'list', 'www.example.com' => 'list'], 'get' => 0, 'created' => 200],
    ];
    private const V1_AREAS = ['redirects' => 'redirects', 'ipfilter' => 'ipfilter', 'cacheSettings' => 'cachesettings', 'maintenance' => 'maintenance'];
    private const V2_AREAS = ['redirects' => 'redirects', 'ip-filters' => 'ipfilter', 'cache-settings' => 'cachesettings', 'maintenances' => 'maintenance'];
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
            $route === '/domains' && in_array($this->method, ['PUT', 'POST', 'DELETE'], true)
                => $this->method === 'DELETE' ? Answer::success('targetObject', [json_decode($this->body, true)]) : $this->domainWritten('targetObject', null),
            $this->method === 'GET' && preg_match('#^/(bind|bindRaw)/([^/]+)$#', $route, $m) === 1 && isset(self::DOMAINS[rawurldecode($m[2])])
                => Answer::fixture($m[1] === 'bind' ? 'v1/bind/zone' : 'v1/bind/file'),
            $this->method === 'POST' && $route === '/statistic/query'
                => Answer::fixture('v1/statistics/query'),
            preg_match('#^/certificates/([^/]+)(/(\d+))?$#', $route, $m) === 1 && isset(self::DOMAINS[rawurldecode($m[1])])
                => match (true) {
                    $this->method === 'GET' && isset($m[3]) => Answer::page(rawurldecode($m[1]) === 'example.com' ? 'v1/certificates/list' : 'v1/certificates/list-empty', 'list', (int) $m[3]),
                    $this->method === 'PUT' && !isset($m[3]) => $this->certificate('targetObject', 4711),
                    $this->method === 'POST' && !isset($m[3]) => $this->certificate('targetObject', null),
                    default => self::notEmulated($this->method, $route),
                },
            $this->method === 'GET' && $route === '/networks'
                => Answer::page('v1/ip-ranges/list', 'list', $this->page()),
            $this->method === 'GET' && preg_match('#^/dnsRecords/([^/]+)/(\d+)$#', $route, $m) === 1
                => rawurldecode($m[1]) === 'example.com'
                    ? Answer::page(array_key_exists('loadbalancer', $this->query()) ? 'v1/dns-records/load-balancing' : 'v1/dns-records/list', 'list', (int) $m[2])
                    : Answer::fixture('v1/errors/unknown-domain'),
            preg_match('#^/dnsRecords/([^/]+)$#', $route, $m) === 1 && isset(self::DOMAINS[rawurldecode($m[1])])
                => match ($this->method) {
                    'PUT', 'POST' => $this->written('DnsRecordVO', 'targetObject', null),
                    'DELETE' => new Answer(200, 'text/html; charset=UTF-8', ['error' => false, 'targetObject' => [json_decode($this->body, true)], 'violationList' => []]),
                    default => self::notEmulated($this->method, $route),
                },
            preg_match('#^/errorpages/([^/]+)(/(\d+))?$#', $route, $m) === 1 && isset(self::DOMAINS[rawurldecode($m[1])])
                => match ($this->method) {
                    'GET' => rawurldecode($m[1]) === 'example.com' ? Answer::page('v1/errorpages/list', 'list', (int) ($m[3] ?? 1)) : Answer::page('v1/redirects/list-empty', 'list', 1),
                    'POST' => Answer::fixture('v1/errorpages/set'),
                    'DELETE' => Answer::fixture('v1/errorpages/delete'),
                    default => self::notEmulated($this->method, $route),
                },
            $this->method === 'GET' && preg_match('#^/errorpage/\d+$#', $route) === 1
                => Answer::fixture('v1/errorpages/get'),
            preg_match('#^/subdomainSetting/([^/]+)$#', $route, $m) === 1
                => $this->settings('v1', rawurldecode($m[1])),
            preg_match('#^/(redirects|ipfilter|cacheSettings|maintenance)/([^/]+)(/(\d+))?$#', $route, $m) === 1
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
        $page = $this->page();

        return match (true) {
            $this->method === 'GET' && $route === '/domains'
                => Answer::page('v2/domains/list', 'data', $page, array_column($this->state()['domains'], 'domain')),
            $this->method === 'POST' && $route === '/statistic/query'
                => Answer::fixture('v2/statistics/query'),
            preg_match('#^/statistics/([a-z-]+)/\d+/\d+(/.*)?$#', $route, $m) === 1
                => $this->statistics($m[1], $m[2] ?? ''),
            $this->method === 'GET' && preg_match('#^/waf/(conditions|conditions/matching-types|actions|actions/values|countries)$#', $route, $m) === 1
                => Answer::fixture('v2/waf/' . ['conditions' => 'conditions', 'conditions/matching-types' => 'matching-types', 'actions' => 'actions', 'actions/values' => 'action-values', 'countries' => 'countries'][$m[1]]),
            $this->method === 'GET' && ($fixture = self::USER_READS[preg_replace('#/\d+(?=/|$)#', '/{id}', $route) ?? ''] ?? null) !== null
                => in_array($fixture, ['users/list', 'users/activities', 'users/permissions-of-user'], true) ? Answer::page('v2/' . $fixture, 'data', $page) : Answer::fixture('v2/' . $fixture),
            $this->method !== 'GET' && preg_match('#^/(users|user|organization)(/|$)#', $route) === 1
                => $this->userWrite($route),
            $this->method === 'POST' && $route === '/domains'
                => $this->createDomain(),
            preg_match('#^/domains/(\d+)$#', $route, $m) === 1 && ($this->method === 'DELETE' || ($this->method === 'PUT' && in_array((int) $m[1], self::DOMAINS, true)))
                => $this->method === 'DELETE' ? $this->deleteDomain((int) $m[1]) : $this->domainWritten('data', (int) $m[1]),
            $this->method === 'GET' && preg_match('#^/domains/(\d+)/(bind|bind-raw)/(Myra|Origin)$#', $route, $m) === 1 && in_array((int) $m[1], self::DOMAINS, true)
                => Answer::fixture('v2/bind/' . ($m[2] === 'bind' ? 'zone-' : 'file-') . strtolower($m[3])),
            $this->method === 'GET' && $route === '/ssl-configurations'
                => Answer::page('v2/certificates/configurations', 'data', $page),
            $this->method === 'GET' && preg_match('#^/domain/(\d+)/ssl/certificates(/\d+)?$#', $route, $m) === 1 && in_array((int) $m[1], self::DOMAINS, true)
                => match (true) {
                    isset($m[2]) => Answer::fixture('v2/certificates/get'),
                    (int) $m[1] === 1000 => Answer::page('v2/certificates/list', 'data', $page),
                    default => Answer::page('v1/certificates/list-empty', 'list', $page)->rename('list', 'data'),
                },
            preg_match('#^/domain/(\d+)/certificates(/(\d+))?$#', $route, $m) === 1 && in_array((int) $m[1], self::DOMAINS, true)
                => match (true) {
                    $this->method === 'POST' && !isset($m[3]) => $this->certificate('data', 4711, 201),
                    $this->method === 'PUT' && isset($m[3]) => $this->certificate('data', (int) $m[3]),
                    $this->method === 'DELETE' && isset($m[3]) => new Answer(204, null, ''),
                    default => self::notEmulated($this->method, $route),
                },
            $this->method === 'GET' && $route === '/subdomains'
                => $this->subdomains(),
            $this->method === 'GET' && preg_match('#^/domain/(\d+)/subdomains$#', $route, $m) === 1 && in_array((int) $m[1], self::DOMAINS, true)
                => Answer::page('v2/subdomains/list-domain', 'data', $page),
            preg_match('#^/domains/(\d+)(/verification(/check)?)?$#', $route, $m) === 1 && in_array((int) $m[1], self::DOMAINS, true)
                => match (true) {
                    $this->method === 'GET' && !isset($m[2]) => Answer::fixture('v2/domains/get'),
                    $this->method === 'GET' && !isset($m[3]) => Answer::fixture('v2/domains/verification'),
                    // assumed from the spec: every domain of the emulated account is verified
                    $this->method === 'POST' && isset($m[3]) => new Answer(409, 'application/json', ['error' => true, 'violationList' => [['propertyPath' => '', 'message' => 'The domain is already verified.', 'code' => 'DOMAIN_VERIFICATION_ALREADY_VERIFIED']], 'warningList' => []]),
                    default => self::notEmulated($this->method, $route),
                },
            $this->method === 'GET' && $route === '/ip-ranges'
                => Answer::page('v2/ip-ranges/list', 'data', $page),
            preg_match('#^/domain/(\d+)/(redirects|ip-filters)/([^/]+)(/(\d+|export|import))?$#', $route, $m) === 1
                => $this->v2Vhost(self::V2_AREAS[$m[2]], (int) $m[1], rawurldecode($m[3]), $m[5] ?? null, $page),
            preg_match('#^/domain/(\d+)/([^/]+)/settings$#', $route, $m) === 1 && in_array((int) $m[1], self::DOMAINS, true)
                => $this->settings('v2', rawurldecode($m[2])),
            // areas with the vhost before the area name
            preg_match('#^/domain/(\d+)/(errorpages|errorpages-grouped)(/(\d+))?$#', $route, $m) === 1 && in_array((int) $m[1], self::DOMAINS, true)
                => match (true) {
                    // captured: unpaged — page 0, pageSize 10^16, whatever ?page says
                    $this->method === 'GET' && $m[2] === 'errorpages' && !isset($m[4]) => Answer::fixture((int) $m[1] === 1000 ? 'v2/errorpages/list' : 'v2/errorpages/list-probe'),
                    $this->method === 'GET' && $m[2] === 'errorpages' => Answer::fixture('v2/errorpages/get'),
                    $this->method === 'GET' => Answer::fixture('v2/errorpages/grouped'),
                    $this->method === 'POST' && $m[2] === 'errorpages' => Answer::fixture('v2/errorpages/set'),
                    $this->method === 'DELETE' && $m[2] === 'errorpages' => new Answer(204, null, ''),
                    default => self::notEmulated($this->method, $route),
                },
            preg_match('#^/domain/(\d+)/(maintenances|maintenance-templates)(/(\d+))?$#', $route, $m) === 1 && in_array((int) $m[1], self::DOMAINS, true)
                => $this->v2DomainMaintenance($m[2], isset($m[4]) ? (int) $m[4] : null),
            preg_match('#^/domain/(\d+)/([^/]+)/(cache-settings|maintenances)(/(\d+))?$#', $route, $m) === 1
                => $this->v2Vhost(self::V2_AREAS[$m[3]], (int) $m[1], rawurldecode($m[2]), $m[5] ?? null, $page),
            preg_match('#^/domain/(\d+)/(load-balancing|vhost/\d+/dns-records|dns-records/(\d+)(/load-balancing)?)$#', $route, $m) === 1 && in_array((int) $m[1], self::DOMAINS, true)
                => $this->v2DnsRecord((int) $m[1], $m[2], isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null, $page),
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
        $created = $name === false ? $this->createdDomain((int) $id) : null;
        $name = $created['domain']['name'] ?? $name;
        if (!is_string($name)) {
            return Answer::fixture('v2/errors/unknown-domain-id');
        }

        return match (true) {
            $this->method === 'GET' && $area === 'dns-records' => match (true) {
                $created !== null => Answer::page('v2/domains/page-2', 'data', $page, $created['records']),
                $name === 'example.com' => Answer::page('v2/dns-records/list', 'data', $page),
                default => Answer::page('v2/domains/page-2', 'data', 1),
            },
            $this->method === 'POST' && $area === 'dns-records' => $created !== null
                ? $this->createDnsRecord($name)
                : $this->written('DnsRecordVO', 'data', null, 201),
            $this->method === 'PUT' && $area === 'cache-clear' => $this->cacheClear('v2', $name, 'data'),
            default => self::notEmulated($this->method, '/domain/' . $id . '/' . $area),
        };
    }

    /**
     * One record, load balancing, a vhost's records. The dedicated load balancing PUT answers
     * HTTP 500 — as captured for the (inactive) probe record.
     */
    private function v2DnsRecord(int $domainId, string $tail, ?int $id, int $page): Answer
    {
        return match (true) {
            $this->method === 'GET' && $tail === 'load-balancing' => $domainId === 1000 ? Answer::page('v2/dns-records/load-balancing', 'data', $page) : Answer::page('v2/domains/page-2', 'data', 1),
            $this->method === 'GET' && str_starts_with($tail, 'vhost/') => $tail === 'vhost/1007/dns-records' ? Answer::fixture('v2/dns-records/for-vhost') : Answer::page('v2/domains/page-2', 'data', 1),
            $this->method === 'GET' && $id !== null => $id === 1021 ? Answer::fixture('v2/dns-records/get') : Answer::fixture('v2/errors/unknown-domain-id'),
            $this->method === 'PUT' && str_ends_with($tail, '/load-balancing') => Answer::fixture('v2/dns-records/load-balancing-endpoint-500'),
            $this->method === 'PUT' && $id !== null => $this->written('DnsRecordVO', 'data', $id),
            $this->method === 'DELETE' && $id !== null => new Answer(204, null, ''),
            default => self::notEmulated($this->method, "/domain/$domainId/$tail"),
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
            $this->method === 'POST' && $tail === null => $this->written($config['objectType'], 'data', null, $config['created'] ?? 201),
            $this->method === 'PUT' && $id !== null => $this->written($config['objectType'], 'data', $id),
            $this->method === 'DELETE' && $id !== null => new Answer(204, null, ''),
            default => self::notEmulated($this->method, "/domain/$domainId/$area/$vhost"),
        };
    }

    /**
     * Domain-wide maintenance pages and the templates, as captured.
     */
    private function v2DomainMaintenance(string $area, ?int $id): Answer
    {
        return match (true) {
            $area === 'maintenances' && $this->method === 'GET' => $id === null ? Answer::fixture('v2/maintenance/for-domain') : Answer::fixture('v2/maintenance/get'),
            $area === 'maintenance-templates' && $this->method === 'GET' && $id === null => Answer::fixture('v2/maintenance-templates/list'),
            $area === 'maintenance-templates' && $this->method === 'POST' && $id === null => $this->written('MaintenanceTemplateVO', 'data', null, 201),
            $area === 'maintenance-templates' && $this->method === 'PUT' && $id !== null => $this->written('MaintenanceTemplateVO', 'data', $id),
            $area === 'maintenance-templates' && $this->method === 'DELETE' && $id !== null => new Answer(204, null, ''),
            default => self::notEmulated($this->method, "/$area"),
        };
    }

    /**
     * Subdomain settings: the captured layers of the apex and www.example.com, `?flat` merged,
     * POST echoed (v1 in targetObject, v2 in data — as captured).
     */
    private function settings(string $version, string $vhost): Answer
    {
        $known = ['example.com' => 'get-apex', 'www.example.com' => 'get-www'];
        if (!isset($known[$vhost])) {
            return Answer::fixture($version === 'v1' ? 'v1/settings/unknown-subdomain' : 'v2/settings/unknown-vhost');
        }

        return match (true) {
            $this->method === 'GET' && array_key_exists('flat', $this->query()) => Answer::fixture($version === 'v1' ? 'v1/settings/flat-apex' : 'v2/settings/flat'),
            $this->method === 'GET' => Answer::fixture(($version === 'v2' && $vhost === 'example.com' ? 'v2' : 'v1') . "/settings/{$known[$vhost]}"),
            $this->method === 'POST' => Answer::success($version === 'v1' ? 'targetObject' : 'data', [json_decode($this->body, true) ?? []]),
            default => self::notEmulated($this->method, "/settings/$vhost"),
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
     * A v2 domain created and kept: the captured DomainVO with the sent name and settings.
     */
    private function createDomain(): Answer
    {
        $data = json_decode($this->body, true);
        $name = is_array($data) && is_string($data['name'] ?? null) ? strtolower(rtrim(trim($data['name']), '.')) : '';
        if ($name === '') {
            return Answer::fixture('v2/errors/malformed-body');
        }
        $state = $this->state();
        if (isset(self::DOMAINS[$name]) || isset($state['domains'][$name])) {
            return self::violation('name', 'The domain already exists.');
        }
        $domain = array_merge(self::captured('v2/domains/list'), self::stringKeys(array_intersect_key($data, ['autoUpdate' => true, 'autoDns' => true])), [
            'id' => self::nextId($state),
            'name' => $name,
            'label' => $name,
            'created' => '2026-10-16T12:00:00+0200',
            'modified' => '2026-10-16T12:00:00+0200',
        ]);
        $state['domains'][$name] = ['domain' => $domain, 'records' => []];
        $this->saveState($state);

        $answer = Answer::success('data', [$domain]);

        return new Answer(201, $answer->contentType, $answer->body);
    }

    private function deleteDomain(int $id): Answer
    {
        $state = $this->state();
        foreach ($state['domains'] as $name => $created) {
            if ($created['domain']['id'] === $id) {
                unset($state['domains'][$name]);
                $this->saveState($state);
            }
        }

        return new Answer(204, null, '');
    }

    /**
     * A DNS record kept in a created domain: the captured DnsRecordVO with the sent fields.
     */
    private function createDnsRecord(string $domain): Answer
    {
        $data = json_decode($this->body, true);
        $name = is_array($data) && is_string($data['name'] ?? null) ? strtolower(rtrim($data['name'], '.')) : '';
        if ($name === '') {
            return Answer::fixture('v2/errors/malformed-body');
        }
        if ($name !== $domain && !str_ends_with($name, '.' . $domain)) {
            return self::violation('name', 'The DNS record is not part of the domain.');
        }
        $state = $this->state();
        $created = $state['domains'][$domain] ?? null;
        if ($created === null) {
            return Answer::fixture('v2/errors/unknown-domain-id');
        }
        $record = array_merge(self::captured('v2/dns-records/list'), self::stringKeys(array_diff_key($data, ['id' => true, 'modified' => true, 'created' => true])), [
            'id' => self::nextId($state),
            'name' => $name,
            'created' => '2026-10-16T12:00:00+0200',
            'modified' => '2026-10-16T12:00:00+0200',
        ]);
        $created['records'][] = $record;
        $state['domains'][$domain] = $created;
        $this->saveState($state);

        $answer = Answer::success('data', [$record]);

        return new Answer(201, $answer->contentType, $answer->body);
    }

    /**
     * @return array{domain: array<string, mixed>, records: list<array<string, mixed>>}|null
     */
    private function createdDomain(int $id): ?array
    {
        foreach ($this->state()['domains'] as $created) {
            if ($created['domain']['id'] === $id) {
                return $created;
            }
        }

        return null;
    }

    /**
     * @return array{domains: array<string, array{domain: array<string, mixed>, records: list<array<string, mixed>>}>}
     */
    private function state(): array
    {
        $file = self::stateFile();
        $json = is_file($file) ? file_get_contents($file) : false;
        $state = $json === false || $json === '' ? null : json_decode($json, true);

        // Rebuilt, not trusted: a broken or foreign file is an empty state.
        $domains = [];
        foreach (is_array($state) && is_array($state['domains'] ?? null) ? $state['domains'] : [] as $name => $created) {
            if (is_string($name) && is_array($created) && is_array($created['domain'] ?? null) && is_array($created['records'] ?? null)) {
                $records = [];
                foreach ($created['records'] as $record) {
                    if (is_array($record)) {
                        $records[] = self::stringKeys($record);
                    }
                }
                $domains[$name] = ['domain' => self::stringKeys($created['domain']), 'records' => $records];
            }
        }

        return ['domains' => $domains];
    }

    /**
     * @param array{domains: array<string, array{domain: array<string, mixed>, records: list<array<string, mixed>>}>} $state
     */
    private function saveState(array $state): void
    {
        file_put_contents(self::stateFile(), json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
    }

    public static function stateFile(): string
    {
        return self::env('MYRA_EMULATOR_STATE', sys_get_temp_dir() . '/myra-emulator-state.json');
    }

    /**
     * One id for domains and records, from 4711: after the highest one created.
     *
     * @param array{domains: array<string, array{domain: array<string, mixed>, records: list<array<string, mixed>>}>} $state
     */
    private static function nextId(array $state): int
    {
        $ids = [4710];
        foreach ($state['domains'] as $created) {
            foreach ([$created['domain'], ...$created['records']] as $object) {
                if (is_int($object['id'] ?? null)) {
                    $ids[] = $object['id'];
                }
            }
        }

        return max($ids) + 1;
    }

    /**
     * The first entry of a captured list, as the shape of a created object.
     *
     * @return array<string, mixed>
     */
    private static function captured(string $fixture): array
    {
        $body = Answer::fixture($fixture)->body;
        $list = is_array($body) ? ($body['data'] ?? null) : null;
        $first = is_array($list) && is_array($list[0] ?? null) ? $list[0] : [];

        return array_diff_key(self::stringKeys($first), ['id' => true, 'name' => true]);
    }

    /**
     * @param array<mixed> $object
     * @return array<string, mixed>
     */
    private static function stringKeys(array $object): array
    {
        $typed = [];
        foreach ($object as $key => $value) {
            if (is_string($key)) {
                $typed[$key] = $value;
            }
        }

        return $typed;
    }

    private static function violation(string $propertyPath, string $message): Answer
    {
        return new Answer(400, 'application/json', ['error' => true, 'violationList' => [['propertyPath' => $propertyPath, 'message' => $message]], 'warningList' => []]);
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
     * User and organisation writes (spec only, never captured): deletes answer 204, the
     * permission check grants everything, everything else is echoed with an id.
     */
    private function userWrite(string $route): Answer
    {
        return match (true) {
            $this->method === 'DELETE' => new Answer(204, null, ''),
            $route === '/user/permissions/check' => Answer::success('data', [['isAuthorized' => true]]),
            default => $this->withDefaults($this->written('UserApiVO', 'data', null, $this->method === 'POST' ? 201 : 200), str_starts_with($route, '/users') ? ['active' => true, 'locked' => false] : []),
        };
    }

    /**
     * A domain write echoed as the complete DomainVO (an update carries only id, modified and
     * the change; Myra answers the whole object — manual).
     */
    private function domainWritten(string $key, ?int $id): Answer
    {
        $body = json_decode($this->body, true);
        $id ??= is_array($body) && is_int($body['id'] ?? null) ? $body['id'] : null;
        $name = $id === null ? false : array_search($id, self::DOMAINS, true);

        return $this->withDefaults($this->written('DomainVO', $key, $id), is_string($name) ? ['name' => $name] : [], $key);
    }

    /**
     * Fields Myra always sends (e.g. in a UserVO) that a request body does not carry.
     *
     * @param array<string, mixed> $defaults
     */
    private function withDefaults(Answer $answer, array $defaults, string $key = 'data'): Answer
    {
        $body = is_array($answer->body) ? $answer->body : [];
        if (is_array($body[$key] ?? null) && is_array($body[$key][0] ?? null)) {
            $body[$key][0] += $defaults;
        }

        return new Answer($answer->status, $answer->contentType, $body);
    }

    /**
     * A certificate write echoed like the other writes, without what Myra never returns
     * (certificate, key, password).
     */
    private function certificate(string $key, ?int $id, int $status = 200): Answer
    {
        $answer = $this->written('SslCertVO', $key, $id, $status);
        $body = is_array($answer->body) ? $answer->body : [];
        if (is_array($body[$key] ?? null) && is_array($body[$key][0] ?? null)) {
            $body[$key][0] = array_diff_key($body[$key][0], ['cert' => true, 'key' => true, 'password' => true]);
        }

        return new Answer($answer->status, $answer->contentType, $body);
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
     * v2 dashboard statistics: the captures, whatever the period; example.org is the quiet
     * domain (no URLs, status codes all 0), the histogram knows the charts of the spec.
     */
    private function statistics(string $statistic, string $tail): Answer
    {
        $hosts = json_decode($this->body, true);
        $quiet = is_array($hosts) && in_array('example.org', is_array($hosts['domains'] ?? null) ? $hosts['domains'] : [], true);
        $chart = explode('/', $tail)[1] ?? '';

        return match (true) {
            $statistic === 'total-domain-requests' && $this->method === 'GET' => Answer::fixture('v2/statistics/total-requests'),
            $statistic === 'total-domain-requests' && $this->method === 'POST' => Answer::fixture('v2/statistics/total-requests-selection'),
            $statistic === 'total-domain-traffic' && $this->method === 'GET' => Answer::fixture('v2/statistics/total-traffic'),
            $this->method !== 'POST' => self::notEmulated($this->method, "/statistics/$statistic"),
            $statistic === 'http-status-codes' => Answer::fixture($quiet ? 'v2/statistics/http-status-codes-quiet' : 'v2/statistics/http-status-codes'),
            $statistic === 'top-urls' => Answer::fixture($quiet ? 'v2/statistics/top-urls-empty' : 'v2/statistics/top-urls'),
            $statistic === 'cached-urls' => Answer::fixture('v2/statistics/cached-urls'),
            $statistic === 'world-map' => Answer::fixture('v2/statistics/world-map'),
            $statistic === 'histogram' && str_starts_with($chart, 'total-') => Answer::fixture('v2/statistics/histogram'),
            $statistic === 'histogram' => Answer::fixture('v2/statistics/histogram-unknown-chart'),
            default => self::notEmulated($this->method, "/statistics/$statistic"),
        };
    }

    /**
     * The account's subdomains, unpaged as captured; `search` filters by name (`filterType=exact`: the whole name).
     */
    private function subdomains(): Answer
    {
        $answer = Answer::fixture('v2/subdomains/list');
        $body = is_array($answer->body) ? $answer->body : [];
        $search = $this->query()['search'] ?? null;
        if (is_string($search)) {
            $exact = ($this->query()['filterType'] ?? null) === 'exact';
            $body['data'] = array_values(array_filter(
                is_array($body['data'] ?? null) ? $body['data'] : [],
                static fn(mixed $s): bool => is_array($s) && is_string($s['label'] ?? null) && ($exact ? $s['label'] === $search : str_contains($s['label'], $search)),
            ));
            $body['count'] = count($body['data']);
        }

        return new Answer(200, 'application/json', $body);
    }

    /**
     * `?page=` (v2, and v1 IP ranges), 1 without.
     */
    private function page(): int
    {
        $page = $this->query()['page'] ?? '1';

        return max(1, is_string($page) ? (int) $page : 1);
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
