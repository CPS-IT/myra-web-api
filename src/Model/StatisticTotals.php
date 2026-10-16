<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * Totals of a period with their graphs (v2 `statistics/total-domain-requests`): `totals()`
 * by name (total_requests, total_cached_traffic, mean_requests_ps, upstream_time_avg, …),
 * `graphs()` by name (total_requests, total_ingress_traffic, …). Names as Myra sends them —
 * kept as maps, Myra adds new ones.
 */
final readonly class StatisticTotals
{
    /**
     * @param array<string, int|float> $totals
     * @param array<string, Graph> $graphs
     * @param array<mixed> $raw the complete answer as Myra sent it
     */
    public function __construct(
        private array $totals,
        private array $graphs,
        private array $raw = [],
    ) {}

    /**
     * @internal
     *
     * @throws TransportException
     */
    public static function fromJson(JsonObject $json): self
    {
        $data = $json->object('data');
        $totals = [];
        foreach (array_keys($data->toArray()) as $name) {
            $totals[(string) $name] = $data->number((string) $name);
        }
        $graphs = [];
        foreach ($json->objectMap('graph') as $name => $graph) {
            $graphs[(string) $name] = Graph::fromJson($graph);
        }

        return new self($totals, $graphs, $json->toArray());
    }

    /**
     * @return array<string, int|float>
     */
    public function totals(): array
    {
        return $this->totals;
    }

    /**
     * @return array<string, Graph>
     */
    public function graphs(): array
    {
        return $this->graphs;
    }

    /**
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
