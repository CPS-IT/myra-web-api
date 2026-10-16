<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\V1;

use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Exception\RequestRejectedException;
use Myracloud\WebApi\Model\Maintenance;
use Myracloud\WebApi\Tests\Support\FakeHttpClient;
use Myracloud\WebApi\Tests\Support\Fixture;
use Myracloud\WebApi\V1;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * As captured with the probe (a maintenance for 2099, never active).
 */
#[CoversClass(V1\Maintenances::class)]
#[UsesClass(V1\Client::class)]
#[UsesClass(V1\Domains::class)]
#[UsesClass(V1\DnsRecords::class)]
#[UsesClass(V1\CacheClearing::class)]
#[UsesClass(V1\Redirects::class)]
#[UsesClass(V1\IpFilters::class)]
#[UsesClass(V1\CacheSettings::class)]
#[UsesClass(V1\SubdomainSettings::class)]
#[UsesClass(\Myracloud\WebApi\Auth\BearerToken::class)]
#[UsesClass(\Myracloud\WebApi\Http\Transport::class)]
#[UsesClass(\Myracloud\WebApi\Http\Envelope::class)]
#[UsesClass(\Myracloud\WebApi\Http\JsonObject::class)]
#[UsesClass(\Myracloud\WebApi\Model\Page::class)]
#[UsesClass(\Myracloud\WebApi\Model\Maintenance::class)]
#[UsesClass(\Myracloud\WebApi\Model\MaintenanceTemplate::class)]
#[UsesClass(\Myracloud\WebApi\Model\Persisted::class)]
#[UsesClass(\Myracloud\WebApi\Model\DomainName::class)]
#[UsesClass(\Myracloud\WebApi\Model\Domain::class)]
#[UsesClass(\Myracloud\WebApi\Model\Violation::class)]
#[UsesClass(\Myracloud\WebApi\Exception\RequestRejectedException::class)]
final class MaintenancesTest extends TestCase
{
    private FakeHttpClient $http;
    private V1\Client $client;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->client = new V1\Client(new BearerToken('t'), httpClient: $this->http);
    }

    public function testListsTheMaintenancesOfAVhost(): void
    {
        $this->http->queue(Fixture::response('v1/maintenance/list'));

        $list = $this->client->maintenances()->list('map.example.org');

        self::assertSame('GET https://api.myracloud.com/en/rapi/maintenance/map.example.org/1', $this->sent()[0]);
        self::assertCount(1, $list);
        self::assertFalse($list[0]->isActive());
    }

    public function testReadsOnePage(): void
    {
        $this->http->queue(Fixture::page('list', [], 2, 50, 0));

        $this->client->maintenances()->page('map.example.org', 2);

        self::assertSame('GET https://api.myracloud.com/en/rapi/maintenance/map.example.org/2', $this->sent()[0]);
    }

    public function testCreatesAsCaptured(): void
    {
        $this->http->queue(Fixture::response('v1/maintenance/create'));

        $created = $this->client->maintenances()->create(self::probe());

        self::assertSame('PUT https://api.myracloud.com/en/rapi/maintenance/map.example.org', $this->sent()[0]);
        self::assertSame(Fixture::requestBody('v1/maintenance/create'), (string) $this->http->lastRequest()->getBody());
        self::assertFalse($created->isActive());
    }

    public function testUpdatesWithTheCompleteObjectAsCaptured(): void
    {
        $this->http->queue(Fixture::response('v1/maintenance/create'), Fixture::response('v1/maintenance/update'));
        $created = $this->client->maintenances()->create(self::probe());

        $this->client->maintenances()->update($created->withContent(self::sent_('v1/maintenance/update', 'content')));

        self::assertSame('POST https://api.myracloud.com/en/rapi/maintenance/map.example.org', $this->sent()[1]);
        self::assertSame(Fixture::requestBody('v1/maintenance/update'), (string) $this->http->lastRequest()->getBody());
    }

    public function testAStaleVersionIsRejected(): void
    {
        $this->http->queue(Fixture::response('v1/maintenance/create'), Fixture::response('v1/maintenance/update-stale'));
        $created = $this->client->maintenances()->create(self::probe());

        $this->expectException(RequestRejectedException::class);
        $this->expectExceptionMessage('The record has been edited in the meantime.');

        $this->client->maintenances()->update($created->withContent('x'));
    }

    public function testDeletesAsCaptured(): void
    {
        $this->http->queue(Fixture::response('v1/maintenance/create'), Fixture::response('v1/maintenance/update'), Fixture::response('v1/maintenance/delete'));
        $created = $this->client->maintenances()->create(self::probe());
        $updated = $this->client->maintenances()->update($created->withContent(self::sent_('v1/maintenance/update', 'content')));

        $this->client->maintenances()->delete($updated);

        self::assertSame('DELETE https://api.myracloud.com/en/rapi/maintenance/map.example.org', $this->sent()[2]);
        self::assertSame(Fixture::requestBody('v1/maintenance/delete'), (string) $this->http->lastRequest()->getBody());
    }

    public function testGuardsWritesBeforeSending(): void
    {
        $persisted = new Maintenance('map.example.org', 's', id: 7, modified: 'm');
        foreach ([fn() => $this->client->maintenances()->update(self::probe()), fn() => $this->client->maintenances()->delete(self::probe()), fn() => $this->client->maintenances()->create($persisted)] as $write) {
            try {
                $write();
                self::fail('No exception thrown.');
            } catch (InvalidArgumentException) {
            }
        }
        self::assertSame([], $this->http->requests());
    }

    private static function probe(): Maintenance
    {
        return new Maintenance('map.example.org', '2099-01-01T00:00:00+0000', '2099-01-01T01:00:00+0000', self::sent_('v1/maintenance/create', 'content'), false);
    }

    /**
     * A (anonymised) value the capture sent.
     */
    private static function sent_(string $fixture, string $field): string
    {
        $body = Fixture::load($fixture)['request']['body'];
        self::assertIsArray($body);
        self::assertIsString($body[$field]);

        return $body[$field];
    }

    /**
     * @return list<string>
     */
    private function sent(): array
    {
        return array_map(static fn($r): string => $r->getMethod() . ' ' . $r->getUri(), $this->http->requests());
    }
}
