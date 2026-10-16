<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * An entry of a user's activity log (`ActivityVO`, v2 only), e.g. a login; geo, OS and browser in raw()['details'].
 */
final readonly class Activity
{
    /**
     * @param array<mixed> $raw the complete object as Myra sent it
     */
    public function __construct(
        private int $id,
        private string $type,
        private string $title,
        private string $message,
        private ?string $ip,
        private ?string $created,
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
            $json->string('type'),
            $json->string('title'),
            $json->string('message'),
            $json->nullableString('ip'),
            $json->nullableString('created'),
            $json->toArray(),
        );
    }

    public function id(): int
    {
        return $this->id;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function ip(): ?string
    {
        return $this->ip;
    }

    public function created(): ?string
    {
        return $this->created;
    }

    /**
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
