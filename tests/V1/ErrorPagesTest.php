<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\V1;

use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Model\ErrorPage;
use Myracloud\WebApi\Tests\Support\FakeHttpClient;
use Myracloud\WebApi\Tests\Support\Fixture;
use Myracloud\WebApi\V1;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * As captured with the probe (an error page for code 413, free on the vhost).
 */
#[CoversClass(V1\ErrorPages::class)]
#[UsesClass(V1\IpRanges::class)]
#[UsesClass(V1\Statistics::class)]
#[UsesClass(V1\Client::class)]
#[UsesClass(V1\Domains::class)]
#[UsesClass(V1\DnsRecords::class)]
#[UsesClass(V1\CacheClearing::class)]
#[UsesClass(V1\Redirects::class)]
#[UsesClass(V1\IpFilters::class)]
#[UsesClass(V1\CacheSettings::class)]
#[UsesClass(V1\SubdomainSettings::class)]
#[UsesClass(V1\Maintenances::class)]
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
    private V1\ErrorPages $pages;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->pages = (new V1\Client(new BearerToken('t'), httpClient: $this->http))->errorPages();
    }

    public function testListsThePagesOfADomain(): void
    {
        $this->http->queue(Fixture::response('v1/errorpages/list'));

        $pages = $this->pages->list('example.com');

        self::assertSame(['GET https://api.myracloud.com/en/rapi/errorpages/example.com/1'], $this->sent());
        self::assertSame(
            [['example.com', 502], ['example.com', 504], ['www.example.com', 502], ['www.example.com', 504]],
            array_map(static fn(ErrorPage $p): array => [$p->subdomain(), $p->errorCode()], $pages),
        );
        self::assertSame('', $pages[0]->content());
    }

    public function testGetsOneWithItsContent(): void
    {
        $this->http->queue(Fixture::response('v1/errorpages/get'));

        $page = $this->pages->get('example.org', 90001);

        self::assertSame('GET https://api.myracloud.com/en/rapi/errorpage/90001', $this->sent()[0]);
        self::assertSame(90001, $page->id());
        self::assertNotSame('', $page->content());
    }

    public function testSetsReplacesAndDeletesAsCaptured(): void
    {
        $this->http->queue(Fixture::response('v1/errorpages/set'), Fixture::response('v1/errorpages/replace'), Fixture::response('v1/errorpages/delete'));

        $this->pages->set('example.org', self::sentHtml('v1/errorpages/set'), ['map.example.org' => [413]]);
        self::assertSame(Fixture::requestBody('v1/errorpages/set'), (string) $this->http->lastRequest()->getBody());
        $this->pages->set('example.org', self::sentHtml('v1/errorpages/replace'), ['map.example.org' => [413]]);
        self::assertSame(Fixture::requestBody('v1/errorpages/replace'), (string) $this->http->lastRequest()->getBody());
        $this->pages->delete('example.org', ['map.example.org' => [413]]);
        self::assertSame(Fixture::requestBody('v1/errorpages/delete'), (string) $this->http->lastRequest()->getBody());

        self::assertSame(['POST https://api.myracloud.com/en/rapi/errorpages/example.org', 'POST https://api.myracloud.com/en/rapi/errorpages/example.org', 'DELETE https://api.myracloud.com/en/rapi/errorpages/example.org'], array_slice($this->sent(), 0));
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
        self::assertSame([], $this->sent());
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
