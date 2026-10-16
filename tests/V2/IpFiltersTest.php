<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\V2;

use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Exception\RequestRejectedException;
use Myracloud\WebApi\Model\IpFilter;
use Myracloud\WebApi\Tests\Support\FakeHttpClient;
use Myracloud\WebApi\Tests\Support\Fixture;
use Myracloud\WebApi\V2;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(V2\IpFilters::class)]
#[UsesClass(V2\CacheSettings::class)]
#[UsesClass(V2\SubdomainSettings::class)]
#[UsesClass(V2\Maintenances::class)]
#[UsesClass(V2\ErrorPages::class)]
#[UsesClass(V2\IpRanges::class)]
#[UsesClass(V2\Statistics::class)]
#[UsesClass(V2\SslCertificates::class)]
#[UsesClass(V2\MaintenanceTemplates::class)]
#[UsesClass(V2\Subdomains::class)]
#[UsesClass(V2\DomainVerifications::class)]
#[UsesClass(V2\Waf::class)]
#[UsesClass(V2\Client::class)]
#[UsesClass(V2\Domains::class)]
#[UsesClass(V2\DnsRecords::class)]
#[UsesClass(V2\CacheClearing::class)]
#[UsesClass(V2\Redirects::class)]
#[UsesClass(V2\DomainIdResolver::class)]
#[UsesClass(\Myracloud\WebApi\Auth\BearerToken::class)]
#[UsesClass(\Myracloud\WebApi\Http\Transport::class)]
#[UsesClass(\Myracloud\WebApi\Http\Envelope::class)]
#[UsesClass(\Myracloud\WebApi\Http\JsonObject::class)]
#[UsesClass(\Myracloud\WebApi\Model\Page::class)]
#[UsesClass(\Myracloud\WebApi\Model\IpFilter::class)]
#[UsesClass(\Myracloud\WebApi\Model\Persisted::class)]
#[UsesClass(\Myracloud\WebApi\Model\DomainName::class)]
#[UsesClass(\Myracloud\WebApi\Model\Violation::class)]
#[UsesClass(\Myracloud\WebApi\Model\Domain::class)]
#[UsesClass(\Myracloud\WebApi\Exception\RequestRejectedException::class)]
final class IpFiltersTest extends TestCase
{
    private FakeHttpClient $http;
    private V2\IpFilters $filters;

    protected function setUp(): void
    {
        $this->http = (new FakeHttpClient())->queue(Fixture::response('v2/domains/list'));
        $this->filters = (new V2\Client(new BearerToken('t'), httpClient: $this->http))->ipFilters();
    }

    public function testListsTheEntriesOfAVhost(): void
    {
        $this->http->queue(Fixture::response('v2/ipfilter/list-apex'));

        $filters = $this->filters->list('Example.COM.');

        self::assertSame(['GET https://apiv2.myracloud.com/domains', 'GET https://apiv2.myracloud.com/domain/1000/ip-filters/example.com'], $this->sent());
        self::assertCount(7, $filters);
    }

    public function testReadsOnePage(): void
    {
        $this->http->queue(Fixture::page('data', [], 2, 50, 0));

        $this->filters->page('www.example.com', 2);

        self::assertSame('GET https://apiv2.myracloud.com/domain/1000/ip-filters/www.example.com?page=2', $this->sent()[1]);
    }

    public function testGetsOneById(): void
    {
        $this->http->queue(Fixture::response('v2/ipfilter/get'));

        $filter = $this->filters->get('example.com', 90393);

        self::assertSame('GET https://apiv2.myracloud.com/domain/1000/ip-filters/example.com/90393', $this->sent()[1]);
        self::assertSame('192.0.2.123/32', $filter->value());
    }

    public function testExportsCsv(): void
    {
        $this->http->queue(Fixture::response('v2/ipfilter/export-type'));

        $csv = $this->filters->export('map.example.org', IpFilter::TYPE_BLACKLIST);

        self::assertSame('GET https://apiv2.myracloud.com/domain/1140/ip-filters/map.example.org/export?type=BLACKLIST', $this->sent()[1]);
        self::assertSame("IP,Expires on,Comments\n192.0.2.1/32,,__probe__ updated\n", $csv);
    }

    public function testExportsAllTypesWithoutFilter(): void
    {
        $this->http->queue(Fixture::response('v2/ipfilter/export'));

        $csv = $this->filters->export('example.com');

        self::assertSame('GET https://apiv2.myracloud.com/domain/1000/ip-filters/example.com/export', $this->sent()[1]);
        self::assertStringStartsWith("IP,Expires on,Comments\n", $csv);
    }

    public function testImportsCsvAndReportsSkippedLines(): void
    {
        $this->http->queue(Fixture::response('v2/ipfilter/import-partial'));

        $skipped = $this->filters->import('map.example.org', IpFilter::TYPE_BLACKLIST, Fixture::requestText('v2/ipfilter/import-partial'));

        self::assertSame('POST https://apiv2.myracloud.com/domain/1140/ip-filters/map.example.org/import?type=BLACKLIST', $this->sent()[1]);
        self::assertSame('text/csv', $this->http->lastRequest()->getHeaderLine('Content-Type'));
        self::assertSame(Fixture::requestText('v2/ipfilter/import-partial'), (string) $this->http->lastRequest()->getBody());
        self::assertSame(['value: Please enter a valid IP-Range'], array_map('strval', $skipped));
    }

    public function testAnImportWithoutSkippedLinesReportsNone(): void
    {
        $this->http->queue(Fixture::response('v2/ipfilter/import'));

        self::assertSame([], $this->filters->import('map.example.org', IpFilter::TYPE_BLACKLIST, Fixture::requestText('v2/ipfilter/import')));
    }

    public function testAnImportAnswerWithoutViolationListReportsNone(): void
    {
        $this->http->queue(new \GuzzleHttp\Psr7\Response(201, [], '{"error":false}'));

        self::assertSame([], $this->filters->import('map.example.org', IpFilter::TYPE_BLACKLIST, "192.0.2.5/32,,\n"));
    }

    public function testAVhostThatDoesNotExistIsRejected(): void
    {
        $this->http->queue(Fixture::response('v2/ipfilter/unknown-vhost'));

        $this->expectException(RequestRejectedException::class);

        $this->filters->list('example.org');
    }

    /**
     * The captured probe run: create, update, stale update, delete.
     */
    public function testCreatesAProbeAsCaptured(): void
    {
        $this->http->queue(Fixture::response('v2/ipfilter/create'));

        $created = $this->filters->create(self::probe());

        self::assertSame('POST https://apiv2.myracloud.com/domain/1140/ip-filters/map.example.org', $this->sent()[1]);
        self::assertSame(Fixture::requestBody('v2/ipfilter/create'), (string) $this->http->lastRequest()->getBody());
        self::assertFalse($created->isEnabled());
        self::assertNotNull($created->id());
    }

    public function testUpdatesWithTheVersionAsCaptured(): void
    {
        $this->http->queue(Fixture::response('v2/ipfilter/create'), Fixture::response('v2/ipfilter/update'));
        $created = $this->filters->create(self::probe());

        $updated = $this->filters->update($created->withComment('__probe__ updated'));

        self::assertSame('PUT https://apiv2.myracloud.com/domain/1140/ip-filters/map.example.org/90002', $this->sent()[2]);
        self::assertSame(Fixture::requestBody('v2/ipfilter/update'), (string) $this->http->lastRequest()->getBody());
        self::assertSame('__probe__ updated', $updated->comment());
    }

    public function testAStaleVersionIsRejected(): void
    {
        $this->http->queue(Fixture::response('v2/ipfilter/create'), Fixture::response('v2/ipfilter/update-stale'));
        $created = $this->filters->create(self::probe());

        $this->expectException(RequestRejectedException::class);
        $this->expectExceptionMessage('The record has been edited in the meantime.');

        $this->filters->update($created->withComment('__probe__ stale'));
    }

    public function testDeletesAsCaptured(): void
    {
        $this->http->queue(Fixture::response('v2/ipfilter/create'), Fixture::response('v2/ipfilter/update'), Fixture::response('v2/ipfilter/delete'));
        $created = $this->filters->create(self::probe());
        $updated = $this->filters->update($created->withComment('__probe__ updated'));

        $this->filters->delete($updated);

        self::assertSame('DELETE https://apiv2.myracloud.com/domain/1140/ip-filters/map.example.org/90002', $this->sent()[3]);
        self::assertSame('', (string) $this->http->lastRequest()->getBody());
    }

    public function testGuardsWritesBeforeSending(): void
    {
        $persisted = new IpFilter('www.example.com', IpFilter::TYPE_BLACKLIST, '192.0.2.1/32', id: 7, modified: 'x');
        foreach ([fn() => $this->filters->update(self::probe()), fn() => $this->filters->delete(self::probe()), fn() => $this->filters->create($persisted)] as $write) {
            try {
                $write();
                self::fail('No exception thrown.');
            } catch (InvalidArgumentException) {
            }
        }
        self::assertSame([], $this->http->requests());
    }

    private static function probe(): IpFilter
    {
        return new IpFilter('map.example.org', IpFilter::TYPE_BLACKLIST, '192.0.2.1/32', enabled: false, comment: '__probe__');
    }

    /**
     * @return list<string>
     */
    private function sent(): array
    {
        return array_map(static fn($r): string => $r->getMethod() . ' ' . $r->getUri(), $this->http->requests());
    }
}
