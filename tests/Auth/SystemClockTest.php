<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\Auth;

use DateTimeImmutable;
use Myracloud\WebApi\Auth\SystemClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SystemClock::class)]
final class SystemClockTest extends TestCase
{
    public function testTellsTheCurrentTime(): void
    {
        $before = new DateTimeImmutable();
        $now = (new SystemClock())->now();

        self::assertGreaterThanOrEqual($before, $now);
        self::assertLessThanOrEqual(new DateTimeImmutable(), $now);
    }
}
