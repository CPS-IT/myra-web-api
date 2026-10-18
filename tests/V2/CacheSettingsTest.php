<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\V2;

use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Exception\RequestRejectedException;
use Myracloud\WebApi\Model\CacheSetting;
use Myracloud\WebApi\Tests\Support\FakeHttpClient;
use Myracloud\WebApi\Tests\Support\Fixture;
use Myracloud\WebApi\V2;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Generated from the captured fixtures (fixtures/v2/cachesettings of cpsit/myra-api-emulator); see docs/2.0-design.md, "Area pattern".
 */
#[CoversClass(V2\CacheSettings::class)]
#[UsesClass(V2\SubdomainSettings::class)]
#[UsesClass(V2\Maintenances::class)]
#[UsesClass(V2\ErrorPages::class)]
#[UsesClass(V2\IpRanges::class)]
#[UsesClass(V2\Statistics::class)]
#[UsesClass(V2\SslCertificates::class)]
#[UsesClass(V2\Zones::class)]
#[UsesClass(V2\MaintenanceTemplates::class)]
#[UsesClass(V2\Subdomains::class)]
#[UsesClass(V2\DomainVerifications::class)]
#[UsesClass(V2\Waf::class)]
#[UsesClass(V2\Users::class)]
#[UsesClass(V2\Organization::class)]
#[UsesClass(V2\Client::class)]
#[UsesClass(V2\Domains::class)]
#[UsesClass(V2\DnsRecords::class)]
#[UsesClass(V2\CacheClearing::class)]
#[UsesClass(V2\Redirects::class)]
#[UsesClass(V2\IpFilters::class)]
#[UsesClass(V2\DomainIdResolver::class)]
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
    private V2\CacheSettings $endpoint;

    protected function setUp(): void
    {
        $this->http = (new FakeHttpClient())->queue(Fixture::response('v2/domains/list'));
        $this->endpoint = (new V2\Client(new BearerToken('t'), httpClient: $this->http))->cacheSettings();
    }

    public function testListsTheEntriesOfAVhost(): void
    {
        $this->http->queue(Fixture::response('v2/cachesettings/list-apex'));

        $entries = $this->endpoint->list('Example.COM.');

        self::assertSame(['GET https://apiv2.myracloud.com/domains', 'GET https://apiv2.myracloud.com/domain/1000/example.com/cache-settings'], $this->sent());
        self::assertCount(19, $entries);
        self::assertSame('example.com', $entries[0]->subdomain());
    }

    public function testReadsOnePage(): void
    {
        $this->http->queue(Fixture::page('data', [], 2, 50, 0));

        self::assertSame([], $this->endpoint->page('www.example.com', 2)->items());
        self::assertSame('GET https://apiv2.myracloud.com/domain/1000/www.example.com/cache-settings?page=2', $this->sent()[1]);
    }

    public function testAVhostThatDoesNotExistIsRejected(): void
    {
        $this->http->queue(Fixture::response('v2/cachesettings/unknown-vhost'));

        $this->expectException(RequestRejectedException::class);
        $this->expectExceptionMessage('The entity does not exist.');

        $this->endpoint->list('example.org');
    }

    /**
     * The captured probe run: create, update, stale update, delete.
     */
    public function testCreatesAProbeAsCaptured(): void
    {
        $this->http->queue(Fixture::response('v2/cachesettings/create'));

        $created = $this->endpoint->create(self::probe());

        self::assertSame('POST https://apiv2.myracloud.com/domain/1140/map.example.org/cache-settings', $this->sent()[1]);
        self::assertSame(Fixture::requestBody('v2/cachesettings/create'), (string) $this->http->lastRequest()->getBody());
        self::assertNotNull($created->id());
        self::assertSame('map.example.org', $created->subdomain());
    }

    public function testUpdatesWithTheVersionAsCaptured(): void
    {
        $this->http->queue(Fixture::response('v2/cachesettings/create'), Fixture::response('v2/cachesettings/update'));
        $created = $this->endpoint->create(self::probe());

        $updated = $this->endpoint->update($created->withTtl(CacheSetting::TTL_LIKE_ORIGIN));

        self::assertSame('PUT https://apiv2.myracloud.com/domain/1140/map.example.org/cache-settings/' . $created->id() . '', $this->sent()[2]);
        self::assertSame(Fixture::requestBody('v2/cachesettings/update'), (string) $this->http->lastRequest()->getBody());
        self::assertNotSame($created->modified(), $updated->modified());
    }

    public function testAStaleVersionIsRejected(): void
    {
        $this->http->queue(Fixture::response('v2/cachesettings/create'), Fixture::response('v2/cachesettings/update-stale'));
        $created = $this->endpoint->create(self::probe());

        $this->expectException(RequestRejectedException::class);
        $this->expectExceptionMessage('The record has been edited in the meantime.');

        $this->endpoint->update($created->withTtl(60));
    }

    public function testDeletesAsCaptured(): void
    {
        $this->http->queue(Fixture::response('v2/cachesettings/create'), Fixture::response('v2/cachesettings/update'), Fixture::response('v2/cachesettings/delete'));
        $created = $this->endpoint->create(self::probe());
        $updated = $this->endpoint->update($created->withTtl(CacheSetting::TTL_LIKE_ORIGIN));

        $this->endpoint->delete($updated);

        self::assertSame('DELETE https://apiv2.myracloud.com/domain/1140/map.example.org/cache-settings' . ('/' . $updated->id()), $this->sent()[3]);
        self::assertSame('', (string) $this->http->lastRequest()->getBody());
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
