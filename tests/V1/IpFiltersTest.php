<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\V1;

use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Exception\RequestRejectedException;
use Myracloud\WebApi\Model\IpFilter;
use Myracloud\WebApi\Tests\Support\FakeHttpClient;
use Myracloud\WebApi\Tests\Support\Fixture;
use Myracloud\WebApi\V1;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(V1\IpFilters::class)]
#[UsesClass(V1\CacheSettings::class)]
#[UsesClass(V1\SubdomainSettings::class)]
#[UsesClass(V1\Client::class)]
#[UsesClass(V1\Domains::class)]
#[UsesClass(V1\DnsRecords::class)]
#[UsesClass(V1\CacheClearing::class)]
#[UsesClass(V1\Redirects::class)]
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
    private V1\IpFilters $filters;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->filters = (new V1\Client(new BearerToken('t'), httpClient: $this->http))->ipFilters();
    }

    public function testListsTheEntriesOfASubdomain(): void
    {
        $this->http->queue(Fixture::response('v1/ipfilter/list-apex'));

        $filters = $this->filters->list('Example.COM.');

        self::assertSame(['GET https://api.myracloud.com/en/rapi/ipfilter/example.com/1'], $this->sent());
        self::assertCount(7, $filters);
    }

    public function testReadsOnePage(): void
    {
        $this->http->queue(Fixture::response('v1/ipfilter/list-empty'));

        self::assertSame([], $this->filters->page('www.example.com', 2)->items());
        self::assertSame(['GET https://api.myracloud.com/en/rapi/ipfilter/www.example.com/2'], $this->sent());
    }

    /**
     * The captured probe run: create, update, stale update, delete.
     */
    public function testCreatesAProbeAsCaptured(): void
    {
        $this->http->queue(Fixture::response('v1/ipfilter/create'));

        $created = $this->filters->create(self::probe());

        self::assertSame('PUT https://api.myracloud.com/en/rapi/ipfilter/map.example.org', $this->sent()[0]);
        self::assertSame(Fixture::requestBody('v1/ipfilter/create'), (string) $this->http->lastRequest()->getBody());
        self::assertFalse($created->isEnabled());
        self::assertNotNull($created->id());
    }

    public function testUpdatesWithTheVersionAsCaptured(): void
    {
        $this->http->queue(Fixture::response('v1/ipfilter/create'), Fixture::response('v1/ipfilter/update'));
        $created = $this->filters->create(self::probe());

        $updated = $this->filters->update($created->withComment('__probe__ updated'));

        self::assertSame('POST https://api.myracloud.com/en/rapi/ipfilter/map.example.org', $this->sent()[1]);
        self::assertSame(Fixture::requestBody('v1/ipfilter/update'), (string) $this->http->lastRequest()->getBody());
        self::assertSame('__probe__ updated', $updated->comment());
    }

    public function testAStaleVersionIsRejected(): void
    {
        $this->http->queue(Fixture::response('v1/ipfilter/create'), Fixture::response('v1/ipfilter/update-stale'));
        $created = $this->filters->create(self::probe());

        $this->expectException(RequestRejectedException::class);
        $this->expectExceptionMessage('The record has been edited in the meantime.');

        $this->filters->update($created->withComment('__probe__ stale'));
    }

    public function testDeletesAsCaptured(): void
    {
        $this->http->queue(Fixture::response('v1/ipfilter/create'), Fixture::response('v1/ipfilter/update'), Fixture::response('v1/ipfilter/delete'));
        $created = $this->filters->create(self::probe());
        $updated = $this->filters->update($created->withComment('__probe__ updated'));

        $this->filters->delete($updated);

        self::assertSame('DELETE https://api.myracloud.com/en/rapi/ipfilter/map.example.org', $this->sent()[2]);
        self::assertSame(Fixture::requestBody('v1/ipfilter/delete'), (string) $this->http->lastRequest()->getBody());
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
