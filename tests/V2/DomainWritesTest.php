<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\V2;

use GuzzleHttp\Psr7\Response;
use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Model\Domain;
use Myracloud\WebApi\Tests\Support\FakeHttpClient;
use Myracloud\WebApi\V2;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Domain writes as the manual / spec describe them (unverified, never probed).
 */
#[CoversClass(V2\Domains::class)]
#[CoversClass(Domain::class)]
#[UsesClass(V2\Client::class)]
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
#[UsesClass(V2\SslCertificates::class)]
#[UsesClass(V2\Zones::class)]
#[UsesClass(V2\MaintenanceTemplates::class)]
#[UsesClass(V2\Subdomains::class)]
#[UsesClass(V2\DomainVerifications::class)]
#[UsesClass(V2\Waf::class)]
#[UsesClass(V2\Users::class)]
#[UsesClass(V2\Organization::class)]
#[UsesClass(V2\DomainIdResolver::class)]
#[UsesClass(\Myracloud\WebApi\Auth\BearerToken::class)]
#[UsesClass(\Myracloud\WebApi\Http\Transport::class)]
#[UsesClass(\Myracloud\WebApi\Http\Envelope::class)]
#[UsesClass(\Myracloud\WebApi\Http\JsonObject::class)]
#[UsesClass(\Myracloud\WebApi\Model\Persisted::class)]
#[UsesClass(InvalidArgumentException::class)]
final class DomainWritesTest extends TestCase
{
    private const BASE = 'https://apiv2.myracloud.com/';

    private FakeHttpClient $http;
    private V2\Domains $domains;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->domains = (new V2\Client(new BearerToken('t'), httpClient: $this->http))->domains();
    }

    private static function answer(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], '{"error":false,"data":[{"objectType":"DomainVO","id":3,"name":"example.com","modified":"2013-12-13T11:30:00+0100"}]}');
    }

    /**
     * @return list<array{string, string}>
     */
    private function sent(): array
    {
        return array_map(static fn($r): array => [$r->getMethod() . ' ' . $r->getUri(), (string) $r->getBody()], $this->http->requests());
    }

    private static function read(): Domain
    {
        return new Domain(3, 'example.com', ['id' => 3, 'name' => 'example.com', 'modified' => '2013-12-09T11:30:00+0100']);
    }

    public function testAnUpdateNeedsTheVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            $this->domains->setAutoUpdate(new Domain(3, 'example.com'), true);
        } finally {
            self::assertSame([], $this->http->requests());
        }
    }

    public function testCreatesUpdatesAndDeletesAsInTheSpec(): void
    {
        $this->http->queue(self::answer(), self::answer(), self::answer(), new Response(204));

        $created = $this->domains->create('example.com', autoDns: true);
        $this->domains->setAutoUpdate(self::read(), false);
        $this->domains->setEnvironment(self::read(), 'test');
        $this->domains->delete(self::read());

        self::assertSame(3, $created->id());
        self::assertSame([
            ['POST ' . self::BASE . 'domains', '{"name":"example.com","autoDns":true}'],
            ['PUT ' . self::BASE . 'domains/3', '{"id":3,"modified":"2013-12-09T11:30:00+0100","autoUpdate":false}'],
            ['PUT ' . self::BASE . 'domains/3', '{"id":3,"modified":"2013-12-09T11:30:00+0100","environment":"test"}'],
            ['DELETE ' . self::BASE . 'domains/3', ''],
        ], $this->sent());
    }
}
