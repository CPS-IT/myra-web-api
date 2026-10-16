<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\Auth;

use DateTimeImmutable;
use Exception;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Utils;
use Myracloud\WebApi\Auth\HmacSignature;
use Myracloud\WebApi\Auth\SystemClock;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Tests\Support\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HmacSignature::class)]
#[UsesClass(SystemClock::class)]
final class HmacSignatureTest extends TestCase
{
    private const BODY = '{"fqdn":"www.example.com","resource":"/*","recursive":true}';

    /**
     * Expected values computed with the shell script of the v2 spec
     * (docs/v2/openapi.json, info.description §2.1.2) and openssl, key "test-key",
     * secret "test-secret" — independent of this implementation.
     *
     * @return iterable<string, array{string, string, string, string, string}>
     */
    public static function specVectors(): iterable
    {
        yield 'v1 GET, empty body' => [
            'GET', 'https://api.myracloud.com/en/rapi/dnsRecords/example.com/1', '', '2026-10-16T12:00:00+02:00',
            'e8xrfitEA1C2I3hL1TotzwutcIDhYIoN6n7Sefg2h+8o+xvUj7QB3W6NfqSSfg2puX8r5c8TJ+5xYIVKGqjscA==',
        ];
        yield 'v2 GET with query string' => [
            'GET', 'https://apiv2.myracloud.com/domains?page=2', '', '2026-10-16T12:00:00+02:00',
            'K5AlS1pF2nOT2udZUXj89NvCPy6E/llq3bCo3lXhpzDP0a77ATaTVJ9Z7dBMCbzUItD8qaAZg5+jUUT4Dgrv7w==',
        ];
        yield 'v1 PUT with JSON body' => [
            'PUT', 'https://api.myracloud.com/en/rapi/cacheClear/example.com', self::BODY, '2026-10-16T12:00:00+02:00',
            '9/YtIGJ9qUyVa/Rvru4k3BuL4BxKePejPhOI6sL2VvDZc91ZhlBGpr2/YJ71niGKMpIq17xhMvQxkY0AVVojTw==',
        ];
        yield 'v2 DELETE, UTC date' => [
            'DELETE', 'https://apiv2.myracloud.com/domain/1000/dns-records/1021', '', '2026-10-16T10:00:00+00:00',
            'nGmedRfN/OPsLJEs8okWmU0oO192jbrQcb1WnnIpM/fKHH1o7mOJNHY+IN7yyOinNf4NPLuABolmC/bVW2Du1w==',
        ];
    }

    #[DataProvider('specVectors')]
    public function testSignsLikeTheSpecScript(string $method, string $uri, string $body, string $date, string $signature): void
    {
        $signed = self::signature($date)->authenticate(new Request($method, $uri, [], $body));

        self::assertSame('MYRA test-key:' . $signature, $signed->getHeaderLine('Authorization'));
        self::assertSame($date, $signed->getHeaderLine('Date'));
        self::assertSame('application/json', $signed->getHeaderLine('Content-Type'));
    }

    public function testSignsTheRequestsOwnContentType(): void
    {
        // vector from the spec script with CONTENT_TYPE="text/csv"
        $request = new Request('POST', 'https://apiv2.myracloud.com/domain/1140/ip-filters/www.example.org/import?type=BLACKLIST', ['Content-Type' => 'text/csv'], '192.0.2.2/32,,__probe__');

        $signed = self::signature()->authenticate($request);

        self::assertSame('MYRA test-key:dEH8ZZgB1wlNrIBbDgUDAe0d7khS24aXAYNb8zU/1zEEqCgyArBbMFGd+3T5uAXL1/EE8F5WhYd4R4wh6fTReA==', $signed->getHeaderLine('Authorization'));
        self::assertSame('text/csv', $signed->getHeaderLine('Content-Type'));
    }

    public function testSignsTheMethodUpperCased(): void
    {
        $lower = self::signature()->authenticate(self::putRequest('put'));
        $upper = self::signature()->authenticate(self::putRequest('PUT'));

        self::assertSame($upper->getHeaderLine('Authorization'), $lower->getHeaderLine('Authorization'));
    }

    public function testIgnoresWhitespaceAroundKeyAndSecret(): void
    {
        $padded = new HmacSignature(" test-key\n", " test-secret\n", new FixedClock('2026-10-16T12:00:00+02:00'));

        self::assertSame(
            self::signature()->authenticate(self::putRequest())->getHeaderLine('Authorization'),
            $padded->authenticate(self::putRequest())->getHeaderLine('Authorization'),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function emptyCredentials(): iterable
    {
        yield 'empty key' => ['', 'test-secret'];
        yield 'whitespace key' => [" \n", 'test-secret'];
        yield 'empty secret' => ['test-key', ''];
        yield 'whitespace secret' => ['test-key', " \n"];
    }

    #[DataProvider('emptyCredentials')]
    public function testRejectsEmptyCredentials(string $apiKey, string $secret): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('API key and secret must not be empty.');

        new HmacSignature($apiKey, $secret);
    }

    public function testSignsTheWholeBodyAndLeavesItRewound(): void
    {
        $request = self::putRequest();
        $request->getBody()->getContents(); // stream at its end, as after a previous read

        $signed = self::signature()->authenticate($request);

        self::assertSame(
            self::signature()->authenticate(self::putRequest())->getHeaderLine('Authorization'),
            $signed->getHeaderLine('Authorization'),
        );
        self::assertSame(self::BODY, $signed->getBody()->getContents());
    }

    public function testRejectsANonSeekableBody(): void
    {
        $request = new Request('PUT', 'https://api.myracloud.com/en/rapi/cacheClear/example.com', [], new NoSeekStream(Utils::streamFor(self::BODY)));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The request body must be seekable to be signed.');

        self::signature()->authenticate($request);
    }

    public function testTakesTheDateFromTheSystemClockByDefault(): void
    {
        $before = new DateTimeImmutable('-1 second');
        $signed = (new HmacSignature('test-key', 'test-secret'))->authenticate(self::putRequest());
        $after = new DateTimeImmutable('+1 second');

        $date = DateTimeImmutable::createFromFormat(DATE_RFC3339, $signed->getHeaderLine('Date'));
        self::assertInstanceOf(DateTimeImmutable::class, $date);
        self::assertGreaterThanOrEqual($before->getTimestamp(), $date->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp(), $date->getTimestamp());
    }

    public function testTakesANewDateForEveryRequest(): void
    {
        $clock = new class implements \Psr\Clock\ClockInterface {
            private int $calls = 0;

            public function now(): DateTimeImmutable
            {
                return (new DateTimeImmutable('2026-10-16T12:00:00+02:00'))->setTime(12, 0, $this->calls++);
            }
        };
        $signature = new HmacSignature('test-key', 'test-secret', $clock);

        self::assertSame('2026-10-16T12:00:00+02:00', $signature->authenticate(self::putRequest())->getHeaderLine('Date'));
        self::assertSame('2026-10-16T12:00:01+02:00', $signature->authenticate(self::putRequest())->getHeaderLine('Date'));
    }

    public function testDoesNotExposeTheSecret(): void
    {
        $signature = new HmacSignature('test-key', 'test-secret');

        self::assertStringNotContainsString('test-secret', print_r($signature, true));
        self::assertStringNotContainsString('test-secret', var_export($signature, true));
        self::assertStringNotContainsString('test-secret', print_r((array) $signature, true));

        $this->expectException(Exception::class);
        serialize($signature);
    }

    private static function signature(string $date = '2026-10-16T12:00:00+02:00'): HmacSignature
    {
        return new HmacSignature('test-key', 'test-secret', new FixedClock($date));
    }

    private static function putRequest(string $method = 'PUT'): Request
    {
        return new Request($method, 'https://api.myracloud.com/en/rapi/cacheClear/example.com', [], self::BODY);
    }
}
