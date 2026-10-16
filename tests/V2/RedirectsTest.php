<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\V2;

use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Exception\DomainNotFoundException;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Exception\RequestRejectedException;
use Myracloud\WebApi\Model\Redirect;
use Myracloud\WebApi\Tests\Support\FakeHttpClient;
use Myracloud\WebApi\Tests\Support\Fixture;
use Myracloud\WebApi\V2;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(V2\Redirects::class)]
#[UsesClass(V2\IpFilters::class)]
#[UsesClass(V2\CacheSettings::class)]
#[UsesClass(V2\SubdomainSettings::class)]
#[CoversClass(V2\DomainIdResolver::class)]
#[UsesClass(V2\Client::class)]
#[UsesClass(V2\Domains::class)]
#[UsesClass(V2\DnsRecords::class)]
#[UsesClass(V2\CacheClearing::class)]
#[UsesClass(\Myracloud\WebApi\Auth\BearerToken::class)]
#[UsesClass(\Myracloud\WebApi\Http\Transport::class)]
#[UsesClass(\Myracloud\WebApi\Http\Envelope::class)]
#[UsesClass(\Myracloud\WebApi\Http\JsonObject::class)]
#[UsesClass(\Myracloud\WebApi\Model\Page::class)]
#[UsesClass(\Myracloud\WebApi\Model\Domain::class)]
#[UsesClass(\Myracloud\WebApi\Model\Redirect::class)]
#[UsesClass(\Myracloud\WebApi\Model\Persisted::class)]
#[UsesClass(\Myracloud\WebApi\Model\DomainName::class)]
#[UsesClass(\Myracloud\WebApi\Model\Violation::class)]
#[UsesClass(\Myracloud\WebApi\Exception\RequestRejectedException::class)]
final class RedirectsTest extends TestCase
{
    private FakeHttpClient $http;
    private V2\Client $client;

    protected function setUp(): void
    {
        $this->http = (new FakeHttpClient())->queue(Fixture::response('v2/domains/list'));
        $this->client = new V2\Client(new BearerToken('t'), httpClient: $this->http);
    }

    public function testListsTheRedirectsOfAVhostOfTheZoneItBelongsTo(): void
    {
        $this->http->queue(Fixture::response('v2/redirects/list-apex'));

        $redirects = $this->client->redirects()->list('Example.COM.');

        self::assertSame(['GET https://apiv2.myracloud.com/domains', 'GET https://apiv2.myracloud.com/domain/1000/redirects/example.com'], $this->sent());
        self::assertSame([90392], array_map(static fn(Redirect $r): ?int => $r->id(), $redirects));
    }

    public function testFindsTheZoneOfASubdomain(): void
    {
        $this->http->queue(Fixture::page('data', [], 2, 50, 0));

        $this->client->redirects()->page('www.shop.example.org', 2);

        self::assertSame('GET https://apiv2.myracloud.com/domain/1140/redirects/www.shop.example.org?page=2', $this->sent()[1]);
    }

    public function testPrefersTheLongestMatchingZone(): void
    {
        $http = (new FakeHttpClient())->queue(
            Fixture::page('data', [Fixture::domain(1, 'example.com'), Fixture::domain(2, 'shop.example.com')], 1, 2, 3),
            Fixture::page('data', [Fixture::domain(3, 'example.org')], 2, 2, 3),
            Fixture::page('data', [], 1, 50, 0),
            Fixture::page('data', [], 1, 50, 0),
        );
        $redirects = (new V2\Client(new BearerToken('t'), httpClient: $http))->redirects();

        $redirects->list('www.shop.example.com');
        $redirects->list('shop.example.com.evil.example.com');

        self::assertSame('https://apiv2.myracloud.com/domain/2/redirects/www.shop.example.com', (string) $http->requests()[2]->getUri());
        self::assertSame('https://apiv2.myracloud.com/domain/1/redirects/shop.example.com.evil.example.com', (string) $http->requests()[3]->getUri());
    }

    public function testAHostOfNoZoneIsNotFound(): void
    {
        $this->expectException(DomainNotFoundException::class);
        $this->expectExceptionMessage('The host "www.example.net" belongs to no domain of this account.');

        $this->client->redirects()->list('www.example.net');
    }

    public function testGetsOneById(): void
    {
        $this->http->queue(Fixture::response('v2/redirects/get'));

        $redirect = $this->client->redirects()->get('example.com', 90392);

        self::assertSame('GET https://apiv2.myracloud.com/domain/1000/redirects/example.com/90392', $this->sent()[1]);
        self::assertSame('https://example.com/path-6', $redirect->destination());
    }

    public function testAVhostThatDoesNotExistIsRejected(): void
    {
        $this->http->queue(Fixture::response('v2/redirects/unknown-vhost'));

        $this->expectException(RequestRejectedException::class);
        $this->expectExceptionMessage('The entity does not exist.');

        $this->client->redirects()->list('example.org');
    }

    /**
     * The captured probe run (captures 25–37): create, update, stale update, delete.
     */
    public function testCreatesAProbeAsCaptured(): void
    {
        $this->http->queue(Fixture::response('v2/redirects/create'));

        $created = $this->client->redirects()->create(self::probe());

        self::assertSame('POST https://apiv2.myracloud.com/domain/1140/redirects/map.example.org', $this->sent()[1]);
        self::assertSame(Fixture::requestBody('v2/redirects/create'), (string) $this->http->lastRequest()->getBody());
        self::assertSame([90003, false], [$created->id(), $created->isEnabled()]);
        self::assertNotNull($created->modified());
    }

    public function testUpdatesWithTheVersionAsCaptured(): void
    {
        $this->http->queue(Fixture::response('v2/redirects/create'), Fixture::response('v2/redirects/update'));
        $created = $this->client->redirects()->create(self::probe());

        $updated = $this->client->redirects()->update($created->withDestination('https://map.example.org/__probe__/target2'));

        self::assertSame('PUT https://apiv2.myracloud.com/domain/1140/redirects/map.example.org/90003', $this->sent()[2]);
        self::assertSame(Fixture::requestBody('v2/redirects/update'), (string) $this->http->lastRequest()->getBody());
        self::assertNotSame($created->modified(), $updated->modified());
    }

    public function testAStaleVersionIsRejected(): void
    {
        $this->http->queue(Fixture::response('v2/redirects/create'), Fixture::response('v2/redirects/update-stale'));
        $created = $this->client->redirects()->create(self::probe());

        $this->expectException(RequestRejectedException::class);
        $this->expectExceptionMessage('The record has been edited in the meantime.');

        $this->client->redirects()->update($created->withDestination('https://map.example.org/__probe__/stale'));
    }

    public function testDeletesAsCaptured(): void
    {
        $this->http->queue(Fixture::response('v2/redirects/create'), Fixture::response('v2/redirects/update'), Fixture::response('v2/redirects/delete'));
        $created = $this->client->redirects()->create(self::probe());
        $updated = $this->client->redirects()->update($created->withDestination('https://map.example.org/__probe__/target2'));

        $this->client->redirects()->delete($updated);

        self::assertSame('DELETE https://apiv2.myracloud.com/domain/1140/redirects/map.example.org/90003', $this->sent()[3]);
        self::assertSame('', (string) $this->http->lastRequest()->getBody());
    }

    public function testGuardsWritesBeforeSending(): void
    {
        $new = new Redirect('www.example.com', '/old', 'https://www.example.com/new');
        $persisted = new Redirect('www.example.com', '/old', 'https://www.example.com/new', id: 7, modified: 'x');
        foreach ([fn() => $this->client->redirects()->update($new), fn() => $this->client->redirects()->delete($new), fn() => $this->client->redirects()->create($persisted)] as $write) {
            try {
                $write();
                self::fail('No exception thrown.');
            } catch (InvalidArgumentException) {
            }
        }
        self::assertSame([], $this->http->requests());
    }

    private static function probe(): Redirect
    {
        return new Redirect('map.example.org', '/__probe__/redirect', 'https://map.example.org/__probe__/target', Redirect::TYPE_REDIRECT, Redirect::MATCH_EXACT, enabled: false);
    }

    /**
     * @return list<string>
     */
    private function sent(): array
    {
        return array_map(static fn($r): string => $r->getMethod() . ' ' . $r->getUri(), $this->http->requests());
    }
}
