<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests;

use GuzzleHttp\Psr7\Request;
use Myracloud\WebApi\Http\Transport;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * Proves the proxy barrier in phpunit.xml.dist: the default client cannot leave the machine.
 * The host never resolves either (.invalid), so even a broken barrier sends nothing.
 */
#[CoversNothing]
final class NetworkBarrierTest extends TestCase
{
    public function testTheDefaultClientIsStoppedByTheProxyBarrier(): void
    {
        try {
            Transport::defaultClient()->sendRequest(new Request('GET', 'https://example.invalid/'));
            self::fail('The request was not stopped.');
        } catch (ClientExceptionInterface $exception) {
            self::assertStringContainsString('127.0.0.1', $exception->getMessage());
        }
    }
}
