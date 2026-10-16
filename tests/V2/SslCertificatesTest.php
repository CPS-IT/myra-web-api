<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\V2;

use GuzzleHttp\Psr7\Response;
use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Model\SslCertificate;
use Myracloud\WebApi\Model\SslCertificateUpload;
use Myracloud\WebApi\Tests\Support\FakeHttpClient;
use Myracloud\WebApi\Tests\Support\Fixture;
use Myracloud\WebApi\V2;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Reads as captured; writes as in the manual / spec (unverified).
 */
#[CoversClass(V2\SslCertificates::class)]
#[UsesClass(V2\Client::class)]
#[UsesClass(V2\Domains::class)]
#[UsesClass(V2\DnsRecords::class)]
#[UsesClass(V2\CacheClearing::class)]
#[UsesClass(V2\Redirects::class)]
#[UsesClass(V2\IpFilters::class)]
#[UsesClass(V2\CacheSettings::class)]
#[UsesClass(V2\SubdomainSettings::class)]
#[UsesClass(V2\Maintenances::class)]
#[UsesClass(V2\ErrorPages::class)]
#[UsesClass(V2\IpRanges::class)]
#[UsesClass(V2\Statistics::class)]
#[UsesClass(V2\MaintenanceTemplates::class)]
#[UsesClass(V2\DomainIdResolver::class)]
#[UsesClass(V2\Subdomains::class)]
#[UsesClass(V2\DomainVerifications::class)]
#[UsesClass(V2\Waf::class)]
#[UsesClass(V2\Users::class)]
#[UsesClass(V2\Organization::class)]
#[UsesClass(\Myracloud\WebApi\Model\Domain::class)]
#[UsesClass(\Myracloud\WebApi\Model\SslConfiguration::class)]
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
    private V2\SslCertificates $certificates;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->certificates = (new V2\Client(new BearerToken('t'), httpClient: $this->http))->sslCertificates();
    }

    public function testListsPagesAndGets(): void
    {
        $this->http->queue(Fixture::response('v2/domains/list'), Fixture::response('v2/certificates/list'), Fixture::response('v2/certificates/page-2'), Fixture::response('v2/certificates/get'));

        self::assertCount(4, $this->certificates->list('example.com'));
        self::assertSame([90003, 90004], array_map(static fn(SslCertificate $c): int => $c->id(), $this->certificates->page('example.com', 2)->items()));
        self::assertSame(90001, $this->certificates->get('example.com', 90001)->id());
        self::assertSame([
            'GET https://apiv2.myracloud.com/domains',
            'GET https://apiv2.myracloud.com/domain/1000/ssl/certificates',
            'GET https://apiv2.myracloud.com/domain/1000/ssl/certificates?page=2',
            'GET https://apiv2.myracloud.com/domain/1000/ssl/certificates/90001',
        ], $this->sent());
    }

    public function testWritesWithoutSslInThePath(): void
    {
        $this->http->queue(Fixture::response('v2/domains/list'), self::answer(201), self::answer(200), new Response(204));
        $upload = new SslCertificateUpload('CERT', 'KEY', ['www.example.com'], ['CHAIN']);

        $created = $this->certificates->upload('example.com', $upload);
        $updated = $this->certificates->update('example.com', $created->withSubdomains(['www.example.com', 'example.com']));
        $this->certificates->delete('example.com', $updated);

        self::assertSame([
            'GET https://apiv2.myracloud.com/domains',
            'POST https://apiv2.myracloud.com/domain/1000/certificates',
            'PUT https://apiv2.myracloud.com/domain/1000/certificates/3',
            'DELETE https://apiv2.myracloud.com/domain/1000/certificates/3',
        ], $this->sent());
        $bodies = array_map(static fn($r): string => (string) $r->getBody(), $this->http->requests());
        self::assertSame((string) json_encode($upload->toJson(), JSON_UNESCAPED_SLASHES), $bodies[1]);
        self::assertSame('{"id":3,"modified":"2016-04-12T15:03:23+0200","subdomains":["www.example.com","example.com"]}', $bodies[2]);
        self::assertSame('', $bodies[3]);
    }

    public function testListsMyrasTlsPresets(): void
    {
        $this->http->queue(Fixture::response('v2/certificates/configurations'));

        $configurations = $this->certificates->configurations();

        self::assertSame(['GET https://apiv2.myracloud.com/ssl-configurations'], $this->sent());
        self::assertCount(4, $configurations);
    }

    private static function answer(int $status): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], '{"error":false,"data":[{"objectType":"SslCertVO","id":3,"modified":"2016-04-12T15:03:23+0200","subdomains":["www.example.com"]}]}');
    }

    /**
     * @return list<string>
     */
    private function sent(): array
    {
        return array_map(static fn($r): string => $r->getMethod() . ' ' . $r->getUri(), $this->http->requests());
    }
}
