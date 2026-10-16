<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;
use SensitiveParameter;
use SensitiveParameterValue;

/**
 * An API key of a user (`ApiKeyVO`, v2 only): the public key — the secret only once, in the
 * answer to its creation ({@see secret()}; hidden from dumps and traces, not in raw()).
 */
final readonly class ApiKey
{
    private ?SensitiveParameterValue $secret;

    /**
     * @param array<mixed> $raw the complete object as Myra sent it, without the secret
     * @param string|null $secret only from create
     */
    public function __construct(
        private int $id,
        private string $name,
        private string $key,
        private array $raw = [],
        #[SensitiveParameter]
        ?string $secret = null,
    ) {
        $this->secret = $secret === null ? null : new SensitiveParameterValue($secret);
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
            $json->string('key'),
            array_diff_key($json->toArray(), ['secret' => true]),
            $json->nullableString('secret'),
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

    /**
     * The public part, as in HmacSignature.
     */
    public function key(): string
    {
        return $this->key;
    }

    /**
     * The secret for `HmacSignature` — only in the answer to its creation, null otherwise.
     */
    public function secret(): ?string
    {
        $secret = $this->secret?->getValue();

        return is_string($secret) ? $secret : null;
    }

    /**
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
