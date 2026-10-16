<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;
use SensitiveParameter;
use SensitiveParameterValue;

/**
 * An API token of the user (`TokenVO`, v2 only): metadata — the token itself only once, in
 * the answer to its creation ({@see value()}; hidden from dumps and traces, not in raw()).
 */
final readonly class Token
{
    private ?SensitiveParameterValue $value;

    /**
     * @param string|null $expiryDate ISO 8601; null = never expires
     * @param array<mixed> $raw the complete object as Myra sent it, without the token
     * @param string|null $value the token, only from create
     */
    public function __construct(
        private int $id,
        private string $name,
        private bool $enabled,
        private ?string $expiryDate,
        private ?string $lastUsedDate,
        private array $raw = [],
        #[SensitiveParameter]
        ?string $value = null,
    ) {
        $this->value = $value === null ? null : new SensitiveParameterValue($value);
    }

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
            array_diff_key($json->toArray(), ['token' => true]),
            $json->nullableString('token'),
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
     * The token for `BearerToken` — only in the answer to its creation, null otherwise.
     */
    public function value(): ?string
    {
        $value = $this->value?->getValue();

        return is_string($value) ? $value : null;
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
        return new self($this->id, $name, $this->enabled, $this->expiryDate, $this->lastUsedDate, $this->raw);
    }

    public function withEnabled(bool $enabled): self
    {
        return new self($this->id, $this->name, $enabled, $this->expiryDate, $this->lastUsedDate, $this->raw);
    }

    /**
     * @param string|null $expiryDate ISO 8601, not in the past; null = never expires
     */
    public function withExpiryDate(?string $expiryDate): self
    {
        return new self($this->id, $this->name, $this->enabled, $expiryDate, $this->lastUsedDate, $this->raw);
    }

    /**
     * The update body of the spec; `expiryDate` is always sent, null meaning "never expires"
     * (the spec: without a value the token does not expire — sending null is not verified).
     *
     * @internal
     *
     * @return array<string, mixed>
     */
    public function toJson(): array
    {
        return ['id' => $this->id, 'modified' => $this->modified(), 'name' => $this->name, 'enabled' => $this->enabled, 'expiryDate' => $this->expiryDate];
    }

    /**
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
