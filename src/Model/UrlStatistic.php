<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * A URL with its request count (v2 `statistics/top-urls`, `statistics/cached-urls`).
 */
final readonly class UrlStatistic
{
    /**
     * @param int|float|null $cost only in top-urls (undocumented)
     * @param array<mixed> $raw the complete object as Myra sent it
     */
    public function __construct(
        private string $url,
        private int|float $count,
        private int|float|null $cost = null,
        private array $raw = [],
    ) {}

    /**
     * @internal
     *
     * @throws TransportException
     */
    public static function fromJson(JsonObject $json): self
    {
        return new self($json->string('url'), $json->number('count'), $json->has('cost') ? $json->number('cost') : null, $json->toArray());
    }

    /**
     * The path, as Myra sends it.
     */
    public function url(): string
    {
        return $this->url;
    }

    public function count(): int|float
    {
        return $this->count;
    }

    public function cost(): int|float|null
    {
        return $this->cost;
    }

    /**
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
