<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * What a WAF rule can do (`WafActionVO`, v2 catalogue): e.g. type "block", "allow",
 * "verify_human", "set_http_status".
 */
final readonly class WafAction
{
    /**
     * @param int $availablePhases bit mask: 1 = request, 2 = response
     * @param array<mixed> $raw the complete object as Myra sent it (forceCustomValues, …)
     */
    public function __construct(
        private string $name,
        private string $type,
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
        return new self($json->string('name'), $json->string('type'), $json->int('availablePhases'), $json->toArray());
    }

    /**
     * The label in the Myra App.
     */
    public function name(): string
    {
        return $this->name;
    }

    public function type(): string
    {
        return $this->type;
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
