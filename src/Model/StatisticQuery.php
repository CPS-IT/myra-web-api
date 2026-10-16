<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use DateTimeInterface;
use Myracloud\WebApi\Exception\InvalidArgumentException;

/**
 * A statistic query (`StatisticQueryVO`, the same in v1 and v2): data sources over a period
 * for some hosts.
 *
 *     (new StatisticQuery($from, $to, ['ALL:example.com']))
 *         ->withDataSource('requests', 'requests')
 *         ->withDataSource('requests_per_hour', 'requests', StatisticQuery::TYPE_HISTOGRAM)
 *
 * Sources (manual, chapter 15.3.2): requests, bytes and their _ssl / _nonssl / _cached /
 * _uncached / _cache_hits variants, upstream_performance, response_codes, country_codes.
 * An unknown source answers HTTP 500 without details (captured, v1).
 */
final readonly class StatisticQuery
{
    /** min/max/sum/avg over the period */
    public const TYPE_STATS = 'stats';
    /** one value per bucket of the aggregation interval */
    public const TYPE_HISTOGRAM = 'histogram';

    public const INTERVAL_5_MINUTES = '5m';
    public const INTERVAL_HOUR = 'hour';
    public const INTERVAL_DAY = 'day';
    public const INTERVAL_WEEK = 'week';

    /** the hosts given (Myra's default) */
    public const SELECT_FQDN = 'fqdn';
    public const SELECT_ALL = 'all';
    public const SELECT_OWN = 'own';
    public const SELECT_FOREIGN = 'foreign';

    /**
     * @param list<string> $fqdns hosts, e.g. "www.example.com"; "ALL:example.com" is the
     *                            domain with all its subdomains
     * @param array<array-key, array{source: string, type: string}> $dataSources by result name
     *                                                            (PHP turns a name such as "123" into an int key)
     * @param string|null $aggregationInterval INTERVAL_*, for histograms; null = Myra's default (day)
     * @param string|null $selection SELECT_*; null = Myra's default (fqdn)
     *
     * @throws InvalidArgumentException for a result name other than [a-zA-Z0-9_]
     */
    public function __construct(
        private DateTimeInterface $start,
        private DateTimeInterface $end,
        private array $fqdns,
        private array $dataSources = [],
        private ?string $aggregationInterval = null,
        private ?string $selection = null,
    ) {
        foreach (array_keys($dataSources) as $name) {
            self::assertName((string) $name); // "123" became an int key
        }
    }

    /**
     * @param string $name names the result set, [a-zA-Z0-9_]
     * @param string $type TYPE_STATS or TYPE_HISTOGRAM
     *
     * @throws InvalidArgumentException for a name other than [a-zA-Z0-9_]
     */
    public function withDataSource(string $name, string $source, string $type = self::TYPE_STATS): self
    {
        self::assertName($name);
        $dataSources = $this->dataSources;
        $dataSources[$name] = ['source' => $source, 'type' => $type];

        return new self($this->start, $this->end, $this->fqdns, $dataSources, $this->aggregationInterval, $this->selection);
    }

    /**
     * @param string $interval INTERVAL_*
     */
    public function withAggregationInterval(string $interval): self
    {
        return new self($this->start, $this->end, $this->fqdns, $this->dataSources, $interval, $this->selection);
    }

    /**
     * @param string $selection SELECT_*
     */
    public function withSelection(string $selection): self
    {
        return new self($this->start, $this->end, $this->fqdns, $this->dataSources, $this->aggregationInterval, $selection);
    }

    /**
     * The StatisticVO to send: `{query: {…}}`, dates in ISO 8601 with offset.
     *
     * @internal
     *
     * @return array{query: array<string, mixed>}
     *
     * @throws InvalidArgumentException without a data source
     */
    public function toJson(): array
    {
        if ($this->dataSources === []) {
            throw new InvalidArgumentException('A statistic query needs at least one data source (withDataSource()).');
        }
        $query = [];
        if ($this->aggregationInterval !== null) {
            $query['aggregationInterval'] = $this->aggregationInterval;
        }
        $query['dataSources'] = $this->dataSources;
        $query['startDate'] = $this->start->format(DATE_ATOM);
        $query['endDate'] = $this->end->format(DATE_ATOM);
        $query['fqdn'] = $this->fqdns;
        if ($this->selection !== null) {
            $query['type'] = $this->selection;
        }

        return ['query' => $query];
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function assertName(string $name): void
    {
        if (preg_match('/^\w+$/D', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('A data source name may contain only [a-zA-Z0-9_], "%s" given.', $name));
        }
    }
}
