<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Closure;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * One page of a list. Both API versions answer lists in pages of `pageSize` (50) and send
 * the total number of hits as `count`.
 *
 * @template T
 */
final readonly class Page
{
    /**
     * @param list<T> $items
     * @param int $total number of hits over all pages
     */
    public function __construct(
        private array $items,
        private int $page,
        private int $pageSize,
        private int $total,
    ) {}

    /**
     * @internal
     *
     * @template U
     *
     * @param string $key where the items are: v1 `list`, v2 `data`
     * @param Closure(JsonObject): U $map
     *
     * @return self<U>
     *
     * @throws TransportException
     */
    public static function fromJson(JsonObject $body, string $key, Closure $map): self
    {
        return new self(
            array_map($map, $body->objects($key)),
            $body->int('page'),
            $body->int('pageSize'),
            $body->int('count'),
        );
    }

    /**
     * @internal
     *
     * @throws InvalidArgumentException if the number is below 1
     */
    public static function number(int $page): int
    {
        if ($page < 1) {
            throw new InvalidArgumentException(sprintf('Pages start at 1, %d given.', $page));
        }

        return $page;
    }

    /**
     * Fetches page after page until the last one.
     *
     * @internal
     *
     * @template U
     *
     * @param Closure(int): self<U> $fetch
     *
     * @return list<U>
     */
    public static function collect(Closure $fetch): array
    {
        $items = [];
        $number = 1;
        do {
            $page = $fetch($number++);
            array_push($items, ...$page->items);
            // An empty page ends it too, whatever `count` claims: never loop forever.
        } while ($page->hasNext() && $page->items !== []);

        return $items;
    }

    /**
     * @return list<T>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * 1-based.
     */
    public function page(): int
    {
        return $this->page;
    }

    public function pageSize(): int
    {
        return $this->pageSize;
    }

    /**
     * Number of hits over all pages (`count` in the API).
     */
    public function total(): int
    {
        return $this->total;
    }

    public function hasNext(): bool
    {
        return $this->page * $this->pageSize < $this->total;
    }
}
