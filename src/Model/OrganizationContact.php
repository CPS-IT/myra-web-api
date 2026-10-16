<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * A contact of the organisation (`OrganizationContactVO`, v2 only). Changes go through
 * with…() on a contact read before.
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
     * Version for optimistic locking, exactly as Myra sent it.
     */
    public function modified(): ?string
    {
        $modified = $this->raw['modified'] ?? null;

        return is_string($modified) ? $modified : null;
    }

    public function withName(string $name): self
    {
        return new self($this->id, $name, $this->email, $this->types, $this->raw);
    }

    public function withEmail(string $email): self
    {
        return new self($this->id, $this->name, $email, $this->types, $this->raw);
    }

    /**
     * @param string|null $phone as written, e.g. "+49 30 1234567" (the spec says integer, which
     *                           would lose a leading 0 or +; sent as given, not verified)
     */
    public function withPhone(?string $phone): self
    {
        return new self($this->id, $this->name, $this->email, $this->types, ['phone' => $phone] + $this->raw);
    }

    /**
     * @param bool $sslReminders notifications about expiring certificates
     * @param bool $trafficAlerts traffic alerts (not for partner customers)
     */
    public function withNotifications(bool $sslReminders, bool $trafficAlerts): self
    {
        return new self($this->id, $this->name, $this->email, $this->types, ['receiveSSLReminders' => $sslReminders, 'receiveTrafficAlerts' => $trafficAlerts] + $this->raw);
    }

    /**
     * The update body of the spec: id, modified and the documented fields as read, with the changes.
     *
     * @internal
     *
     * @return array<string, mixed>
     */
    public function toJson(): array
    {
        $json = ['id' => $this->id, 'modified' => $this->modified(), 'name' => $this->name, 'email' => $this->email];
        foreach (['phone', 'receiveSSLReminders', 'receiveTrafficAlerts', 'technicalPriority', 'escalationPriority'] as $key) {
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
