<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * What a WAF rule can match on (`WafConditionVO`, v2 catalogue): e.g. "host", "user_agent",
 * "country", "custom_header" (then with your own key).
 */
final readonly class WafCondition
{
    /**
     * @param string|null $key the header or parameter it reads, e.g. "Host"; null = given per rule
     * @param int $availablePhases bit mask: 1 = request, 2 = response
     * @param array<mixed> $raw the complete object as Myra sent it (forceCustomValues, …)
     */
    public function __construct(
        private string $name,
        private string $category,
        private string $alias,
        private ?string $key,
        private int $availablePhases,
        private array $raw = [],
    ) {}

    /**
     * @internal
     *
     * @throws TransportException
     */
    public static function fromJson(JsonObject $json): self
    {
        return new self($json->string('name'), $json->string('category'), $json->string('alias'), $json->nullableString('key'), $json->int('availablePhases'), $json->toArray());
    }

    public function name(): string
    {
        return $this->name;
    }

    public function category(): string
    {
        return $this->category;
    }

    /**
     * The label in the Myra App.
     */
    public function alias(): string
    {
        return $this->alias;
    }

    public function key(): ?string
    {
        return $this->key;
    }

    public function availablePhases(): int
    {
        return $this->availablePhases;
    }

    /**
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
