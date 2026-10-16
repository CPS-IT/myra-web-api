<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * A country for WAF rules (`CountryVO`, v2 catalogue).
 */
final readonly class Country
{
    /**
     * @param string $code ISO 3166-1 alpha-2, e.g. "DE"
     * @param array<mixed> $raw the complete object as Myra sent it
     */
    public function __construct(
        private string $code,
        private string $name,
        private string $continentCode,
        private string $continent,
        private array $raw = [],
    ) {}

    /**
     * @internal
     *
     * @throws TransportException
     */
    public static function fromJson(JsonObject $json): self
    {
        return new self($json->string('countryCode'), $json->string('country'), $json->string('continentCode'), $json->string('continent'), $json->toArray());
    }

    public function code(): string
    {
        return $this->code;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function continentCode(): string
    {
        return $this->continentCode;
    }

    public function continent(): string
    {
        return $this->continent;
    }

    /**
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
