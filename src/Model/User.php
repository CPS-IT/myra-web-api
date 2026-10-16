<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * A user of the organisation (`UserVO`, v2 only; `user/me` sends `UserExtendedVO` with sessions — in raw()).
 */
final readonly class User
{
    /**
     * @param array<mixed> $raw the complete object as Myra sent it
     */
    public function __construct(
        private int $id,
        private string $login,
        private string $email,
        private string $firstname,
        private string $lastname,
        private bool $active,
        private bool $locked,
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
            $json->string('login'),
            $json->string('email'),
            $json->string('firstname'),
            $json->string('lastname'),
            $json->bool('active'),
            $json->bool('locked'),
            $json->toArray(),
        );
    }

    public function id(): int
    {
        return $this->id;
    }

    public function login(): string
    {
        return $this->login;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function firstname(): string
    {
        return $this->firstname;
    }

    public function lastname(): string
    {
        return $this->lastname;
    }

    public function active(): bool
    {
        return $this->active;
    }

    public function locked(): bool
    {
        return $this->locked;
    }

    /**
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
