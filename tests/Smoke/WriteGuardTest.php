<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\Smoke;

use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(WriteGuard::class)]
final class WriteGuardTest extends TestCase
{
    #[TestWith(['PUT', 'https://api.myracloud.com/en/rapi/redirects/www.example.org'])]
    #[TestWith(['DELETE', 'https://api.myracloud.com/de/rapi/redirects/www.example.org'])]
    #[TestWith(['POST', 'https://apiv2.myracloud.com/domain/1140/redirects/www.example.org'])]
    #[TestWith(['DELETE', 'https://apiv2.myracloud.com/domain/1140/redirects/www.example.org/5'])]
    public function testAllowsTheProbedArea(string $method, string $uri): void
    {
        self::assertTrue(self::guard()->allows(new Request($method, $uri)));
    }

    #[TestWith(['PUT', 'https://api.myracloud.com/en/rapi/redirects/www.example.com'], 'another vhost')]
    #[TestWith(['PUT', 'https://api.myracloud.com/en/rapi/redirects/example.org'], 'the apex')]
    #[TestWith(['POST', 'https://apiv2.myracloud.com/domain/1000/redirects/www.example.org'], 'another domain id')]
    #[TestWith(['DELETE', 'https://apiv2.myracloud.com/domain/1140/redirects/www.example.org.evil/5'], 'a longer host')]
    #[TestWith(['DELETE', 'https://apiv2.myracloud.com/domain/1140/redirects/www.example.org/5/x'], 'a deeper path')]
    #[TestWith(['PUT', 'https://api.myracloud.com/en/rapi/cacheClear/www.example.org'], 'another area')]
    #[TestWith(['PUT', 'https://api.myracloud.com/en/rapi/redirectsX/www.example.org'], 'a similar area')]
    #[TestWith(['DELETE', 'https://apiv2.myracloud.com/domains/1140'], 'the domain itself')]
    public function testRefusesEverythingElse(string $method, string $uri): void
    {
        self::assertFalse(self::guard()->allows(new Request($method, $uri)));
    }

    public function testAllowsOnlyTheListedActions(): void
    {
        $guard = new WriteGuard('www.example.org', 1140, ['ip-filters'], ['import']);

        self::assertTrue($guard->allows(new Request('POST', 'https://apiv2.myracloud.com/domain/1140/ip-filters/www.example.org/import?type=BLACKLIST')));
        self::assertFalse($guard->allows(new Request('POST', 'https://apiv2.myracloud.com/domain/1140/ip-filters/www.example.org/export')));
        self::assertFalse(self::guard()->allows(new Request('POST', 'https://apiv2.myracloud.com/domain/1140/redirects/www.example.org/import')));
    }

    public function testAllowsTheV2PathWithTheVhostFirst(): void
    {
        $guard = new WriteGuard('www.example.org', 1140, ['cache-settings']);

        self::assertTrue($guard->allows(new Request('POST', 'https://apiv2.myracloud.com/domain/1140/www.example.org/cache-settings')));
        self::assertTrue($guard->allows(new Request('DELETE', 'https://apiv2.myracloud.com/domain/1140/www.example.org/cache-settings/5')));
        self::assertFalse($guard->allows(new Request('POST', 'https://apiv2.myracloud.com/domain/1140/www.example.com/cache-settings')));
        self::assertFalse($guard->allows(new Request('POST', 'https://apiv2.myracloud.com/domain/1140/www.example.org/settings')));
    }

    public function testAllowsDomainAreasOfTheDomainOnly(): void
    {
        $guard = new WriteGuard('www.example.org', 1140, ['redirects'], [], 'example.org', ['dnsRecords', 'dns-records']);

        self::assertTrue($guard->allows(new Request('PUT', 'https://api.myracloud.com/en/rapi/dnsRecords/example.org')));
        self::assertTrue($guard->allows(new Request('POST', 'https://apiv2.myracloud.com/domain/1140/dns-records')));
        self::assertTrue($guard->allows(new Request('DELETE', 'https://apiv2.myracloud.com/domain/1140/dns-records/5')));
        self::assertTrue($guard->allows(new Request('PUT', 'https://apiv2.myracloud.com/domain/1140/dns-records/5/load-balancing')));
        self::assertFalse($guard->allows(new Request('PUT', 'https://api.myracloud.com/en/rapi/dnsRecords/example.com')));
        self::assertFalse($guard->allows(new Request('POST', 'https://apiv2.myracloud.com/domain/1000/dns-records')));
        self::assertFalse($guard->allows(new Request('PUT', 'https://apiv2.myracloud.com/domain/1140/dns-records/5/other')));
        self::assertFalse((new WriteGuard('www.example.org', 1140, ['redirects']))->allows(new Request('POST', 'https://apiv2.myracloud.com/domain/1140/dns-records')));
    }

    public function testAVhostNameIsNoPattern(): void
    {
        $guard = new WriteGuard('www.example.org', 1140, ['redirects']);

        self::assertFalse($guard->allows(new Request('PUT', 'https://api.myracloud.com/en/rapi/redirects/wwwXexampleXorg')));
    }

    private static function guard(): WriteGuard
    {
        return new WriteGuard('www.example.org', 1140, ['redirects']);
    }
}
