<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\V2;

use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Model\ErrorPage;
use Myracloud\WebApi\Tests\Support\FakeHttpClient;
use Myracloud\WebApi\Tests\Support\Fixture;
use Myracloud\WebApi\V2;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * As captured with the probe (an error page for code 413, free on the vhost).
 */
#[CoversClass(V2\ErrorPages::class)]
#[UsesClass(V2\Client::class)]
#[UsesClass(V2\Domains::class)]
#[UsesClass(V2\DnsRecords::class)]
#[UsesClass(V2\CacheClearing::class)]
#[UsesClass(V2\Redirects::class)]
#[UsesClass(V2\IpFilters::class)]
#[UsesClass(V2\CacheSettings::class)]
#[UsesClass(V2\SubdomainSettings::class)]
#[UsesClass(V2\Maintenances::class)]
#[UsesClass(V2\MaintenanceTemplates::class)]
#[UsesClass(V2\DomainIdResolver::class)]
#[UsesClass(\Myracloud\WebApi\Auth\BearerToken::class)]
#[UsesClass(\Myracloud\WebApi\Http\Transport::class)]
#[UsesClass(\Myracloud\WebApi\Http\Envelope::class)]
#[UsesClass(\Myracloud\WebApi\Http\JsonObject::class)]
#[UsesClass(\Myracloud\WebApi\Model\Page::class)]
#[UsesClass(\Myracloud\WebApi\Model\ErrorPage::class)]
#[UsesClass(\Myracloud\WebApi\Model\ErrorPageSelection::class)]
#[UsesClass(\Myracloud\WebApi\Model\DomainName::class)]
#[UsesClass(\Myracloud\WebApi\Model\Domain::class)]
final class ErrorPagesTest extends TestCase
{
    private FakeHttpClient $http;
    private V2\ErrorPages $pages;

    protected function setUp(): void
    {
        $this->http = (new FakeHttpClient())->queue(Fixture::response('v2/domains/list'));
        $this->pages = (new V2\Client(new BearerToken('t'), httpClient: $this->http))->errorPages();
    }

    public function testListsThePagesOfADomain(): void
    {
        $this->http->queue(Fixture::response('v2/errorpages/list'));

        $pages = $this->pages->list('example.com');

        self::assertSame(['GET https://apiv2.myracloud.com/domains', 'GET https://apiv2.myracloud.com/domain/1000/errorpages'], $this->sent());
        self::assertSame(
            [['example.com', 502], ['example.com', 504], ['www.example.com', 502], ['www.example.com', 504]],
            array_map(static fn(ErrorPage $p): array => [$p->subdomain(), $p->errorCode()], $pages),
        );
        self::assertSame('', $pages[0]->content());
    }

    /**
     * Captured: unpaged (page 0, pageSize 10^16) — one request, not an endless loop.
     */
    public function testAnUnpagedListCostsOneRequest(): void
    {
        $this->http->queue(Fixture::response('v2/errorpages/list'));

        self::assertCount(4, $this->pages->list('example.com'));
        self::assertCount(2, $this->http->requests());
    }

    public function testGetsOneWithItsContent(): void
    {
        $this->http->queue(Fixture::response('v2/errorpages/get'));

        $page = $this->pages->get('example.com', 90400);

        self::assertSame('GET https://apiv2.myracloud.com/domain/1000/errorpages/90400', $this->sent()[1]);
        self::assertSame(90400, $page->id());
        self::assertNotSame('', $page->content());
    }

    public function testGroupsBySubdomain(): void
    {
        $this->http->queue(Fixture::response('v2/errorpages/grouped'));

        $grouped = $this->pages->grouped('example.com');

        self::assertSame('GET https://apiv2.myracloud.com/domain/1000/errorpages-grouped', $this->sent()[1]);
        self::assertSame(['example.com', 'www.example.com'], array_keys($grouped));
        self::assertSame([502, 504], array_map(static fn(ErrorPage $p): int => $p->errorCode(), $grouped['example.com']));
    }

    public function testSetsReplacesAndDeletesAsCaptured(): void
    {
        $this->http->queue(Fixture::response('v2/errorpages/set'), Fixture::response('v2/errorpages/replace'), Fixture::response('v2/errorpages/delete'));

        $this->pages->set('example.org', self::sentHtml('v2/errorpages/set'), ['map.example.org' => [413]]);
        self::assertSame(Fixture::requestBody('v2/errorpages/set'), (string) $this->http->lastRequest()->getBody());
        $this->pages->set('example.org', self::sentHtml('v2/errorpages/replace'), ['map.example.org' => [413]]);
        self::assertSame(Fixture::requestBody('v2/errorpages/replace'), (string) $this->http->lastRequest()->getBody());
        $this->pages->delete('example.org', ['map.example.org' => [413]]);
        self::assertSame(Fixture::requestBody('v2/errorpages/delete'), (string) $this->http->lastRequest()->getBody());

        self::assertSame(['POST https://apiv2.myracloud.com/domain/1140/errorpages', 'POST https://apiv2.myracloud.com/domain/1140/errorpages', 'DELETE https://apiv2.myracloud.com/domain/1140/errorpages'], array_slice($this->sent(), 1));
    }

    public function testChecksTheSelectionBeforeSending(): void
    {
        foreach ([fn() => $this->pages->set('example.org', '<html/>', ['map.example.org' => [418]]), fn() => $this->pages->delete('example.org', [])] as $write) {
            try {
                $write();
                self::fail('No exception thrown.');
            } catch (InvalidArgumentException) {
            }
        }
        self::assertSame(['GET https://apiv2.myracloud.com/domains'], $this->sent());
    }

    private static function sentHtml(string $fixture): string
    {
        $body = Fixture::load($fixture)['request']['body'];
        self::assertIsArray($body);
        self::assertIsString($body['pageContent']);

        return $body['pageContent'];
    }

    /**
     * @return list<string>
     */
    private function sent(): array
    {
        return array_map(static fn($r): string => $r->getMethod() . ' ' . $r->getUri(), $this->http->requests());
    }
}
