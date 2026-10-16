<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\Auth;

use Exception;
use GuzzleHttp\Psr7\Request;
use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(BearerToken::class)]
final class BearerTokenTest extends TestCase
{
    public function testSetsTheAuthorizationHeader(): void
    {
        $request = (new BearerToken('test-token'))->authenticate(new Request('GET', 'https://apiv2.myracloud.com/domains'));

        self::assertSame('Bearer test-token', $request->getHeaderLine('Authorization'));
    }

    public function testIgnoresWhitespaceAroundTheToken(): void
    {
        $request = (new BearerToken(" test-token\n"))->authenticate(new Request('GET', 'https://apiv2.myracloud.com/domains'));

        self::assertSame('Bearer test-token', $request->getHeaderLine('Authorization'));
    }

    #[TestWith([''])]
    #[TestWith([" \n"])]
    public function testRejectsAnEmptyToken(string $token): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The API token must not be empty.');

        new BearerToken($token);
    }

    public function testDoesNotExposeTheToken(): void
    {
        $token = new BearerToken('test-token');

        self::assertStringNotContainsString('test-token', print_r($token, true));
        self::assertStringNotContainsString('test-token', var_export($token, true));
        self::assertStringNotContainsString('test-token', print_r((array) $token, true));

        $this->expectException(Exception::class);
        serialize($token);
    }
}
