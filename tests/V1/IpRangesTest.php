<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\V1;

use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Tests\Support\FakeHttpClient;
use Myracloud\WebApi\Tests\Support\Fixture;
use Myracloud\WebApi\V1;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * As captured: the list, and page 2 of a probe with pageSize 10.
 */
#[CoversClass(V1\IpRanges::class)]
#[UsesClass(V1\Statistics::class)]
#[UsesClass(V1\SslCertificates::class)]
#[CoversClass(\Myracloud\WebApi\Model\IpRange::class)]
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
#[UsesClass(\Myracloud\WebApi\Auth\BearerToken::class)]
#[UsesClass(\Myracloud\WebApi\Http\Transport::class)]
#[UsesClass(\Myracloud\WebApi\Http\Envelope::class)]
#[UsesClass(\Myracloud\WebApi\Http\JsonObject::class)]
#[UsesClass(\Myracloud\WebApi\Model\Page::class)]
final class IpRangesTest extends TestCase
{
    private FakeHttpClient $http;
    private V1\IpRanges $ranges;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->ranges = (new V1\Client(new BearerToken('t'), httpClient: $this->http))->ipRanges();
    }

    /**
     * v1 lists only the enabled ranges.
     */
    public function testListsTheRangesAsTheVersionReturnsThem(): void
    {
        $this->http->queue(Fixture::response('v1/ip-ranges/list'));

        $ranges = $this->ranges->list();

        self::assertSame(['GET https://api.myracloud.com/en/rapi/networks'], $this->sent());
        self::assertCount(22, $ranges);
        self::assertSame(90002, $ranges[0]->id());
        self::assertSame($ranges[0]->raw()['network'], $ranges[0]->network());
    }

    public function testActiveKeepsTheEnabledRangesOnly(): void
    {
        $this->http->queue(Fixture::response('v1/ip-ranges/list'));

        $active = $this->ranges->active();

        self::assertCount(22, $active);
        foreach ($active as $range) {
            self::assertTrue($range->enabled());
            self::assertNull($range->validTo());
        }
    }

    public function testPagesByQueryFromPage2On(): void
    {
        $this->http->queue(Fixture::response('v1/ip-ranges/page-2'));

        $page = $this->ranges->page(2);

        self::assertSame(['GET https://api.myracloud.com/en/rapi/networks?page=2'], $this->sent());
        self::assertSame(2, $page->page());
        self::assertCount(10, $page->items());
    }

    public function testPagesStartAt1(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->ranges->page(0);
    }

    /**
     * @return list<string>
     */
    private function sent(): array
    {
        return array_map(static fn($r): string => $r->getMethod() . ' ' . $r->getUri(), $this->http->requests());
    }
}
