<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final readonly class FixedClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(string $now)
    {
        $this->now = new DateTimeImmutable($now);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
