<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\V1;

use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Exception\RequestRejectedException;
use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Model\CacheSetting;
use Myracloud\WebApi\Tests\Support\FakeHttpClient;
use Myracloud\WebApi\Tests\Support\Fixture;
use Myracloud\WebApi\V1;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Generated from the captured fixtures (tests/Fixtures/v1/cachesettings); see docs/2.0-design.md, "Area pattern".
 */
#[CoversClass(V1\CacheSettings::class)]
#[UsesClass(V1\SubdomainSettings::class)]
#[UsesClass(V1\Client::class)]
#[UsesClass(V1\Domains::class)]
#[UsesClass(V1\DnsRecords::class)]
#[UsesClass(V1\CacheClearing::class)]
#[UsesClass(V1\Redirects::class)]
#[UsesClass(V1\IpFilters::class)]
#[UsesClass(\Myracloud\WebApi\Auth\BearerToken::class)]
#[UsesClass(\Myracloud\WebApi\Http\Transport::class)]
#[UsesClass(\Myracloud\WebApi\Http\Envelope::class)]
#[UsesClass(\Myracloud\WebApi\Http\JsonObject::class)]
#[UsesClass(\Myracloud\WebApi\Model\Page::class)]
#[UsesClass(\Myracloud\WebApi\Model\CacheSetting::class)]
#[UsesClass(\Myracloud\WebApi\Model\Persisted::class)]
#[UsesClass(\Myracloud\WebApi\Model\DomainName::class)]
#[UsesClass(\Myracloud\WebApi\Model\Violation::class)]
#[UsesClass(\Myracloud\WebApi\Model\Domain::class)]
#[UsesClass(\Myracloud\WebApi\Exception\RequestRejectedException::class)]
#[UsesClass(\Myracloud\WebApi\Exception\TransportException::class)]
final class CacheSettingsTest extends TestCase
{
    private FakeHttpClient $http;
    private V1\CacheSettings $endpoint;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->endpoint = (new V1\Client(new BearerToken('t'), httpClient: $this->http))->cacheSettings();
    }

    public function testListsTheEntriesOfAVhost(): void
    {
        $this->http->queue(Fixture::response('v1/cachesettings/list-apex'));

        $entries = $this->endpoint->list('Example.COM.');

        self::assertSame(['GET https://api.myracloud.com/en/rapi/cacheSettings/example.com/1'], $this->sent());
        self::assertCount(19, $entries);
        self::assertSame('example.com', $entries[0]->subdomain());
    }

    public function testReadsOnePage(): void
    {
        $this->http->queue(Fixture::response('v1/cachesettings/list-empty'));

        self::assertSame([], $this->endpoint->page('www.example.com', 2)->items());
        self::assertSame(['GET https://api.myracloud.com/en/rapi/cacheSettings/www.example.com/2'], $this->sent());
    }

    public function testASubdomainThatIsNoVhostIsAServerError(): void
    {
        $this->http->queue(Fixture::response('v1/cachesettings/unknown-subdomain'));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('server error (HTTP 500)');

        $this->endpoint->list('www.example.org');
    }

    /**
     * The captured probe run: create, update, stale update, delete.
     */
    public function testCreatesAProbeAsCaptured(): void
    {
        $this->http->queue(Fixture::response('v1/cachesettings/create'));

        $created = $this->endpoint->create(self::probe());

        self::assertSame('PUT https://api.myracloud.com/en/rapi/cacheSettings/map.example.org', $this->sent()[0]);
        self::assertSame(Fixture::requestBody('v1/cachesettings/create'), (string) $this->http->lastRequest()->getBody());
        self::assertNotNull($created->id());
        self::assertSame('map.example.org', $created->subdomain());
    }

    public function testUpdatesWithTheVersionAsCaptured(): void
    {
        $this->http->queue(Fixture::response('v1/cachesettings/create'), Fixture::response('v1/cachesettings/update'));
        $created = $this->endpoint->create(self::probe());

        $updated = $this->endpoint->update($created->withTtl(CacheSetting::TTL_LIKE_ORIGIN));

        self::assertSame('POST https://api.myracloud.com/en/rapi/cacheSettings/map.example.org', $this->sent()[1]);
        self::assertSame(Fixture::requestBody('v1/cachesettings/update'), (string) $this->http->lastRequest()->getBody());
        self::assertNotSame($created->modified(), $updated->modified());
    }

    public function testAStaleVersionIsRejected(): void
    {
        $this->http->queue(Fixture::response('v1/cachesettings/create'), Fixture::response('v1/cachesettings/update-stale'));
        $created = $this->endpoint->create(self::probe());

        $this->expectException(RequestRejectedException::class);
        $this->expectExceptionMessage('The record has been edited in the meantime.');

        $this->endpoint->update($created->withTtl(60));
    }

    public function testDeletesAsCaptured(): void
    {
        $this->http->queue(Fixture::response('v1/cachesettings/create'), Fixture::response('v1/cachesettings/update'), Fixture::response('v1/cachesettings/delete'));
        $created = $this->endpoint->create(self::probe());
        $updated = $this->endpoint->update($created->withTtl(CacheSetting::TTL_LIKE_ORIGIN));

        $this->endpoint->delete($updated);

        self::assertSame('DELETE https://api.myracloud.com/en/rapi/cacheSettings/map.example.org' . (""), $this->sent()[2]);
        self::assertSame(Fixture::requestBody('v1/cachesettings/delete'), (string) $this->http->lastRequest()->getBody());
    }

    public function testGuardsWritesBeforeSending(): void
    {
        $persisted = new CacheSetting('map.example.org', '/a', 60, id: 7, modified: 'x');
        foreach ([fn() => $this->endpoint->update(self::probe()), fn() => $this->endpoint->delete(self::probe()), fn() => $this->endpoint->create($persisted)] as $write) {
            try {
                $write();
                self::fail('No exception thrown.');
            } catch (InvalidArgumentException) {
            }
        }
        self::assertSame([], $this->http->requests());
    }

    private static function probe(): CacheSetting
    {
        return new CacheSetting('map.example.org', '/__probe__/', CacheSetting::TTL_NO_CACHE, CacheSetting::TYPE_EXACT, sort: 999, enabled: false);
    }

    /**
     * @return list<string>
     */
    private function sent(): array
    {
        return array_map(static fn($r): string => $r->getMethod() . ' ' . $r->getUri(), $this->http->requests());
    }
}
