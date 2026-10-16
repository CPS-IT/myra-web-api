<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\V1;

use DateTimeImmutable;
use GuzzleHttp\Psr7\Response;
use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Model\Domain;
use Myracloud\WebApi\Tests\Support\FakeHttpClient;
use Myracloud\WebApi\V1;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Domain writes as the manual / spec describe them (unverified, never probed).
 */
#[CoversClass(V1\Domains::class)]
#[CoversClass(Domain::class)]
#[UsesClass(V1\Client::class)]
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
#[UsesClass(V1\SslCertificates::class)]
#[UsesClass(V1\Zones::class)]
#[UsesClass(\Myracloud\WebApi\Auth\BearerToken::class)]
#[UsesClass(\Myracloud\WebApi\Http\Transport::class)]
#[UsesClass(\Myracloud\WebApi\Http\Envelope::class)]
#[UsesClass(\Myracloud\WebApi\Http\JsonObject::class)]
#[UsesClass(\Myracloud\WebApi\Model\Persisted::class)]
#[UsesClass(InvalidArgumentException::class)]
final class DomainWritesTest extends TestCase
{
    private const BASE = 'https://api.myracloud.com/en/rapi/';

    private FakeHttpClient $http;
    private V1\Domains $domains;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->domains = (new V1\Client(new BearerToken('t'), httpClient: $this->http))->domains();
    }

    private static function answer(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], '{"error":false,"targetObject":[{"objectType":"DomainVO","id":3,"name":"example.com","modified":"2013-12-13T11:30:00+0100"}]}');
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

    public function testCreatesUpdatesPausesAndDeletesAsInTheManual(): void
    {
        $this->http->queue(self::answer(), self::answer(), self::answer(), self::answer(), self::answer(), self::answer());

        $created = $this->domains->create('example.com', false, false);
        $this->domains->create('example.org');
        $this->domains->setAutoUpdate(self::read(), true);
        $this->domains->pause(self::read(), new DateTimeImmutable('2024-09-28T15:00:00+02:00'));
        $this->domains->resume(self::read());
        $this->domains->delete(self::read());

        self::assertSame([3, '2013-12-13T11:30:00+0100'], [$created->id(), $created->modified()]);
        self::assertSame([
            ['PUT ' . self::BASE . 'domains', '{"name":"example.com","autoUpdate":false,"autoDns":false}'],
            ['PUT ' . self::BASE . 'domains', '{"name":"example.org"}'],
            ['POST ' . self::BASE . 'domains', '{"id":3,"modified":"2013-12-09T11:30:00+0100","autoUpdate":true}'],
            ['POST ' . self::BASE . 'domains', '{"id":3,"modified":"2013-12-09T11:30:00+0100","paused":true,"pausedUntil":"2024-09-28T15:00:00+02:00"}'],
            ['POST ' . self::BASE . 'domains', '{"id":3,"modified":"2013-12-09T11:30:00+0100","paused":false}'],
            ['DELETE ' . self::BASE . 'domains', '{"id":3}'],
        ], $this->sent());
    }

    public function testPausesWithoutEnd(): void
    {
        $this->http->queue(self::answer());

        $this->domains->pause(self::read());

        self::assertSame('{"id":3,"modified":"2013-12-09T11:30:00+0100","paused":true}', $this->sent()[0][1]);
    }
}
