<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\Smoke;

use GuzzleHttp\Psr7\Request;
use LogicException;
use Myracloud\WebApi\Http\Transport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordingClient::class)]
#[UsesClass(Transport::class)]
final class RecordingClientTest extends TestCase
{
    public function testRefusesAWriteTheGuardDoesNotAllowBeforeSending(): void
    {
        $client = new RecordingClient(static fn(): bool => false);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Refused by the write guard: DELETE /domain/1/redirects/x');

        $client->sendRequest(new Request('DELETE', 'https://example.invalid/domain/1/redirects/x'));
    }

    public function testTheCircuitBreakerStopsARunBeforeSending(): void
    {
        $client = new RecordingClient(static fn(): bool => true, 0);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Circuit breaker: more than 0 requests in one run — a loop?');

        $client->sendRequest(new Request('GET', 'https://example.invalid/domains'));
    }
}
