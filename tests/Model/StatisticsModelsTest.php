<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\Model;

use DateTimeImmutable;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;
use Myracloud\WebApi\Model\Graph;
use Myracloud\WebApi\Model\StatisticQuery;
use Myracloud\WebApi\Model\StatisticResult;
use Myracloud\WebApi\Model\StatisticTotals;
use Myracloud\WebApi\Model\StatisticValues;
use Myracloud\WebApi\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StatisticQuery::class)]
#[CoversClass(StatisticResult::class)]
#[CoversClass(StatisticValues::class)]
#[CoversClass(Graph::class)]
#[CoversClass(StatisticTotals::class)]
#[UsesClass(JsonObject::class)]
#[UsesClass(TransportException::class)]
#[UsesClass(InvalidArgumentException::class)]
final class StatisticsModelsTest extends TestCase
{
    public function testAQueryLeavesOutWhatIsNotSet(): void
    {
        $query = (new StatisticQuery(new DateTimeImmutable('2026-10-15T00:00:00+00:00'), new DateTimeImmutable('2026-10-16T00:00:00+00:00'), ['www.example.com']))
            ->withDataSource('bytes', 'bytes_cached');

        self::assertSame(['query' => [
            'dataSources' => ['bytes' => ['source' => 'bytes_cached', 'type' => 'stats']],
            'startDate' => '2026-10-15T00:00:00+00:00',
            'endDate' => '2026-10-16T00:00:00+00:00',
            'fqdn' => ['www.example.com'],
        ]], $query->toJson());
    }

    public function testDataSourcesCanBeGivenToTheConstructorAndReplaced(): void
    {
        $query = new StatisticQuery(new DateTimeImmutable('@0'), new DateTimeImmutable('@60'), [], ['a' => ['source' => 'requests', 'type' => 'stats']], StatisticQuery::INTERVAL_5_MINUTES, StatisticQuery::SELECT_ALL);

        $json = $query->withDataSource('a', 'bytes', StatisticQuery::TYPE_HISTOGRAM)->withDataSource('b_2', 'requests')->toJson()['query'];

        self::assertSame(['a' => ['source' => 'bytes', 'type' => 'histogram'], 'b_2' => ['source' => 'requests', 'type' => 'stats']], $json['dataSources']);
        self::assertSame(['5m', 'all'], [$json['aggregationInterval'], $json['type']]);
        $before = $query->toJson()['query']['dataSources'];
        self::assertIsArray($before);
        self::assertSame(['source' => 'requests', 'type' => 'stats'], $before['a'], 'immutable');
    }

    public function testIntervalAndSelectionAreSetLikeTheDataSources(): void
    {
        $query = (new StatisticQuery(new DateTimeImmutable('@0'), new DateTimeImmutable('@60'), ['ALL:example.com']))->withDataSource('r', 'requests');

        $json = $query->withSelection(StatisticQuery::SELECT_OWN)->withAggregationInterval(StatisticQuery::INTERVAL_WEEK)->toJson()['query'];

        self::assertSame(['aggregationInterval', 'dataSources', 'startDate', 'endDate', 'fqdn', 'type'], array_keys($json), 'the captured order');
        self::assertSame(['week', 'own', ['r' => ['source' => 'requests', 'type' => 'stats']], ['ALL:example.com']], [$json['aggregationInterval'], $json['type'], $json['dataSources'], $json['fqdn']]);
        self::assertSame(['week', 'all'], array_values(array_intersect_key($query->withSelection(StatisticQuery::SELECT_ALL)->withAggregationInterval(StatisticQuery::INTERVAL_WEEK)->toJson()['query'], ['aggregationInterval' => 1, 'type' => 1])));
        self::assertArrayNotHasKey('type', $query->toJson()['query'], 'immutable');
    }

    public function testAQueryNeedsADataSource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A statistic query needs at least one data source (withDataSource()).');

        (new StatisticQuery(new DateTimeImmutable(), new DateTimeImmutable(), ['www.example.com']))->toJson();
    }

    #[TestWith(['requests-total'])]
    #[TestWith(['requests total'])]
    #[TestWith([''])]
    #[TestWith(["requests\n"])]
    public function testADataSourceNameIsAWord(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A data source name may contain only [a-zA-Z0-9_], "' . $name . '" given.');

        (new StatisticQuery(new DateTimeImmutable(), new DateTimeImmutable(), []))->withDataSource($name, 'requests');
    }

    public function testTheConstructorChecksTheNamesToo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"a-b" given');

        new StatisticQuery(new DateTimeImmutable(), new DateTimeImmutable(), [], ['ok' => ['source' => 'requests', 'type' => 'stats'], 'a-b' => ['source' => 'requests', 'type' => 'stats']]);
    }

    public function testANumericNameIsFine(): void
    {
        $query = new StatisticQuery(new DateTimeImmutable(), new DateTimeImmutable(), [], ['123' => ['source' => 'requests', 'type' => 'stats']]);

        $dataSources = $query->toJson()['query']['dataSources'];
        self::assertIsArray($dataSources);
        self::assertSame([123], array_keys($dataSources));
    }

    public function testReadsEachResultSetByItsShape(): void
    {
        $result = StatisticResult::fromJson(new JsonObject(self::body('v1/statistics/query')));

        $requests = $result->stats('requests');
        self::assertSame([1063, 1064, 1065, 66.5], [$requests->min(), $requests->max(), $requests->sum(), $requests->avg()]);
        self::assertSame([1792051200000 => 1061, 1792054800000 => 1062], $result->histogram('requests_histogram'));
        self::assertSame(1011, $result->statsByKey('response_codes')[206]->sum());
        self::assertEqualsCanonicalizing(['requests', 'requests_histogram', 'response_codes'], $result->names());
        self::assertSame(['123'], (new StatisticResult(['123' => []]))->names(), 'names are strings, also numeric ones');
        self::assertSame(self::body('v1/statistics/query')['result'], $result->result());
        self::assertSame(self::body('v1/statistics/query'), $result->raw());
    }

    public function testAResultSetOfAnotherShapeIsUnexpected(): void
    {
        $result = StatisticResult::fromJson(new JsonObject(self::body('v1/statistics/query')));

        foreach ([
            'result.requests_histogram.min is missing' => static fn(): mixed => $result->stats('requests_histogram'),
            'result.requests.min is not an object but int' => static fn(): mixed => $result->statsByKey('requests'),
            'result.response_codes.500.value is missing' => static fn(): mixed => $result->histogram('response_codes'),
            'result.nothing is missing' => static fn(): mixed => $result->stats('nothing'),
        ] as $message => $read) {
            try {
                $read();
                self::fail('No exception for ' . $message);
            } catch (TransportException $exception) {
                self::assertSame('Unexpected response: ' . $message . '.', $exception->getMessage());
            }
        }
    }

    public function testAnAnswerWithoutResultIsUnexpected(): void
    {
        $this->expectExceptionMessage('Unexpected response: result is missing.');

        StatisticResult::fromJson(new JsonObject(['objectType' => 'StatisticVO']));
    }

    public function testAGraphWithoutDataFieldIsUnexpected(): void
    {
        $this->expectExceptionMessage('Unexpected response: data is missing.');

        Graph::fromJson(new JsonObject(['name' => '', 'max' => 0, 'sum' => 0]));
    }

    public function testAGraphKeepsFloatsAndItsRawObject(): void
    {
        $json = ['name' => 'upstream', 'max' => 1.5, 'sum' => 3, 'data' => [['x' => 1000, 'y' => 0.25]]];

        $graph = Graph::fromJson(new JsonObject($json));

        self::assertSame(['upstream', 1.5, 3, [1000 => 0.25], $json], [$graph->name(), $graph->max(), $graph->sum(), $graph->points(), $graph->raw()]);
    }

    public function testTotalsNeedNumbers(): void
    {
        $this->expectExceptionMessage('Unexpected response: data.total_requests is not a number but string.');

        StatisticTotals::fromJson(new JsonObject(['graph' => [], 'data' => ['total_requests' => '12']]));
    }

    /**
     * @return array<mixed>
     */
    private static function body(string $fixture): array
    {
        $body = Fixture::load($fixture)['response']['body'];
        self::assertIsArray($body);

        return $body;
    }
}
