<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\V1;

use DateTimeImmutable;
use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Model\StatisticQuery;
use Myracloud\WebApi\Tests\Support\FakeHttpClient;
use Myracloud\WebApi\Tests\Support\Fixture;
use Myracloud\WebApi\V1;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * As captured; the shared behaviour is in the contract test.
 */
#[CoversClass(V1\Statistics::class)]
#[UsesClass(V1\SslCertificates::class)]
#[UsesClass(V1\Client::class)]
#[UsesClass(V1\Domains::class)]
#[UsesClass(V1\DnsRecords::class)]
#[UsesClass(V1\CacheClearing::class)]
#[UsesClass(V1\Redirects::class)]
#[UsesClass(V1\IpFilters::class)]
#[UsesClass(V1\CacheSettings::class)]
#[UsesClass(V1\SubdomainSettings::class)]
#[UsesClass(V1\Maintenances::class)]
#[UsesClass(V1\ErrorPages::class)]
#[UsesClass(V1\IpRanges::class)]
#[UsesClass(\Myracloud\WebApi\Auth\BearerToken::class)]
#[UsesClass(\Myracloud\WebApi\Http\Transport::class)]
#[UsesClass(\Myracloud\WebApi\Http\Envelope::class)]
#[UsesClass(\Myracloud\WebApi\Http\JsonObject::class)]
#[UsesClass(\Myracloud\WebApi\Model\StatisticQuery::class)]
#[UsesClass(\Myracloud\WebApi\Model\StatisticResult::class)]
#[UsesClass(TransportException::class)]
final class StatisticsTest extends TestCase
{
    public function testPostsTheQuery(): void
    {
        $http = (new FakeHttpClient())->queue(Fixture::response('v1/statistics/query'));

        (new V1\Client(new BearerToken('t'), httpClient: $http))->statistics()->query(self::query('requests'));

        self::assertSame('POST https://api.myracloud.com/en/rapi/statistic/query', $http->lastRequest()->getMethod() . ' ' . $http->lastRequest()->getUri());
    }

    public function testAnUnknownDataSourceIsAServerError(): void
    {
        $http = (new FakeHttpClient())->queue(Fixture::response('v1/statistics/query-unknown-source'));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('server error (HTTP 500)');

        (new V1\Client(new BearerToken('t'), httpClient: $http))->statistics()->query(self::query('no_such_source'));
    }

    private static function query(string $source): StatisticQuery
    {
        return (new StatisticQuery(new DateTimeImmutable('2026-10-15T10:00:00+02:00'), new DateTimeImmutable('2026-10-15T11:59:59+02:00'), ['ALL:example.com']))->withDataSource('x', $source);
    }
}
