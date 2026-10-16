<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\Exception;

use Myracloud\WebApi\Exception\RequestRejectedException;
use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Model\Violation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RequestRejectedException::class)]
#[CoversClass(TransportException::class)]
#[UsesClass(Violation::class)]
final class ExceptionsTest extends TestCase
{
    public function testARejectionCarriesItsViolations(): void
    {
        $violations = [new Violation('fqdn', 'The given domain does not exist.', 'DOMAIN_DOES_NOT_EXIST')];
        $exception = new RequestRejectedException('rejected', $violations);

        self::assertSame('rejected', $exception->getMessage());
        self::assertSame($violations, $exception->violations());
    }

    public function testATransportFailureKeepsItsCause(): void
    {
        $cause = new RuntimeException('Connection refused');
        $exception = new TransportException('GET https://apiv2.myracloud.com/domains: Connection refused', $cause);

        self::assertSame('GET https://apiv2.myracloud.com/domains: Connection refused', $exception->getMessage());
        self::assertSame($cause, $exception->getPrevious());
        self::assertSame(0, $exception->getCode());
        self::assertNull((new TransportException('x'))->getPrevious());
    }
}
