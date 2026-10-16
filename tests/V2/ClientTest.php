<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\V2;

use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Exception\AccessDeniedException;
use Myracloud\WebApi\Exception\DomainNotFoundException;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Exception\RequestRejectedException;
use Myracloud\WebApi\Tests\Support\FakeHttpClient;
use Myracloud\WebApi\Tests\Support\Fixture;
use Myracloud\WebApi\V2;
use Myracloud\WebApi\V2\Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(V2\Client::class)]
#[CoversClass(V2\Domains::class)]
#[CoversClass(V2\DnsRecords::class)]
#[CoversClass(V2\CacheClearing::class)]
#[UsesClass(V2\Redirects::class)]
#[UsesClass(V2\IpFilters::class)]
#[UsesClass(V2\CacheSettings::class)]
#[UsesClass(V2\SubdomainSettings::class)]
#[CoversClass(V2\DomainIdResolver::class)]
#[UsesClass(\Myracloud\WebApi\Auth\BearerToken::class)]
#[UsesClass(\Myracloud\WebApi\Auth\HmacSignature::class)]
#[UsesClass(\Myracloud\WebApi\Http\Transport::class)]
#[UsesClass(\Myracloud\WebApi\Http\Envelope::class)]
#[UsesClass(\Myracloud\WebApi\Http\JsonObject::class)]
#[UsesClass(\Myracloud\WebApi\Model\Page::class)]
#[UsesClass(\Myracloud\WebApi\Model\Domain::class)]
#[UsesClass(\Myracloud\WebApi\Model\DnsRecord::class)]
#[UsesClass(\Myracloud\WebApi\Model\UpstreamOptions::class)]
#[UsesClass(\Myracloud\WebApi\Model\CacheClear::class)]
#[UsesClass(\Myracloud\WebApi\Model\CacheClearBody::class)]
#[UsesClass(\Myracloud\WebApi\Model\DomainName::class)]
#[UsesClass(\Myracloud\WebApi\Model\Violation::class)]
#[UsesClass(\Myracloud\WebApi\Exception\RequestRejectedException::class)]
final class ClientTest extends TestCase
{
    private FakeHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
    }

    public function testListsDnsRecordsByDomainNameViaTheId(): void
    {
        $this->http->queue(Fixture::response('v2/domains/list'), Fixture::response('v2/dns-records/list'));

        $records = $this->client()->dnsRecords()->list('Example.COM.');

        self::assertCount(6, $records);
        self::assertSame([
            'GET https://apiv2.myracloud.com/domains',
            'GET https://apiv2.myracloud.com/domain/1000/dns-records',
        ], $this->sent());
    }

    public function testFollowsThePagesOfDnsRecords(): void
    {
        $record = static fn(int $id): array => ['id' => $id, 'name' => "h$id.example.com", 'recordType' => 'A', 'value' => '192.0.2.1', 'ttl' => 300, 'active' => true, 'enabled' => true];
        $this->http->queue(
            Fixture::response('v2/domains/list'),
            Fixture::page('data', [$record(1), $record(2)], 1, 2, 3),
            Fixture::page('data', [$record(3)], 2, 2, 3),
        );

        $records = $this->client()->dnsRecords()->list('example.org');

        self::assertSame([1, 2, 3], array_map(static fn($r): ?int => $r->id(), $records));
        self::assertSame([
            'GET https://apiv2.myracloud.com/domains',
            'GET https://apiv2.myracloud.com/domain/1140/dns-records',
            'GET https://apiv2.myracloud.com/domain/1140/dns-records?page=2',
        ], $this->sent());
    }

    public function testReadsOnePageOfDomains(): void
    {
        $this->http->queue(Fixture::response('v2/domains/page-2'));

        $page = $this->client()->domains()->page(2);

        self::assertSame([], $page->items());
        self::assertSame(2, $page->page());
        self::assertSame(2, $page->total());
        self::assertFalse($page->hasNext());
        self::assertSame(['GET https://apiv2.myracloud.com/domains?page=2'], $this->sent());
    }

    public function testListsDomains(): void
    {
        $this->http->queue(Fixture::response('v2/domains/list'));

        $domains = $this->client()->domains()->list();

        self::assertSame([1000 => 'example.com', 1140 => 'example.org'], array_column(array_map(static fn($d): array => [$d->id(), $d->name()], $domains), 1, 0));
        self::assertSame(['GET https://apiv2.myracloud.com/domains'], $this->sent());
    }

    public function testLooksEachDomainIdUpOnlyOnce(): void
    {
        $this->http->queue(Fixture::response('v2/domains/list'), Fixture::response('v2/dns-records/list'), Fixture::response('v2/cache-clear/single'));
        $client = $this->client();

        $client->dnsRecords()->list('example.com');
        $client->cacheClear()->clear('example.com', 'example.com', '/__probe__/nothing.txt');

        self::assertSame(1000, $client->domainId('EXAMPLE.com'));
        self::assertSame(1140, $client->domainId('example.org.'));
        self::assertCount(3, $this->http->requests());
    }

    public function testReadsTheDomainListOnlyAsFarAsNeeded(): void
    {
        $this->http->queue(
            Fixture::page('data', [Fixture::domain(1, 'one.example')], 1, 1, 3),
            Fixture::page('data', [Fixture::domain(2, 'two.example')], 2, 1, 3),
            Fixture::page('data', [Fixture::domain(3, 'three.example')], 3, 1, 3),
        );
        $client = $this->client();

        self::assertSame(1, $client->domainId('one.example'));
        self::assertCount(1, $this->http->requests());
        self::assertSame(3, $client->domainId('three.example'));
        self::assertSame(2, $client->domainId('two.example'));
        self::assertCount(3, $this->http->requests());
    }

    public function testAnUnknownDomainIsNotFoundAfterAllPagesWereRead(): void
    {
        $this->http->queue(Fixture::response('v2/domains/list'));
        $client = $this->client();

        foreach ([1, 2] as $attempt) {
            try {
                $client->dnsRecords()->list('does-not-exist.invalid');
                self::fail('No exception thrown.');
            } catch (DomainNotFoundException $exception) {
                self::assertSame('The domain "does-not-exist.invalid" is not in the domain list of this account.', $exception->getMessage());
            }
        }
        self::assertSame(['GET https://apiv2.myracloud.com/domains'], $this->sent());
    }

    public function testStopsLookingAtAnEmptyPage(): void
    {
        $this->http->queue(Fixture::page('data', [], 1, 50, 10));

        $this->expectException(DomainNotFoundException::class);

        $this->client()->domainId('example.com');
    }

    public function testClearsTheCacheOfAResource(): void
    {
        $this->http->queue(Fixture::response('v2/domains/list'), Fixture::response('v2/cache-clear/single'));

        $clears = $this->client()->cacheClear()->clear('example.com', 'example.com', '/__probe__/nothing.txt');

        self::assertSame('PUT https://apiv2.myracloud.com/domain/1000/cache-clear', $this->sent()[1]);
        self::assertSame(Fixture::requestBody('v2/cache-clear/single'), (string) $this->http->lastRequest()->getBody());
        self::assertSame('example.com', $clears[0]->fqdn());
    }

    public function testClearsTheCacheOfSeveralResources(): void
    {
        $this->http->queue(Fixture::response('v2/domains/list'), Fixture::response('v2/cache-clear/bulk'));

        $clears = $this->client()->cacheClear()->clearBulk('example.com', 'example.com', ['/__probe__/a.txt', '/__probe__/b.txt']);

        self::assertSame('PUT https://apiv2.myracloud.com/domain/1000/cache-clear', $this->sent()[1]);
        self::assertSame(Fixture::requestBody('v2/cache-clear/bulk'), (string) $this->http->lastRequest()->getBody());
        self::assertSame(['/__probe__/a.txt', '/__probe__/b.txt'], $clears[0]->bulk());
    }

    public function testRejectionsCarryTheirViolations(): void
    {
        $this->http->queue(Fixture::response('v2/domains/list'), Fixture::response('v2/errors/wrong-type'));

        try {
            $this->client()->cacheClear()->clear('example.com', 'example.com', '/__probe__/nothing.txt');
            self::fail('No exception thrown.');
        } catch (RequestRejectedException $exception) {
            self::assertSame('recursive', $exception->violations()[0]->propertyPath());
        }
    }

    public function testWrongCredentialsFailAlreadyAtTheLookup(): void
    {
        $this->http->queue(Fixture::response('v2/errors/wrong-credentials'));

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Access denied for GET https://apiv2.myracloud.com/domains');

        $this->client()->dnsRecords()->list('example.com');
    }

    public function testTalksToAnotherBaseUri(): void
    {
        $this->http->queue(Fixture::response('v2/domains/list'));

        (new Client(new BearerToken('t'), 'http://localhost:8080', $this->http))->domains()->page(1);

        self::assertSame(['GET http://localhost:8080/domains'], $this->sent());
    }

    public function testRejectsPagesBelowOneWithoutARequest(): void
    {
        try {
            $this->client()->dnsRecords()->page('example.com', 0);
            self::fail('No exception thrown.');
        } catch (InvalidArgumentException) {
            self::assertSame([], $this->sent());
        }
    }

    public function testHandsOutTheSameEndpoints(): void
    {
        $client = $this->client();

        self::assertSame($client->domains(), $client->domains());
        self::assertSame($client->dnsRecords(), $client->dnsRecords());
        self::assertSame($client->cacheClear(), $client->cacheClear());
    }

    private function client(): Client
    {
        return new Client(new BearerToken('test-token'), httpClient: $this->http);
    }

    /**
     * @return list<string>
     */
    private function sent(): array
    {
        return array_map(static fn($r): string => $r->getMethod() . ' ' . $r->getUri(), $this->http->requests());
    }
}
