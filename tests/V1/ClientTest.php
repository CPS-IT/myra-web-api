<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\V1;

use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Exception\AccessDeniedException;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Tests\Support\FakeHttpClient;
use Myracloud\WebApi\Tests\Support\Fixture;
use Myracloud\WebApi\V1;
use Myracloud\WebApi\V1\Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(V1\Client::class)]
#[CoversClass(V1\Domains::class)]
#[CoversClass(V1\DnsRecords::class)]
#[CoversClass(V1\CacheClearing::class)]
#[UsesClass(V1\Redirects::class)]
#[UsesClass(V1\IpFilters::class)]
#[UsesClass(V1\CacheSettings::class)]
#[UsesClass(V1\SubdomainSettings::class)]
#[UsesClass(V1\Maintenances::class)]
#[UsesClass(V1\ErrorPages::class)]
#[UsesClass(V1\IpRanges::class)]
#[UsesClass(V1\Statistics::class)]
#[UsesClass(V1\SslCertificates::class)]
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

    public function testListsDnsRecordsByDomainName(): void
    {
        $this->http->queue(Fixture::response('v1/dns-records/list'));

        $records = $this->client()->dnsRecords()->list('Example.COM.');

        self::assertCount(6, $records);
        self::assertSame(['GET https://api.myracloud.com/en/rapi/dnsRecords/example.com/1'], $this->sent());
    }

    public function testFollowsThePagesOfDnsRecords(): void
    {
        $record = static fn(int $id): array => ['id' => $id, 'name' => "h$id.example.com", 'recordType' => 'A', 'value' => '192.0.2.1', 'ttl' => 300, 'active' => true, 'enabled' => true];
        $this->http->queue(
            Fixture::page('list', [$record(1), $record(2)], 1, 2, 3),
            Fixture::page('list', [$record(3)], 2, 2, 3),
        );

        $records = $this->client()->dnsRecords()->list('example.com');

        self::assertSame([1, 2, 3], array_map(static fn($r): ?int => $r->id(), $records));
        self::assertSame([
            'GET https://api.myracloud.com/en/rapi/dnsRecords/example.com/1',
            'GET https://api.myracloud.com/en/rapi/dnsRecords/example.com/2',
        ], $this->sent());
    }

    public function testReadsOnePageOfDnsRecords(): void
    {
        $this->http->queue(Fixture::response('v1/dns-records/list'));

        $page = $this->client()->dnsRecords()->page('example.com', 3);

        self::assertSame(6, $page->total());
        self::assertSame(['GET https://api.myracloud.com/en/rapi/dnsRecords/example.com/3'], $this->sent());
    }

    public function testListsDomains(): void
    {
        $this->http->queue(Fixture::response('v1/domains/list'));

        $domains = $this->client()->domains()->list();

        self::assertSame(['example.com', 'example.org'], array_map(static fn($d): string => $d->name(), $domains));
        self::assertSame(['GET https://api.myracloud.com/en/rapi/domains/1'], $this->sent());
    }

    public function testReadsOnePageOfDomains(): void
    {
        $this->http->queue(Fixture::response('v1/domains/list'));

        $this->client()->domains()->page(2);

        self::assertSame(['GET https://api.myracloud.com/en/rapi/domains/2'], $this->sent());
    }

    public function testClearsTheCacheOfAResource(): void
    {
        $this->http->queue(Fixture::response('v1/cache-clear/single'));

        $clears = $this->client()->cacheClear()->clear('example.com', 'example.com', '/__probe__/nothing.txt');

        self::assertSame(['PUT https://api.myracloud.com/en/rapi/cacheClear/example.com'], $this->sent());
        self::assertSame(Fixture::requestBody('v1/cache-clear/single'), (string) $this->http->lastRequest()->getBody());
        self::assertCount(1, $clears);
        self::assertSame('/__probe__/nothing.txt', $clears[0]->resource());
    }

    public function testClearsTheCacheOfSeveralResources(): void
    {
        $this->http->queue(Fixture::response('v1/cache-clear/bulk'));

        $clears = $this->client()->cacheClear()->clearBulk('example.com', 'example.com', ['/__probe__/a.txt', '/__probe__/b.txt']);

        self::assertSame(['PUT https://api.myracloud.com/en/rapi/cacheClear/example.com'], $this->sent());
        self::assertSame(Fixture::requestBody('v1/cache-clear/bulk'), (string) $this->http->lastRequest()->getBody());
        self::assertSame(['/__probe__/a.txt', '/__probe__/b.txt'], $clears[0]->bulk());
    }

    public function testAnUnknownDomainLooksLikeWrongCredentials(): void
    {
        $this->http->queue(Fixture::response('v1/errors/unknown-domain'));

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('the credentials are wrong, or the domain does not exist');

        $this->client()->dnsRecords()->list('does-not-exist.invalid');
    }

    public function testSpeaksGerman(): void
    {
        $this->http->queue(Fixture::response('v1/domains/list'));

        (new Client(new BearerToken('t'), 'de', httpClient: $this->http))->domains()->page(1);

        self::assertSame(['GET https://api.myracloud.com/de/rapi/domains/1'], $this->sent());
    }

    public function testRejectsOtherLanguages(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('API v1 speaks "en" or "de", "fr" given.');

        new Client(new BearerToken('t'), 'fr');
    }

    public function testTalksToAnotherBaseUri(): void
    {
        $this->http->queue(Fixture::response('v1/domains/list'));

        (new Client(new BearerToken('t'), baseUri: 'http://localhost:8080/', httpClient: $this->http))->domains()->page(1);

        self::assertSame(['GET http://localhost:8080/en/rapi/domains/1'], $this->sent());
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
        self::assertSame($client->redirects(), $client->redirects());
        self::assertSame($client->ipFilters(), $client->ipFilters());
        self::assertSame($client->cacheSettings(), $client->cacheSettings());
        self::assertSame($client->subdomainSettings(), $client->subdomainSettings());
        self::assertSame($client->maintenances(), $client->maintenances());
        self::assertSame($client->errorPages(), $client->errorPages());
        self::assertSame($client->ipRanges(), $client->ipRanges());
        self::assertSame($client->statistics(), $client->statistics());
        self::assertSame($client->sslCertificates(), $client->sslCertificates());
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
