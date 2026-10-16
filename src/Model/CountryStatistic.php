<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * The requests and traffic of one country (v2 `statistics/world-map`). The split by
 * network operator (`astype`) is in raw().
 */
final readonly class CountryStatistic
{
    /**
     * @param string $countryCode ISO 3166-1 alpha-2, e.g. "DE"
     * @param array<string, int|float> $totals total_requests, total_cached_traffic, … as Myra names them
     * @param array<mixed> $raw the complete object as Myra sent it
     */
    public function __construct(
        private string $countryCode,
        private array $totals,
        private array $raw = [],
    ) {}

    /**
     * @internal
     *
     * @throws TransportException
     */
    public static function fromJson(JsonObject $json): self
    {
        $totals = [];
        foreach ($json->toArray() as $name => $value) {
            if (is_int($value) || is_float($value)) {
                $totals[(string) $name] = $value;
            }
        }

        return new self($json->string('country_code'), $totals, $json->toArray());
    }

    public function countryCode(): string
    {
        return $this->countryCode;
    }

    /**
     * @return array<string, int|float>
     */
    public function totals(): array
    {
        return $this->totals;
    }

    /**
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
