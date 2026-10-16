<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * A contact of the organisation (`OrganizationContactVO`, v2 only).
 */
final readonly class OrganizationContact
{
    /**
     * @param list<string> $types
     * @param array<mixed> $raw the complete object as Myra sent it
     */
    public function __construct(
        private int $id,
        private string $name,
        private string $email,
        private array $types,
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
            $json->string('email'),
            $json->has('types') ? $json->strings('types') : [],
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

    public function email(): string
    {
        return $this->email;
    }

    /**
     * @return list<string> keys of the contact types, e.g. "TECHNICAL"
     */
    public function types(): array
    {
        return $this->types;
    }

    /**
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
