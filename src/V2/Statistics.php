<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V2;

use DateTimeInterface;
use Myracloud\WebApi\Contract;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\CountryStatistic;
use Myracloud\WebApi\Model\Graph;
use Myracloud\WebApi\Model\StatisticQuery;
use Myracloud\WebApi\Model\StatisticResult;
use Myracloud\WebApi\Model\StatisticTotals;
use Myracloud\WebApi\Model\UrlStatistic;

/**
 * `POST /statistic/query` (as v1) and the dashboard statistics `/statistics/…/{from}/{to}`
 * (v2 only; the period as Unix seconds in the path, read only although most are POSTs).
 *
 * The dashboard statistics take the hosts as `$domains` (each with all its subdomains) and/or
 * `$subdomains` (just these); top/cached URLs take ONE of either. Their answers have no
 * envelope (captured): bare lists or maps, times in MILLISECONDS. A domain outside the
 * account is rejected with INTERNAL_ERROR (HTTP 400, captured).
 */
final readonly class Statistics implements Contract\Statistics
{
    public const CHART_TOTAL_REQUESTS = 'total-requests';
    public const CHART_TOTAL_REQUESTS_WITHOUT_BLOCKED = 'total-requests-without-blocked-requests';
    public const CHART_TOTAL_CACHED_REQUESTS = 'total-cached-requests';
    public const CHART_TOTAL_UNCACHED_REQUESTS = 'total-uncached-requests';
    public const CHART_TOTAL_TRAFFIC = 'total-traffic';
    public const CHART_TOTAL_CACHED_TRAFFIC = 'total-cached-traffic';
    public const CHART_TOTAL_UNCACHED_TRAFFIC = 'total-uncached-traffic';
    public const CHART_TOTAL_INGRESS_TRAFFIC = 'total-ingress-traffic';
    public const CHART_TOTAL_EGRESS_TRAFFIC = 'total-egress-traffic';
    public const CHART_UPSTREAM_TIME_AVG = 'upstream-time-avg';
    public const CHART_CACHED_UNCACHED_REQUESTS_RATIO = 'cached-uncached-requests-ratio';
    public const CHART_CACHED_UNCACHED_TRAFFIC_RATIO = 'cached-uncached-traffic-ratio';
    public const CHART_BLOCKED_REQUESTS_BY_TREATMENT_TYPE = 'blocked-requests-by-treatment-type';
    public const CHART_REQUESTS_BY_AS_TYPE = 'requests-by-as-type';
    public const CHART_REQUESTS_BY_STATUS_CODE = 'requests-by-status-code';

    public const INTERVAL_2_MINUTES = '2m';
    public const INTERVAL_HOURLY = 'hourly';
    public const INTERVAL_DAILY = 'daily';
    public const INTERVAL_WEEKLY = 'weekly';

    /** world map: sort by one of the totals (total_requests, total_cached_traffic, …) or the country */
    public const SORT_TOTAL_REQUESTS = 'total_requests';
    public const SORT_TOTAL_TRAFFIC = 'total_traffic';
    public const SORT_COUNTRY_CODE = 'country_code';

    /**
     * @internal built by {@see Client}
     */
    public function __construct(private Transport $transport) {}

    public function query(StatisticQuery $query): StatisticResult
    {
        return StatisticResult::fromJson($this->transport->send('POST', 'statistic/query', body: $query->toJson()));
    }

    /**
     * Totals and graphs of all domains of the account (no hosts given: GET), or of the hosts
     * given (POST).
     *
     * @param list<string> $domains
     * @param list<string> $subdomains
     *
     * @throws InvalidArgumentException if $from is after $to
     * @throws MyraException
     */
    public function totalRequests(DateTimeInterface $from, DateTimeInterface $to, array $domains = [], array $subdomains = []): StatisticTotals
    {
        $path = self::path('total-domain-requests', $from, $to);

        return StatisticTotals::fromJson($domains === [] && $subdomains === []
            ? $this->transport->send('GET', $path)
            : $this->transport->send('POST', $path, body: self::hosts($domains, $subdomains)));
    }

    /**
     * Traffic graphs of all domains of the account, by name (total_traffic, total_cached_traffic, …).
     *
     * @return array<string, Graph>
     *
     * @throws InvalidArgumentException if $from is after $to
     * @throws MyraException
     */
    public function totalTraffic(DateTimeInterface $from, DateTimeInterface $to): array
    {
        return self::graphs($this->transport->send('GET', self::path('total-domain-traffic', $from, $to))->entries());
    }

    /**
     * Requests by HTTP status code — every code Myra knows, 0 for those without requests (captured).
     *
     * @param list<string> $domains
     * @param list<string> $subdomains
     *
     * @return array<int, int|float>
     *
     * @throws InvalidArgumentException if $from is after $to
     * @throws MyraException
     */
    public function httpStatusCodes(DateTimeInterface $from, DateTimeInterface $to, array $domains, array $subdomains = []): array
    {
        $codes = [];
        foreach ($this->transport->sendList('POST', self::path('http-status-codes', $from, $to), self::hosts($domains, $subdomains)) as $code) {
            $codes[$code->int('status_code')] = $code->number('value');
        }

        return $codes;
    }

    /**
     * The most requested URLs of ONE domain or subdomain (Myra sends 10); none = an empty list.
     *
     * @param list<string> $domains
     * @param list<string> $subdomains
     *
     * @return list<UrlStatistic>
     *
     * @throws InvalidArgumentException if $from is after $to
     * @throws MyraException
     */
    public function topUrls(DateTimeInterface $from, DateTimeInterface $to, array $domains, array $subdomains = []): array
    {
        return array_map(UrlStatistic::fromJson(...), $this->transport->sendList('POST', self::path('top-urls', $from, $to), self::hosts($domains, $subdomains)));
    }

    /**
     * The URLs of ONE domain or subdomain answered from the cache; none = an empty list.
     *
     * @param list<string> $domains
     * @param list<string> $subdomains
     *
     * @return list<UrlStatistic>
     *
     * @throws InvalidArgumentException if $from is after $to
     * @throws MyraException
     */
    public function cachedUrls(DateTimeInterface $from, DateTimeInterface $to, array $domains, array $subdomains = []): array
    {
        return array_map(UrlStatistic::fromJson(...), $this->transport->sendList('POST', self::path('cached-urls', $from, $to), self::hosts($domains, $subdomains)));
    }

    /**
     * Requests and traffic by country.
     *
     * @param list<string> $domains
     * @param list<string> $subdomains
     * @param string $sortBy SORT_* or another total's name
     * @param string $direction "desc" or "asc"
     *
     * @return list<CountryStatistic>
     *
     * @throws InvalidArgumentException if $from is after $to
     * @throws MyraException
     */
    public function worldMap(DateTimeInterface $from, DateTimeInterface $to, array $domains, array $subdomains = [], string $sortBy = self::SORT_TOTAL_REQUESTS, string $direction = 'desc'): array
    {
        $path = self::path('world-map', $from, $to) . '/' . rawurlencode($sortBy) . '/' . rawurlencode($direction);

        return array_map(CountryStatistic::fromJson(...), $this->transport->sendList('POST', $path, self::hosts($domains, $subdomains)));
    }

    /**
     * One chart, by group: per host and "ALL-{domainId}", or one accumulated group ($sum).
     * An unknown chart is rejected ("The entity does not exist.", captured).
     *
     * @param string $chart CHART_*
     * @param string $interval INTERVAL_*
     * @param list<string> $domains
     * @param list<string> $subdomains
     * @param bool $compressed drop the points without data
     * @param bool $sum accumulate all hosts into one graph
     *
     * @return array<string, Graph>
     *
     * @throws InvalidArgumentException if $from is after $to
     * @throws MyraException
     */
    public function histogram(DateTimeInterface $from, DateTimeInterface $to, string $chart, string $interval, array $domains, array $subdomains = [], bool $compressed = false, bool $sum = false): array
    {
        $path = sprintf('%s/%s/%s/%d/%d', self::path('histogram', $from, $to), rawurlencode($chart), rawurlencode($interval), $compressed, $sum);
        $groups = [];
        foreach ($this->transport->send('POST', $path, body: self::hosts($domains, $subdomains))->objectMap('group') as $name => $group) {
            $groups[(string) $name] = Graph::fromJson($group->object('graphData'));
        }

        return $groups;
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function path(string $statistic, DateTimeInterface $from, DateTimeInterface $to): string
    {
        if ($from > $to) {
            throw new InvalidArgumentException(sprintf('The period starts after it ends: %s > %s.', $from->format(DATE_ATOM), $to->format(DATE_ATOM)));
        }

        return sprintf('statistics/%s/%d/%d', $statistic, $from->getTimestamp(), $to->getTimestamp());
    }

    /**
     * @param list<string> $domains
     * @param list<string> $subdomains
     *
     * @return array{domains: list<string>, subDomains: list<string>}
     */
    private static function hosts(array $domains, array $subdomains): array
    {
        return ['domains' => $domains, 'subDomains' => $subdomains];
    }

    /**
     * @param array<int|string, \Myracloud\WebApi\Http\JsonObject> $graphs
     *
     * @return array<string, Graph>
     */
    private static function graphs(array $graphs): array
    {
        $result = [];
        foreach ($graphs as $name => $graph) {
            $result[(string) $name] = Graph::fromJson($graph);
        }

        return $result;
    }
}
