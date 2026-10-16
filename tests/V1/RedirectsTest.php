<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\V1;

use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Exception\RequestRejectedException;
use Myracloud\WebApi\Model\Redirect;
use Myracloud\WebApi\Tests\Support\FakeHttpClient;
use Myracloud\WebApi\Tests\Support\Fixture;
use Myracloud\WebApi\V1;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(V1\Redirects::class)]
#[UsesClass(V1\IpFilters::class)]
#[UsesClass(V1\CacheSettings::class)]
#[UsesClass(V1\SubdomainSettings::class)]
#[UsesClass(V1\Maintenances::class)]
#[UsesClass(V1\Client::class)]
#[UsesClass(V1\Domains::class)]
#[UsesClass(V1\DnsRecords::class)]
#[UsesClass(V1\CacheClearing::class)]
#[UsesClass(\Myracloud\WebApi\Auth\BearerToken::class)]
#[UsesClass(\Myracloud\WebApi\Http\Transport::class)]
#[UsesClass(\Myracloud\WebApi\Http\Envelope::class)]
#[UsesClass(\Myracloud\WebApi\Http\JsonObject::class)]
#[UsesClass(\Myracloud\WebApi\Model\Page::class)]
#[UsesClass(\Myracloud\WebApi\Model\Redirect::class)]
#[UsesClass(\Myracloud\WebApi\Model\Persisted::class)]
#[UsesClass(\Myracloud\WebApi\Model\DomainName::class)]
#[UsesClass(\Myracloud\WebApi\Model\Violation::class)]
#[UsesClass(\Myracloud\WebApi\Exception\RequestRejectedException::class)]
final class RedirectsTest extends TestCase
{
    private FakeHttpClient $http;
    private V1\Redirects $redirects;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->redirects = (new V1\Client(new BearerToken('t'), httpClient: $this->http))->redirects();
    }

    public function testListsTheRedirectsOfASubdomain(): void
    {
        $this->http->queue(Fixture::response('v1/redirects/list-www'));

        $redirects = $this->redirects->list('WWW.example.com.');

        self::assertSame(['GET https://api.myracloud.com/en/rapi/redirects/www.example.com/1'], $this->sent());
        self::assertSame([[90404, 'www.example.com', Redirect::TYPE_PERMANENT, Redirect::MATCH_PREFIX, false]], array_map(static fn(Redirect $r): array => [$r->id(), $r->subdomain(), $r->type(), $r->matchingType(), $r->isEnabled()], $redirects));
    }

    public function testReadsOnePage(): void
    {
        $this->http->queue(Fixture::response('v1/redirects/list-empty'));

        $page = $this->redirects->page('example.org', 2);

        self::assertSame([], $page->items());
        self::assertSame(['GET https://api.myracloud.com/en/rapi/redirects/example.org/2'], $this->sent());
    }

    /**
     * The captured probe run (captures 25–37): create, update, stale update, delete.
     */
    public function testCreatesAProbeAsCaptured(): void
    {
        $this->http->queue(Fixture::response('v1/redirects/create'));

        $created = $this->redirects->create(self::probe());

        self::assertSame('PUT https://api.myracloud.com/en/rapi/redirects/map.example.org', $this->sent()[0]);
        self::assertSame(Fixture::requestBody('v1/redirects/create'), (string) $this->http->lastRequest()->getBody());
        self::assertSame([90001, false], [$created->id(), $created->isEnabled()]);
        self::assertNotNull($created->modified());
    }

    public function testUpdatesWithTheVersionAsCaptured(): void
    {
        $this->http->queue(Fixture::response('v1/redirects/create'), Fixture::response('v1/redirects/update'));
        $created = $this->redirects->create(self::probe());

        $updated = $this->redirects->update($created->withDestination('https://map.example.org/__probe__/target2'));

        self::assertSame('POST https://api.myracloud.com/en/rapi/redirects/map.example.org', $this->sent()[1]);
        self::assertSame(Fixture::requestBody('v1/redirects/update'), (string) $this->http->lastRequest()->getBody());
        self::assertNotSame($created->modified(), $updated->modified());
    }

    public function testAStaleVersionIsRejected(): void
    {
        $this->http->queue(Fixture::response('v1/redirects/create'), Fixture::response('v1/redirects/update-stale'));
        $created = $this->redirects->create(self::probe());

        $this->expectException(RequestRejectedException::class);
        $this->expectExceptionMessage('The record has been edited in the meantime.');

        $this->redirects->update($created->withDestination('https://map.example.org/__probe__/stale'));
    }

    public function testDeletesAsCaptured(): void
    {
        $this->http->queue(Fixture::response('v1/redirects/create'), Fixture::response('v1/redirects/update'), Fixture::response('v1/redirects/delete'));
        $created = $this->redirects->create(self::probe());
        $updated = $this->redirects->update($created->withDestination('https://map.example.org/__probe__/target2'));

        $this->redirects->delete($updated);

        self::assertSame('DELETE https://api.myracloud.com/en/rapi/redirects/map.example.org', $this->sent()[2]);
        self::assertSame(Fixture::requestBody('v1/redirects/delete'), (string) $this->http->lastRequest()->getBody());
    }

    public function testGuardsWritesBeforeSending(): void
    {
        $new = new Redirect('www.example.com', '/old', 'https://www.example.com/new');
        $persisted = new Redirect('www.example.com', '/old', 'https://www.example.com/new', id: 7, modified: 'x');
        foreach ([fn() => $this->redirects->update($new), fn() => $this->redirects->delete($new), fn() => $this->redirects->create($persisted)] as $write) {
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
