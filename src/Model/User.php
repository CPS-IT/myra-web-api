<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * A user of the organisation (`UserVO`, v2 only; `user/me` sends `UserExtendedVO` with sessions — in raw()).
 *
 * Changes go through withEmail() / withName() on a user read before ({@see \Myracloud\WebApi\V2\Users::update()}).
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
     * Version for optimistic locking, exactly as Myra sent it.
     */
    public function modified(): ?string
    {
        $modified = $this->raw['modified'] ?? null;

        return is_string($modified) ? $modified : null;
    }

    /**
     * The new address is also the new login (the spec: login should equal email).
     */
    public function withEmail(string $email): self
    {
        return new self($this->id, $email, $email, $this->firstname, $this->lastname, $this->active, $this->locked, $this->raw);
    }

    public function withName(string $firstname, string $lastname): self
    {
        return new self($this->id, $this->login, $this->email, $firstname, $lastname, $this->active, $this->locked, $this->raw);
    }

    /**
     * The update body of the spec: id, modified, email, login, names — and the role fields
     * (roles, rootGroupRoles, isIndirectCustomer) exactly as read, never defaulted: whether
     * Myra keeps a role field that is left out is not verified, and dropping one could take
     * away a user's (or your own) administrator rights.
     *
     * @internal
     *
     * @return array<string, mixed>
     */
    public function toJson(): array
    {
        $json = ['id' => $this->id, 'modified' => $this->modified(), 'email' => $this->email, 'login' => $this->login, 'firstname' => $this->firstname, 'lastname' => $this->lastname];
        foreach (['roles', 'rootGroupRoles', 'isIndirectCustomer'] as $key) {
            if (array_key_exists($key, $this->raw)) {
                $json[$key] = $this->raw[$key];
            }
        }

        return $json;
    }

    /**
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
