<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * One chart of the v2 statistics (`{name, max, sum, data: [{x, y}]}`): points by time.
 *
 * Captured: `max` and `sum` were 0 while the points had values — read the points;
 * `data` is null for a host without requests (→ no points).
 */
final readonly class Graph
{
    /**
     * @param array<int, int|float> $points y by x, x = Unix time in MILLISECONDS
     * @param array<mixed> $raw the complete object as Myra sent it
     */
    public function __construct(
        private string $name,
        private int|float $max,
        private int|float $sum,
        private array $points,
        private array $raw = [],
    ) {}

    /**
     * @internal
     *
     * @throws TransportException
     */
    public static function fromJson(JsonObject $json): self
    {
        $points = [];
        foreach ($json->has('data') && $json->toArray()['data'] === null ? [] : $json->objects('data') as $point) {
            $points[$point->int('x')] = $point->number('y');
        }

        return new self($json->string('name'), $json->number('max'), $json->number('sum'), $points, $json->toArray());
    }

    public function name(): string
    {
        return $this->name;
    }

    public function max(): int|float
    {
        return $this->max;
    }

    public function sum(): int|float
    {
        return $this->sum;
    }

    /**
     * @return array<int, int|float> y by x, x = Unix time in milliseconds
     */
    public function points(): array
    {
        return $this->points;
    }

    /**
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
