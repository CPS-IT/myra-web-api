<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\V1;

use GuzzleHttp\Psr7\Response;
use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Model\Redirect;
use Myracloud\WebApi\Tests\Support\FakeHttpClient;
use Myracloud\WebApi\Tests\Support\Fixture;
use Myracloud\WebApi\V1;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(V1\Redirects::class)]
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
final class RedirectsTest extends TestCase
{
    /** v1 answer of a write, after the manual (§11.2.2) — not captured yet. */
    private const WRITTEN = '{"error":false,"violationList":[],"targetObject":[{"objectType":"DnsRedirectVO","id":7,"modified":"2026-10-16T12:00:00+0200","created":"2026-10-16T12:00:00+0200","subDomainName":"www.example.com","source":"/old","destination":"https://www.example.com/new","type":"permanent","matchingType":"exact","sort":0,"enabled":true}]}';

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

    public function testCreatesWithPut(): void
    {
        $this->http->queue(new Response(200, [], self::WRITTEN));

        $created = $this->redirects->create(new Redirect('www.example.com', '/old', 'https://www.example.com/new'));

        self::assertSame(['PUT https://api.myracloud.com/en/rapi/redirects/www.example.com'], $this->sent());
        self::assertSame('{"subDomainName":"www.example.com","source":"/old","destination":"https://www.example.com/new","type":"permanent","matchingType":"exact","sort":0,"enabled":true,"expertMode":false}', (string) $this->http->lastRequest()->getBody());
        self::assertSame([7, '2026-10-16T12:00:00+0200'], [$created->id(), $created->modified()]);
    }

    public function testUpdatesWithPostAndTheVersion(): void
    {
        $this->http->queue(new Response(200, [], self::WRITTEN), new Response(200, [], self::WRITTEN));
        $created = $this->redirects->create(new Redirect('www.example.com', '/old', 'https://www.example.com/new'));

        $this->redirects->update($created->withDestination('https://www.example.com/newer'));

        self::assertSame('POST https://api.myracloud.com/en/rapi/redirects/www.example.com', $this->sent()[1]);
        self::assertSame('{"subDomainName":"www.example.com","source":"/old","destination":"https://www.example.com/newer","type":"permanent","matchingType":"exact","sort":0,"enabled":true,"expertMode":false,"id":7,"modified":"2026-10-16T12:00:00+0200"}', (string) $this->http->lastRequest()->getBody());
    }

    public function testDeletesWithIdAndVersionInTheBody(): void
    {
        $this->http->queue(new Response(200, [], self::WRITTEN), new Response(200, [], '{"error":false,"violationList":[],"targetObject":[]}'));
        $created = $this->redirects->create(new Redirect('www.example.com', '/old', 'https://www.example.com/new'));

        $this->redirects->delete($created);

        self::assertSame('DELETE https://api.myracloud.com/en/rapi/redirects/www.example.com', $this->sent()[1]);
        self::assertSame('{"id":7,"modified":"2026-10-16T12:00:00+0200"}', (string) $this->http->lastRequest()->getBody());
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

    /**
     * @return list<string>
     */
    private function sent(): array
    {
        return array_map(static fn($r): string => $r->getMethod() . ' ' . $r->getUri(), $this->http->requests());
    }
}
