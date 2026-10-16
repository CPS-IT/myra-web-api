<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * An API token of the organisation (`TokenVO`, v2 only): metadata only, never the token itself.
 */
final readonly class Token
{
    /**
     * @param array<mixed> $raw the complete object as Myra sent it
     */
    public function __construct(
        private int $id,
        private string $name,
        private bool $enabled,
        private ?string $expiryDate,
        private ?string $lastUsedDate,
        private array $raw = [],
    ) {}

    /**
     * @internal
     *
     * @throws TransportException
     */
    public static function fromJson(JsonObject $json): self
    {
        return new self(
            $json->int('id'),
            $json->string('name'),
            $json->bool('enabled'),
            $json->nullableString('expiryDate'),
            $json->nullableString('lastUsedDate'),
            $json->toArray(),
        );
    }

    public function id(): int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function expiryDate(): ?string
    {
        return $this->expiryDate;
    }

    public function lastUsedDate(): ?string
    {
        return $this->lastUsedDate;
    }

    /**
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
