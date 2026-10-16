<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\Integration;

use Closure;
use DateTimeImmutable;
use LogicException;
use Myracloud\WebApi\Auth\Authentication;
use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Auth\HmacSignature;
use Myracloud\WebApi\Contract\Client;
use Myracloud\WebApi\Exception\AccessDeniedException;
use Myracloud\WebApi\Exception\DomainNotFoundException;
use Myracloud\WebApi\Exception\RequestRejectedException;
use Myracloud\WebApi\Model\CacheSetting;
use Myracloud\WebApi\Model\DnsRecord;
use Myracloud\WebApi\Model\Domain;
use Myracloud\WebApi\Model\IpFilter;
use Myracloud\WebApi\Model\Maintenance;
use Myracloud\WebApi\Model\MaintenanceTemplate;
use Myracloud\WebApi\Model\Redirect;
use Myracloud\WebApi\Model\StatisticQuery;
use Myracloud\WebApi\Model\UpstreamOptions;
use Myracloud\WebApi\V1;
use Myracloud\WebApi\V2;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Both clients over real HTTP — the default Guzzle client, real sockets, HMAC and token on
 * the wire — against the emulator (tests/Emulator), which answers with the captures and
 * checks authentication independently of this package. Pages of 2 make every list span
 * several requests.
 */
#[CoversNothing]
final class IntegrationTest extends TestCase
{
    private static ?EmulatorServer $server = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = new EmulatorServer(['MYRA_EMULATOR_PAGE_SIZE' => '2']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    protected function setUp(): void
    {
        self::server()->reset();
    }

    /**
     * @return iterable<string, array{'v1'|'v2', Closure(): Authentication}>
     */
    public static function clients(): iterable
    {
        $hmac = static fn(): Authentication => new HmacSignature('test-key', 'test-secret');
        $token = static fn(): Authentication => new BearerToken('test-token');
        yield 'v1, HMAC' => ['v1', $hmac];
        yield 'v1, token' => ['v1', $token];
        yield 'v2, HMAC' => ['v2', $hmac];
        yield 'v2, token' => ['v2', $token];
    }

    /**
     * @param 'v1'|'v2' $version
     * @param Closure(): Authentication $auth
     */
    #[DataProvider('clients')]
    public function testListsTheDnsRecordsOverAllPages(string $version, Closure $auth): void
    {
        $records = self::client($version, $auth())->dnsRecords()->list('example.com');

        self::assertSame(
            ['example.com', 'api.example.com', 'www.example.com', 'staging.example.com', 'api.staging.example.com', 'map.example.com'],
            array_map(static fn(DnsRecord $r): string => $r->name(), $records),
        );
        self::assertSame(
            $version === 'v1'
                ? ['GET /en/rapi/dnsRecords/example.com/1', 'GET /en/rapi/dnsRecords/example.com/2', 'GET /en/rapi/dnsRecords/example.com/3']
                : ['GET /domains', 'GET /domain/1000/dns-records', 'GET /domain/1000/dns-records?page=2', 'GET /domain/1000/dns-records?page=3'],
            self::requests(),
        );
        self::assertAllAuthenticated();
    }

    /**
     * @param 'v1'|'v2' $version
     * @param Closure(): Authentication $auth
     */
    #[DataProvider('clients')]
    public function testListsTheDomains(string $version, Closure $auth): void
    {
        $domains = self::client($version, $auth())->domains()->list();

        self::assertSame([[1000, 'example.com'], [1140, 'example.org']], array_map(static fn(Domain $d): array => [$d->id(), $d->name()], $domains));
        self::assertAllAuthenticated();
    }

    /**
     * Pages by query, across many pages of 2; active() is the same set in both versions.
     *
     * @param 'v1'|'v2' $version
     * @param Closure(): Authentication $auth
     */
    #[DataProvider('clients')]
    public function testListsMyrasIpRanges(string $version, Closure $auth): void
    {
        $ranges = self::client($version, $auth())->ipRanges();

        self::assertCount($version === 'v1' ? 22 : 37, $ranges->list());
        self::assertCount(22, $ranges->active());
        self::assertContains('GET /' . ($version === 'v1' ? 'en/rapi/networks' : 'ip-ranges') . '?page=11', self::requests());
        self::assertAllAuthenticated();
    }

    /**
     * The shared query in both versions; the dashboard statistics with v2.
     *
     * @param 'v1'|'v2' $version
     * @param Closure(): Authentication $auth
     */
    #[DataProvider('clients')]
    public function testReadsStatistics(string $version, Closure $auth): void
    {
        $client = self::client($version, $auth());
        $from = new DateTimeImmutable('2026-10-15T10:00:00+02:00');
        $to = new DateTimeImmutable('2026-10-15T11:59:59+02:00');

        $result = $client->statistics()->query((new StatisticQuery($from, $to, ['ALL:example.com']))->withDataSource('requests', 'requests'));
        self::assertIsInt($result->stats('requests')->sum());

        if ($client instanceof V2\Client) {
            $statistics = $client->statistics();
            self::assertArrayHasKey('total_requests', $statistics->totalRequests($from, $to)->totals());
            self::assertCount(3, $statistics->totalTraffic($from, $to));
            self::assertSame([0, 0, 0], array_values($statistics->httpStatusCodes($from, $to, ['example.org'])));
            self::assertCount(3, $statistics->topUrls($from, $to, ['example.com']));
            self::assertSame([], $statistics->topUrls($from, $to, ['example.org']));
            self::assertSame('DE', $statistics->worldMap($from, $to, ['example.com'])[0]->countryCode());
            self::assertArrayHasKey('www.example.com', $statistics->histogram($from, $to, V2\Statistics::CHART_TOTAL_REQUESTS, V2\Statistics::INTERVAL_HOURLY, ['example.com']));
            self::assertContains('POST /statistics/top-urls/1792051200/1792058399', self::requests());
        }
        self::assertAllAuthenticated();
    }

    /**
     * @param 'v1'|'v2' $version
     * @param Closure(): Authentication $auth
     */
    #[DataProvider('clients')]
    public function testClearsTheCache(string $version, Closure $auth): void
    {
        $client = self::client($version, $auth());

        $single = $client->cacheClear()->clear('example.com', 'www.example.com', '/*', true);
        $bulk = $client->cacheClear()->clearBulk('example.com', 'www.example.com', ['/*.css', '/*.js']);

        self::assertSame(['www.example.com', '/*', true], [$single[0]->fqdn(), $single[0]->resource(), $single[0]->isRecursive()]);
        self::assertSame(['/*.css', '/*.js'], $bulk[0]->bulk());
        $bodies = array_column(array_filter(self::server()->calls(), static fn(array $c): bool => $c['method'] === 'PUT'), 'body');
        self::assertSame(['{"fqdn":"www.example.com","resource":"/*","recursive":true}', '{"fqdn":"www.example.com","bulk":["/*.css","/*.js"],"recursive":false}'], $bodies);
        self::assertAllAuthenticated();
    }

    /**
     * @param 'v1'|'v2' $version
     * @param Closure(): Authentication $auth
     */
    #[DataProvider('clients')]
    public function testListsTheRedirectsOfEachVhost(string $version, Closure $auth): void
    {
        $redirects = self::client($version, $auth())->redirects();

        self::assertSame([90392], array_map(static fn(Redirect $r): ?int => $r->id(), $redirects->list('example.com')));
        self::assertSame([90404], array_map(static fn(Redirect $r): ?int => $r->id(), $redirects->list('www.example.com')));
        self::assertAllAuthenticated();
    }

    /**
     * Writes: the emulator echoes them as the manual/spec describe (not captured yet).
     *
     * @param 'v1'|'v2' $version
     * @param Closure(): Authentication $auth
     */
    #[DataProvider('clients')]
    public function testCreatesUpdatesAndDeletesARedirect(string $version, Closure $auth): void
    {
        $redirects = self::client($version, $auth())->redirects();

        $created = $redirects->create(new Redirect('www.example.com', '/old', 'https://www.example.com/new', Redirect::TYPE_PERMANENT));
        $updated = $redirects->update($created->withDestination('https://www.example.com/newer'));
        $redirects->delete($updated);

        self::assertSame(4711, $created->id());
        self::assertSame('https://www.example.com/newer', $updated->destination());
        self::assertNotSame($created->modified(), $updated->modified());
        $writes = array_values(array_filter(self::requests(), static fn(string $r): bool => !str_starts_with($r, 'GET')));
        self::assertSame(
            $version === 'v1'
                ? ['PUT /en/rapi/redirects/www.example.com', 'POST /en/rapi/redirects/www.example.com', 'DELETE /en/rapi/redirects/www.example.com']
                : ['POST /domain/1000/redirects/www.example.com', 'PUT /domain/1000/redirects/www.example.com/4711', 'DELETE /domain/1000/redirects/www.example.com/4711'],
            $writes,
        );
        self::assertAllAuthenticated();
    }

    /**
     * @param 'v1'|'v2' $version
     * @param Closure(): Authentication $auth
     */
    #[DataProvider('clients')]
    public function testManagesIpFilters(string $version, Closure $auth): void
    {
        $client = self::client($version, $auth());
        $filters = $client->ipFilters();

        self::assertCount(7, $filters->list('example.com'));
        self::assertSame([], $filters->list('www.example.com'));
        $created = $filters->create(new IpFilter('www.example.com', IpFilter::TYPE_BLACKLIST, '192.0.2.1/32', enabled: false));
        $updated = $filters->update($created->withComment('c'));
        $filters->delete($updated);
        self::assertSame('c', $updated->comment());
        if ($client instanceof V2\Client) {
            self::assertStringStartsWith("IP,Expires on,Comments\n192.0.2.123/32,", $client->ipFilters()->export('example.com'));
            self::assertSame(['value: Please enter a valid IP-Range'], array_map('strval', $client->ipFilters()->import('www.example.com', IpFilter::TYPE_BLACKLIST, "192.0.2.2/32,,\nnot-an-ip,,\n")));
        }
        self::assertAllAuthenticated();
    }

    /**
     * @param 'v1'|'v2' $version
     * @param Closure(): Authentication $auth
     */
    #[DataProvider('clients')]
    public function testManagesCacheSettings(string $version, Closure $auth): void
    {
        $settings = self::client($version, $auth())->cacheSettings();

        self::assertCount(19, $settings->list('example.com'));
        $created = $settings->create(new CacheSetting('www.example.com', '/__probe__/', CacheSetting::TTL_NO_CACHE, CacheSetting::TYPE_EXACT, enabled: false));
        $updated = $settings->update($created->withTtl(60));
        $settings->delete($updated);
        self::assertSame([60, 'www.example.com'], [$updated->ttl(), $updated->subdomain()]);
        self::assertAllAuthenticated();
    }

    /**
     * @param 'v1'|'v2' $version
     * @param Closure(): Authentication $auth
     */
    #[DataProvider('clients')]
    public function testReadsAndUpdatesSubdomainSettings(string $version, Closure $auth): void
    {
        $settings = self::client($version, $auth())->subdomainSettings();

        $www = $settings->get('www.example.com');
        self::assertSame(['antibot_post_flood', 'antibot_post_flood_threshold'], array_keys($www->own()));
        self::assertCount(94, $settings->effective('www.example.com'));
        self::assertSame($www->own(), $settings->update('www.example.com', $www->own())->own());
        $writes = array_values(array_filter(self::requests(), static fn(string $r): bool => str_starts_with($r, 'POST')));
        self::assertSame([$version === 'v1' ? 'POST /en/rapi/subdomainSetting/www.example.com' : 'POST /domain/1000/www.example.com/settings'], $writes);
        self::assertAllAuthenticated();
    }

    /**
     * @param 'v1'|'v2' $version
     * @param Closure(): Authentication $auth
     */
    #[DataProvider('clients')]
    public function testManagesDnsRecordsAndLoadBalancing(string $version, Closure $auth): void
    {
        $records = self::client($version, $auth())->dnsRecords();

        self::assertCount(6, $records->loadBalancing('example.com'));
        $created = $records->create('example.com', new DnsRecord('__probe__.example.com', 'TXT', 'probe'));
        $updated = $records->update('example.com', $created->withValue('probe 2')->withUpstreamOptions(new UpstreamOptions(weight: 2, id: 1, modified: 'm')));
        $records->delete('example.com', $updated);
        self::assertSame(['probe 2', 2], [$updated->value(), $updated->upstreamOptions()?->weight()]);
        self::assertAllAuthenticated();
    }

    /**
     * @param 'v1'|'v2' $version
     * @param Closure(): Authentication $auth
     */
    #[DataProvider('clients')]
    public function testManagesMaintenances(string $version, Closure $auth): void
    {
        $client = self::client($version, $auth());
        $maintenances = $client->maintenances();

        self::assertCount(1, $maintenances->list('www.example.com'));
        $created = $maintenances->create(new Maintenance('www.example.com', '2099-01-01T00:00:00+0000', '2099-01-01T01:00:00+0000', '<html>x</html>'));
        $updated = $maintenances->update($created->withContent('<html>y</html>'));
        $maintenances->delete($updated);
        self::assertSame('<html>y</html>', $updated->content());
        if ($client instanceof V2\Client) {
            self::assertCount(1, $client->maintenances()->forDomain('example.com', true));
            $template = $client->maintenanceTemplates()->create('example.com', new MaintenanceTemplate('t', '<html/>'));
            $client->maintenanceTemplates()->delete('example.com', $client->maintenanceTemplates()->update('example.com', $template->withName('u')));
        }
        self::assertAllAuthenticated();
    }

    /**
     * @param 'v1'|'v2' $version
     * @param Closure(): Authentication $auth
     */
    #[DataProvider('clients')]
    public function testManagesErrorPages(string $version, Closure $auth): void
    {
        $pages = self::client($version, $auth())->errorPages();

        $list = $pages->list('example.com'); // v2: unpaged — fetched once
        self::assertCount(4, $list);
        self::assertNotSame('', $pages->get('example.com', $list[0]->id())->content());
        $pages->set('example.com', '<html>x</html>', ['www.example.com' => [413]]);
        $pages->delete('example.com', ['www.example.com' => [413]]);
        $lists = array_filter(self::requests(), static fn(string $r): bool => str_contains($r, 'errorpages') && str_starts_with($r, 'GET') && !str_contains($r, '/9'));
        self::assertCount(1, $lists);
        self::assertAllAuthenticated();
    }

    /**
     * @param 'v1'|'v2' $version
     * @param Closure(): Authentication $auth
     */
    #[DataProvider('clients')]
    public function testAHostOutsideTheDomainIsRejected(string $version, Closure $auth): void
    {
        try {
            self::client($version, $auth())->cacheClear()->clear('example.com', 'no-such-host.invalid', '/*');
            self::fail('No exception thrown.');
        } catch (RequestRejectedException $exception) {
            self::assertSame('DOMAIN_DOES_NOT_EXIST', $exception->violations()[0]->code());
        }
    }

    /**
     * @param 'v1'|'v2' $version
     * @param Closure(): Authentication $auth
     */
    #[DataProvider('clients')]
    public function testAnUnknownDomainFails(string $version, Closure $auth): void
    {
        $this->expectException($version === 'v1' ? AccessDeniedException::class : DomainNotFoundException::class);

        self::client($version, $auth())->dnsRecords()->list('does-not-exist.invalid');
    }

    /**
     * @return iterable<string, array{'v1'|'v2', Authentication, string}>
     */
    public static function wrongCredentials(): iterable
    {
        // Exact messages only where captured (v1 wrong secret: text/plain; v2 wrong token:
        // empty body); the other combinations answer the same way by assumption.
        yield 'v1, wrong secret (captured)' => ['v1', new HmacSignature('test-key', 'wrong-secret'), 'API key wrong or not provided.'];
        yield 'v1, unknown key' => ['v1', new HmacSignature('other-key', 'test-secret'), ''];
        yield 'v1, wrong token' => ['v1', new BearerToken('wrong-token'), ''];
        yield 'v2, wrong token (captured)' => ['v2', new BearerToken('wrong-token'), 'empty response'];
        yield 'v2, wrong secret' => ['v2', new HmacSignature('test-key', 'wrong-secret'), ''];
        yield 'v2, unknown key' => ['v2', new HmacSignature('other-key', 'test-secret'), ''];
    }

    /**
     * @param 'v1'|'v2' $version
     * @param string $message expected part of the message; '' = not captured, class only
     */
    #[DataProvider('wrongCredentials')]
    public function testWrongCredentialsAreAccessDenied(string $version, Authentication $auth, string $message): void
    {
        $this->expectException(AccessDeniedException::class);
        if ($message !== '') {
            $this->expectExceptionMessage($message);
        }

        self::client($version, $auth)->domains()->list();
    }

    /**
     * @param 'v1'|'v2' $version
     */
    private static function client(string $version, Authentication $auth): Client
    {
        // No HTTP client given: the built-in Guzzle default, as a consumer would use it.
        return $version === 'v1' ? new V1\Client($auth, baseUri: self::server()->baseUri()) : new V2\Client($auth, self::server()->baseUri());
    }

    private static function server(): EmulatorServer
    {
        return self::$server ?? throw new LogicException('The emulator is not running.');
    }

    /**
     * @return list<string>
     */
    private static function requests(): array
    {
        return array_map(static fn(array $c): string => $c['method'] . ' ' . $c['uri'], self::server()->calls());
    }

    private static function assertAllAuthenticated(): void
    {
        $calls = self::server()->calls();
        self::assertNotSame([], $calls);
        foreach ($calls as $call) {
            self::assertTrue($call['auth_ok'], $call['method'] . ' ' . $call['uri'] . ' was not authenticated.');
            self::assertSame($call['content_length'] ?? 0, $call['body_bytes'], 'Body bytes differ from Content-Length: stream not rewound?');
        }
    }
}
