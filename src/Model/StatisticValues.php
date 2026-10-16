<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * Minimum, maximum, sum and average of a data source over the period (type "stats").
 */
final readonly class StatisticValues
{
    public function __construct(
        private int|float $min,
        private int|float $max,
        private int|float $sum,
        private int|float $avg,
    ) {}

    /**
     * @internal
     *
     * @throws TransportException
     */
    public static function fromJson(JsonObject $json): self
    {
        return new self($json->number('min'), $json->number('max'), $json->number('sum'), $json->number('avg'));
    }

    public function min(): int|float
    {
        return $this->min;
    }

    public function max(): int|float
    {
        return $this->max;
    }

    public function sum(): int|float
    {
        return $this->sum;
    }

    public function avg(): int|float
    {
        return $this->avg;
    }
}
