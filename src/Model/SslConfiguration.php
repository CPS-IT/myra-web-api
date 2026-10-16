<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * One of Myra's TLS presets (v2 `ssl-configurations`), referenced by name from a certificate
 * (`sslConfigurationName`). Captured: only the name is always there.
 */
final readonly class SslConfiguration
{
    /**
     * @param array<mixed> $raw the complete object as Myra sent it
     */
    public function __construct(
        private string $name,
        private ?string $protocols = null,
        private ?string $ciphers = null,
        private ?string $tls13Ciphers = null,
        private array $raw = [],
    ) {}

    /**
     * @internal
     *
     * @throws TransportException
     */
    public static function fromJson(JsonObject $json): self
    {
        return new self($json->string('name'), $json->nullableString('protocols'), $json->nullableString('ciphers'), $json->nullableString('tls13Ciphers'), $json->toArray());
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * E.g. "TLSv1.2 TLSv1.3"; null = Myra's default.
     */
    public function protocols(): ?string
    {
        return $this->protocols;
    }

    public function ciphers(): ?string
    {
        return $this->ciphers;
    }

    public function tls13Ciphers(): ?string
    {
        return $this->tls13Ciphers;
    }

    /**
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
