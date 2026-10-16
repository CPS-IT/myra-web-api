<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\V2;

use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Model\IpRange;
use Myracloud\WebApi\Tests\Support\FakeHttpClient;
use Myracloud\WebApi\Tests\Support\Fixture;
use Myracloud\WebApi\V2;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * As captured: the list, and page 2 of a probe with pageSize 10.
 */
#[CoversClass(V2\IpRanges::class)]
#[UsesClass(V2\Statistics::class)]
#[CoversClass(\Myracloud\WebApi\Model\IpRange::class)]
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
#[UsesClass(V2\MaintenanceTemplates::class)]
#[UsesClass(V2\Subdomains::class)]
#[UsesClass(V2\DomainVerifications::class)]
#[UsesClass(V2\DomainIdResolver::class)]
#[UsesClass(\Myracloud\WebApi\Auth\BearerToken::class)]
#[UsesClass(\Myracloud\WebApi\Http\Transport::class)]
#[UsesClass(\Myracloud\WebApi\Http\Envelope::class)]
#[UsesClass(\Myracloud\WebApi\Http\JsonObject::class)]
#[UsesClass(\Myracloud\WebApi\Model\Page::class)]
final class IpRangesTest extends TestCase
{
    private FakeHttpClient $http;
    private V2\IpRanges $ranges;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->ranges = (new V2\Client(new BearerToken('t'), httpClient: $this->http))->ipRanges();
    }

    /**
     * v2 lists all ranges, the disabled ones with validTo.
     */
    public function testListsTheRangesAsTheVersionReturnsThem(): void
    {
        $this->http->queue(Fixture::response('v2/ip-ranges/list'));

        $ranges = $this->ranges->list();

        self::assertSame(['GET https://apiv2.myracloud.com/ip-ranges'], $this->sent());
        self::assertCount(37, $ranges);
        self::assertSame(90001, $ranges[0]->id());
        self::assertSame($ranges[0]->raw()['network'], $ranges[0]->network());
    }

    public function testADisabledRangeCarriesItsEnd(): void
    {
        $this->http->queue(Fixture::response('v2/ip-ranges/list'));

        $range = $this->ranges->list()[0];

        self::assertEquals(
            new IpRange(90001, '192.0.2.101/24', false, '2018-06-25T02:00:00+0200', '2022-02-07T01:00:00+0100', 'redacted-1', '2026-04-09T16:52:01+0200', $range->raw()),
            $range,
        );
        self::assertSame([false, '2018-06-25T02:00:00+0200', '2022-02-07T01:00:00+0100', 'redacted-1', '2026-04-09T16:52:01+0200'], [$range->enabled(), $range->validFrom(), $range->validTo(), $range->comment(), $range->modified()]);
    }

    public function testActiveKeepsTheEnabledRangesOnly(): void
    {
        $this->http->queue(Fixture::response('v2/ip-ranges/list'));

        $active = $this->ranges->active();

        self::assertCount(22, $active);
        self::assertSame(90002, $active[0]->id());
        foreach ($active as $range) {
            self::assertTrue($range->enabled());
            self::assertNull($range->validTo());
        }
    }

    public function testPagesByQueryFromPage2On(): void
    {
        $this->http->queue(Fixture::response('v2/ip-ranges/page-2'));

        $page = $this->ranges->page(2);

        self::assertSame(['GET https://apiv2.myracloud.com/ip-ranges?page=2'], $this->sent());
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
