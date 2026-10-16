<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;
use Myracloud\WebApi\Model\Violation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Violation::class)]
#[UsesClass(JsonObject::class)]
#[UsesClass(TransportException::class)]
final class ViolationTest extends TestCase
{
    public function testReadsAllFields(): void
    {
        $violation = Violation::fromJson(new JsonObject([
            'propertyPath' => 'fqdn',
            'message' => 'The given domain does not exist.',
            'code' => 'DOMAIN_DOES_NOT_EXIST',
            'translations' => ['en' => 'The given domain does not exist.', 'de' => 'Die angegebene Domain wurde nicht gefunden.'],
        ]));

        self::assertSame('fqdn', $violation->propertyPath());
        self::assertSame('The given domain does not exist.', $violation->message());
        self::assertSame('DOMAIN_DOES_NOT_EXIST', $violation->code());
        self::assertSame(['en' => 'The given domain does not exist.', 'de' => 'Die angegebene Domain wurde nicht gefunden.'], $violation->translations());
        self::assertSame('fqdn: The given domain does not exist. (DOMAIN_DOES_NOT_EXIST)', (string) $violation);
    }

    public function testOptionalFieldsMayBeMissing(): void
    {
        $violation = Violation::fromJson(new JsonObject([]));

        self::assertSame('', $violation->propertyPath());
        self::assertSame('', $violation->message());
        self::assertNull($violation->code());
        self::assertSame([], $violation->translations());
    }

    public function testDescribesAViolationOfTheWholeRequestWithoutPath(): void
    {
        self::assertSame('Invalid data format.', (string) new Violation('', 'Invalid data format.'));
    }

    public function testRejectsMistypedFields(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Unexpected response: translations is not an object of strings but string.');

        Violation::fromJson(new JsonObject(['message' => 'x', 'translations' => 'x']));
    }
}
