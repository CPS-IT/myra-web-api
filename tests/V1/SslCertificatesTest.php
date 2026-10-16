<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\V1;

use GuzzleHttp\Psr7\Response;
use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Model\SslCertificate;
use Myracloud\WebApi\Model\SslCertificateUpload;
use Myracloud\WebApi\Tests\Support\FakeHttpClient;
use Myracloud\WebApi\Tests\Support\Fixture;
use Myracloud\WebApi\V1;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Reads as captured; writes as in the manual / spec (unverified).
 */
#[CoversClass(V1\SslCertificates::class)]
#[UsesClass(V1\Zones::class)]
#[UsesClass(V1\Client::class)]
#[UsesClass(V1\Domains::class)]
#[UsesClass(V1\DnsRecords::class)]
#[UsesClass(V1\CacheClearing::class)]
#[UsesClass(V1\Redirects::class)]
#[UsesClass(V1\IpFilters::class)]
#[UsesClass(V1\CacheSettings::class)]
#[UsesClass(V1\SubdomainSettings::class)]
#[UsesClass(V1\Maintenances::class)]
#[UsesClass(V1\ErrorPages::class)]
#[UsesClass(V1\IpRanges::class)]
#[UsesClass(V1\Statistics::class)]
#[UsesClass(\Myracloud\WebApi\Auth\BearerToken::class)]
#[UsesClass(\Myracloud\WebApi\Http\Transport::class)]
#[UsesClass(\Myracloud\WebApi\Http\Envelope::class)]
#[UsesClass(\Myracloud\WebApi\Http\JsonObject::class)]
#[UsesClass(\Myracloud\WebApi\Model\Page::class)]
#[UsesClass(\Myracloud\WebApi\Model\DomainName::class)]
#[UsesClass(SslCertificate::class)]
#[UsesClass(SslCertificateUpload::class)]
final class SslCertificatesTest extends TestCase
{
    private FakeHttpClient $http;
    private V1\SslCertificates $certificates;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->certificates = (new V1\Client(new BearerToken('t'), httpClient: $this->http))->sslCertificates();
    }

    public function testListsAndPagesPerDomain(): void
    {
        $this->http->queue(Fixture::response('v1/certificates/list'), Fixture::response('v1/certificates/list-empty'));

        self::assertCount(4, $this->certificates->list('example.com'));
        self::assertSame([], $this->certificates->page('example.org', 2)->items());
        self::assertSame(['GET https://api.myracloud.com/en/rapi/certificates/example.com/1', 'GET https://api.myracloud.com/en/rapi/certificates/example.org/2'], $this->sent());
    }

    public function testUploadsWithPut(): void
    {
        $this->http->queue(self::answer());

        $created = $this->certificates->upload('example.com', new SslCertificateUpload('CERT', 'KEY', ['www.example.com']));

        self::assertSame(['PUT https://api.myracloud.com/en/rapi/certificates/example.com'], $this->sent());
        self::assertSame('{"objectType":"SslCertVO","cert":"CERT","key":"KEY","subdomains":["www.example.com"],"intermediates":[]}', (string) $this->http->lastRequest()->getBody());
        self::assertSame(3, $created->id());
    }

    public function testUpdatesWithPost(): void
    {
        $this->http->queue(self::answer());

        $this->certificates->update('example.com', new SslCertificate(3, '2013-12-09T11:30:00+0100', ['www3.example.com']));

        self::assertSame(['POST https://api.myracloud.com/en/rapi/certificates/example.com'], $this->sent());
        self::assertSame('{"id":3,"modified":"2013-12-09T11:30:00+0100","subdomains":["www3.example.com"]}', (string) $this->http->lastRequest()->getBody());
    }

    /**
     * The manual's answer to a write: the certificate in targetObject.
     */
    private static function answer(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], '{"error":false,"targetObject":[{"objectType":"SslCertVO","id":3,"modified":"2013-12-09T11:30:00+0100","subdomains":["www3.example.com"]}]}');
    }

    /**
     * @return list<string>
     */
    private function sent(): array
    {
        return array_map(static fn($r): string => $r->getMethod() . ' ' . $r->getUri(), $this->http->requests());
    }
}
