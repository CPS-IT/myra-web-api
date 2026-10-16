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
