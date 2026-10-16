<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * The answer to a {@see StatisticQuery} (`StatisticVO`, the same in v1 and v2): one result
 * set per data source, under the name given in the query.
 *
 * The shape of a result set depends on its type AND its source (captured): "stats" gives
 * min/max/sum/avg — except response_codes (and country_codes), which give those per code;
 * "histogram" gives one value per bucket, keyed by the bucket's start in MILLISECONDS.
 * Read each with the accessor that fits; result() has everything as Myra sent it.
 */
final readonly class StatisticResult
{
    /**
     * @param array<mixed> $result
     * @param array<mixed> $raw the complete answer as Myra sent it, incl. the query echo
     */
    public function __construct(
        private array $result,
        private array $raw = [],
    ) {}

    /**
     * @internal
     *
     * @throws TransportException
     */
    public static function fromJson(JsonObject $json): self
    {
        return new self($json->object('result')->toArray(), $json->toArray());
    }

    /**
     * The result set names (as in the query).
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_map('strval', array_keys($this->result));
    }

    /**
     * A "stats" result set.
     *
     * @throws TransportException if it is missing or has another shape
     */
    public function stats(string $name): StatisticValues
    {
        return StatisticValues::fromJson($this->json()->object($name));
    }

    /**
     * A "stats" result set of a source that splits by key: response_codes (HTTP status
     * codes as int keys, plus "Other"), country_codes.
     *
     * @return array<int|string, StatisticValues>
     *
     * @throws TransportException if it is missing or has another shape
     */
    public function statsByKey(string $name): array
    {
        return array_map(StatisticValues::fromJson(...), $this->json()->objectMap($name));
    }

    /**
     * A "histogram" result set: value by bucket start (Unix time in milliseconds).
     *
     * @return array<int, int|float>
     *
     * @throws TransportException if it is missing or has another shape
     */
    public function histogram(string $name): array
    {
        $histogram = [];
        foreach ($this->json()->objectMap($name) as $bucket => $value) {
            $histogram[(int) $bucket] = $value->number('value');
        }

        return $histogram;
    }

    /**
     * @return array<mixed>
     */
    public function result(): array
    {
        return $this->result;
    }

    /**
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }

    private function json(): JsonObject
    {
        return new JsonObject($this->result, 'result');
    }
}
