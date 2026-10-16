<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\V2;

use DateTimeImmutable;
use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Exception\RequestRejectedException;
use Myracloud\WebApi\Model\CountryStatistic;
use Myracloud\WebApi\Model\Graph;
use Myracloud\WebApi\Model\UrlStatistic;
use Myracloud\WebApi\Tests\Support\FakeHttpClient;
use Myracloud\WebApi\Tests\Support\Fixture;
use Myracloud\WebApi\V2;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The dashboard statistics as captured: two hours of a production domain (metric numbers
 * and URLs replaced), a quiet domain for the empty answers, and two failures.
 */
#[CoversClass(V2\Statistics::class)]
#[UsesClass(V2\SslCertificates::class)]
#[UsesClass(V2\Zones::class)]
#[CoversClass(\Myracloud\WebApi\Model\Graph::class)]
#[CoversClass(\Myracloud\WebApi\Model\StatisticTotals::class)]
#[CoversClass(\Myracloud\WebApi\Model\UrlStatistic::class)]
#[CoversClass(\Myracloud\WebApi\Model\CountryStatistic::class)]
#[UsesClass(V2\Client::class)]
#[UsesClass(V2\Domains::class)]
#[UsesClass(V2\DnsRecords::class)]
#[UsesClass(V2\CacheClearing::class)]
#[UsesClass(V2\Redirects::class)]
#[UsesClass(V2\IpFilters::class)]
#[UsesClass(V2\CacheSettings::class)]
#[UsesClass(V2\SubdomainSettings::class)]
#[UsesClass(V2\Maintenances::class)]
#[UsesClass(V2\ErrorPages::class)]
#[UsesClass(V2\IpRanges::class)]
#[UsesClass(V2\MaintenanceTemplates::class)]
#[UsesClass(V2\Subdomains::class)]
#[UsesClass(V2\DomainVerifications::class)]
#[UsesClass(V2\Waf::class)]
#[UsesClass(V2\Users::class)]
#[UsesClass(V2\Organization::class)]
#[UsesClass(V2\DomainIdResolver::class)]
#[UsesClass(\Myracloud\WebApi\Auth\BearerToken::class)]
#[UsesClass(\Myracloud\WebApi\Http\Transport::class)]
#[UsesClass(\Myracloud\WebApi\Http\Envelope::class)]
#[UsesClass(\Myracloud\WebApi\Http\JsonObject::class)]
#[UsesClass(\Myracloud\WebApi\Model\Violation::class)]
#[UsesClass(\Myracloud\WebApi\Exception\RequestRejectedException::class)]
final class StatisticsTest extends TestCase
{
    private const BASE = 'https://apiv2.myracloud.com/statistics/';

    private FakeHttpClient $http;
    private V2\Statistics $statistics;
    private DateTimeImmutable $from;
    private DateTimeImmutable $to;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->statistics = (new V2\Client(new BearerToken('t'), httpClient: $this->http))->statistics();
        $this->from = new DateTimeImmutable('2026-10-15T10:00:00+02:00');
        $this->to = new DateTimeImmutable('2026-10-15T11:59:59+02:00');
    }

    public function testTotalRequestsOfAllDomainsAreAGet(): void
    {
        $this->http->queue(Fixture::response('v2/statistics/total-requests'));

        $totals = $this->statistics->totalRequests($this->from, $this->to);

        self::assertSame(['GET ' . self::BASE . 'total-domain-requests/1792051200/1792058399', ''], $this->last());
        $data = self::fixtureBody('v2/statistics/total-requests')['data'];
        self::assertSame($data, $totals->totals());
        self::assertSame(['total_requests', 'total_uncached_requests', 'total_blocked_requests', 'total_cached_requests', 'total_redirect_requests', 'total_verified_requests', 'total_ingress_traffic', 'total_egress_traffic'], array_keys($totals->graphs()));
        self::assertSame(self::fixtureBody('v2/statistics/total-requests'), $totals->raw());
        $graph = $totals->graphs()['total_requests'];
        self::assertSame([1792051200000, 1792051320000, 1792051440000], array_keys($graph->points()));
        self::assertSame(['', 0, 0], [$graph->name(), $graph->max(), $graph->sum()]);
    }

    public function testTotalRequestsOfSomeHostsAreAPost(): void
    {
        $this->http->queue(Fixture::response('v2/statistics/total-requests-selection'));

        $totals = $this->statistics->totalRequests($this->from, $this->to, ['example.com']);

        self::assertSame(['POST ' . self::BASE . 'total-domain-requests/1792051200/1792058399', Fixture::requestBody('v2/statistics/total-requests-selection')], $this->last());
        self::assertArrayHasKey('upstream_time_avg', $totals->totals());
    }

    public function testTotalRequestsOfSubdomainsOnly(): void
    {
        $this->http->queue(Fixture::response('v2/statistics/total-requests-selection'));

        $this->statistics->totalRequests($this->from, $this->to, subdomains: ['www.example.com']);

        self::assertSame('{"domains":[],"subDomains":["www.example.com"]}', $this->last()[1]);
    }

    public function testTotalTrafficIsAMapOfGraphs(): void
    {
        $this->http->queue(Fixture::response('v2/statistics/total-traffic'));

        $graphs = $this->statistics->totalTraffic($this->from, $this->to);

        self::assertSame(['GET ' . self::BASE . 'total-domain-traffic/1792051200/1792058399', ''], $this->last());
        self::assertSame(['total_traffic', 'total_uncached_traffic', 'total_cached_traffic'], array_keys($graphs));
        self::assertContainsOnlyInstancesOf(Graph::class, $graphs);
    }

    public function testHttpStatusCodes(): void
    {
        $this->http->queue(Fixture::response('v2/statistics/http-status-codes'), Fixture::response('v2/statistics/http-status-codes-quiet'));

        $codes = $this->statistics->httpStatusCodes($this->from, $this->to, ['example.com']);

        self::assertSame(['POST ' . self::BASE . 'http-status-codes/1792051200/1792058399', Fixture::requestBody('v2/statistics/http-status-codes')], $this->last());
        self::assertSame([200, 301, 404], array_keys($codes));
        self::assertSame([0, 0, 0], array_values($this->statistics->httpStatusCodes($this->from, $this->to, ['example.org'])));
    }

    public function testTopUrls(): void
    {
        $this->http->queue(Fixture::response('v2/statistics/top-urls'), Fixture::response('v2/statistics/top-urls-empty'));

        $urls = $this->statistics->topUrls($this->from, $this->to, ['example.com']);

        self::assertSame(['POST ' . self::BASE . 'top-urls/1792051200/1792058399', Fixture::requestBody('v2/statistics/top-urls')], $this->last());
        $first = self::fixtureList('v2/statistics/top-urls')[0];
        self::assertEquals(new UrlStatistic('/path-1', 1236, 1237, $first), $urls[0]);
        self::assertSame(['/path-1', 1236, 1237, $first], [$urls[0]->url(), $urls[0]->count(), $urls[0]->cost(), $urls[0]->raw()]);
        self::assertSame([], $this->statistics->topUrls($this->from, $this->to, ['example.org']), 'Myra answers null for none');
    }

    public function testCachedUrlsHaveNoCost(): void
    {
        $this->http->queue(Fixture::response('v2/statistics/cached-urls'));

        $urls = $this->statistics->cachedUrls($this->from, $this->to, ['example.com']);

        self::assertSame(['POST ' . self::BASE . 'cached-urls/1792051200/1792058399', Fixture::requestBody('v2/statistics/cached-urls')], $this->last());
        self::assertSame(['/path-4', '/path-5', '/path-6'], array_map(static fn(UrlStatistic $u): string => $u->url(), $urls));
        self::assertNull($urls[0]->cost());
    }

    public function testWorldMap(): void
    {
        $this->http->queue(Fixture::response('v2/statistics/world-map'));

        $countries = $this->statistics->worldMap($this->from, $this->to, ['example.com']);

        self::assertSame(['POST ' . self::BASE . 'world-map/1792051200/1792058399/total_requests/desc', Fixture::requestBody('v2/statistics/world-map')], $this->last());
        self::assertSame(['DE', 'US', 'GB'], array_map(static fn(CountryStatistic $c): string => $c->countryCode(), $countries));
        $first = self::fixtureList('v2/statistics/world-map')[0];
        self::assertSame(array_diff_key($first, ['country_code' => true, 'astype' => true]), $countries[0]->totals());
        self::assertSame($first, $countries[0]->raw());
    }

    public function testWorldMapSortedOtherwise(): void
    {
        $this->http->queue(Fixture::response('v2/statistics/world-map'));

        $this->statistics->worldMap($this->from, $this->to, [], ['www.example.com'], V2\Statistics::SORT_COUNTRY_CODE, 'asc');

        self::assertSame(['POST ' . self::BASE . 'world-map/1792051200/1792058399/country_code/asc', '{"domains":[],"subDomains":["www.example.com"]}'], $this->last());
    }

    public function testHistogramByGroup(): void
    {
        $this->http->queue(Fixture::response('v2/statistics/histogram'));

        $groups = $this->statistics->histogram($this->from, $this->to, V2\Statistics::CHART_TOTAL_REQUESTS, V2\Statistics::INTERVAL_HOURLY, ['example.com'], sum: true);

        self::assertSame(['POST ' . self::BASE . 'histogram/1792051200/1792058399/total-requests/hourly/0/1', Fixture::requestBody('v2/statistics/histogram')], $this->last());
        self::assertSame(['ALL-1000', 'api.example.com', 'api.staging.example.com', 'map.example.com', 'example.com', 'staging.example.com', 'static.example.com', 'www.example.com'], array_keys($groups));
        self::assertSame([], $groups['ALL-1000']->points(), 'data: null');
        self::assertSame([1792051200000, 1792054800000], array_keys($groups['www.example.com']->points()));
    }

    public function testHistogramCompressedWithoutSum(): void
    {
        $this->http->queue(Fixture::response('v2/statistics/histogram'));

        $this->statistics->histogram($this->from, $this->to, V2\Statistics::CHART_TOTAL_TRAFFIC, V2\Statistics::INTERVAL_DAILY, ['example.com'], compressed: true);

        self::assertSame('POST ' . self::BASE . 'histogram/1792051200/1792058399/total-traffic/daily/1/0', $this->last()[0]);
    }

    public function testAnUnknownChartIsRejected(): void
    {
        $this->http->queue(Fixture::response('v2/statistics/histogram-unknown-chart'));

        $this->expectException(RequestRejectedException::class);
        $this->expectExceptionMessage('The entity does not exist.');

        $this->statistics->histogram($this->from, $this->to, 'no-such-chart', V2\Statistics::INTERVAL_HOURLY, ['example.com']);
    }

    public function testADomainOutsideTheAccountIsRejected(): void
    {
        $this->http->queue(Fixture::response('v2/statistics/top-urls-unknown-domain'));

        try {
            $this->statistics->topUrls($this->from, $this->to, ['does-not-exist.invalid']);
            self::fail('No exception thrown.');
        } catch (RequestRejectedException $exception) {
            self::assertSame('INTERNAL_ERROR', $exception->violations()[0]->code());
        }
    }

    public function testAPeriodMustNotEndBeforeItStarts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The period starts after it ends: 2026-10-15T11:59:59+02:00 > 2026-10-15T10:00:00+02:00.');

        try {
            $this->statistics->totalTraffic($this->to, $this->from);
        } finally {
            self::assertSame([], $this->http->requests());
        }
    }

    public function testAPeriodOfOneInstantIsFine(): void
    {
        $this->http->queue(Fixture::response('v2/statistics/total-traffic'));

        $this->statistics->totalTraffic($this->from, $this->from);

        self::assertSame('GET ' . self::BASE . 'total-domain-traffic/1792051200/1792051200', $this->last()[0]);
    }

    /**
     * @return array{string, string} request line and body
     */
    private function last(): array
    {
        $request = $this->http->lastRequest();

        return [$request->getMethod() . ' ' . $request->getUri(), (string) $request->getBody()];
    }

    /**
     * @return array<mixed>
     */
    private static function fixtureBody(string $name): array
    {
        $body = Fixture::load($name)['response']['body'];
        self::assertIsArray($body);

        return $body;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fixtureList(string $name): array
    {
        /** @var list<array<string, mixed>> */
        return self::fixtureBody($name);
    }
}
