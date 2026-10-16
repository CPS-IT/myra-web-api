<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\V2;

use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Exception\RequestRejectedException;
use Myracloud\WebApi\Model\Maintenance;
use Myracloud\WebApi\Model\MaintenanceTemplate;
use Myracloud\WebApi\Tests\Support\FakeHttpClient;
use Myracloud\WebApi\Tests\Support\Fixture;
use Myracloud\WebApi\V2;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * As captured with the probe (a maintenance for 2099, never active).
 */
#[CoversClass(V2\Maintenances::class)]
#[UsesClass(V2\ErrorPages::class)]
#[UsesClass(V2\IpRanges::class)]
#[CoversClass(V2\MaintenanceTemplates::class)]
#[UsesClass(V2\Client::class)]
#[UsesClass(V2\Domains::class)]
#[UsesClass(V2\DnsRecords::class)]
#[UsesClass(V2\CacheClearing::class)]
#[UsesClass(V2\Redirects::class)]
#[UsesClass(V2\IpFilters::class)]
#[UsesClass(V2\CacheSettings::class)]
#[UsesClass(V2\SubdomainSettings::class)]
#[UsesClass(V2\DomainIdResolver::class)]
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
    private V2\Client $client;

    protected function setUp(): void
    {
        $this->http = (new FakeHttpClient())->queue(Fixture::response('v2/domains/list'));
        $this->client = new V2\Client(new BearerToken('t'), httpClient: $this->http);
    }

    public function testListsTheMaintenancesOfAVhost(): void
    {
        $this->http->queue(Fixture::response('v2/maintenance/list'));

        $list = $this->client->maintenances()->list('map.example.org');

        self::assertSame('GET https://apiv2.myracloud.com/domain/1140/map.example.org/maintenances', $this->sent()[1]);
        self::assertCount(1, $list);
        self::assertFalse($list[0]->isActive());
    }

    public function testReadsOnePage(): void
    {
        $this->http->queue(Fixture::page('data', [], 2, 50, 0));

        $this->client->maintenances()->page('map.example.org', 2);

        self::assertSame('GET https://apiv2.myracloud.com/domain/1140/map.example.org/maintenances?page=2', $this->sent()[1]);
    }

    public function testCreatesAsCaptured(): void
    {
        $this->http->queue(Fixture::response('v2/maintenance/create'));

        $created = $this->client->maintenances()->create(self::probe());

        self::assertSame('POST https://apiv2.myracloud.com/domain/1140/map.example.org/maintenances', $this->sent()[1]);
        self::assertSame(Fixture::requestBody('v2/maintenance/create'), (string) $this->http->lastRequest()->getBody());
        self::assertFalse($created->isActive());
    }

    public function testUpdatesWithTheCompleteObjectAsCaptured(): void
    {
        $this->http->queue(Fixture::response('v2/maintenance/create'), Fixture::response('v2/maintenance/update'));
        $created = $this->client->maintenances()->create(self::probe());

        $this->client->maintenances()->update($created->withContent(self::sent_('v2/maintenance/update', 'content')));

        self::assertSame('PUT https://apiv2.myracloud.com/domain/1140/map.example.org/maintenances/' . $created->id() . '', $this->sent()[2]);
        self::assertSame(Fixture::requestBody('v2/maintenance/update'), (string) $this->http->lastRequest()->getBody());
    }

    public function testAStaleVersionIsRejected(): void
    {
        $this->http->queue(Fixture::response('v2/maintenance/create'), Fixture::response('v2/maintenance/update-stale'));
        $created = $this->client->maintenances()->create(self::probe());

        $this->expectException(RequestRejectedException::class);
        $this->expectExceptionMessage('The record has been edited in the meantime.');

        $this->client->maintenances()->update($created->withContent('x'));
    }

    public function testDeletesAsCaptured(): void
    {
        $this->http->queue(Fixture::response('v2/maintenance/create'), Fixture::response('v2/maintenance/update'), Fixture::response('v2/maintenance/delete'));
        $created = $this->client->maintenances()->create(self::probe());
        $updated = $this->client->maintenances()->update($created->withContent(self::sent_('v2/maintenance/update', 'content')));

        $this->client->maintenances()->delete($updated);

        self::assertSame('DELETE https://apiv2.myracloud.com/domain/1140/map.example.org/maintenances/' . $updated->id() . '', $this->sent()[3]);
        self::assertSame('', (string) $this->http->lastRequest()->getBody());
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

    public function testGetsOneAndListsTheDomain(): void
    {
        $this->http->queue(Fixture::response('v2/maintenance/get'), Fixture::response('v2/maintenance/for-domain'));

        $one = $this->client->maintenances()->get('example.org', 90003);
        $all = $this->client->maintenances()->forDomain('example.org', true);

        self::assertSame(['GET https://apiv2.myracloud.com/domain/1140/maintenances/90003', 'GET https://apiv2.myracloud.com/domain/1140/maintenances?presentAndFuture=true'], array_slice($this->sent(), 1));
        self::assertSame($one->id(), $all[0]->id());
    }

    public function testListsTheDomainWithoutAndWithPastFilter(): void
    {
        $this->http->queue(Fixture::page('data', [], 1, 50, 0), Fixture::page('data', [], 1, 50, 0));

        $this->client->maintenances()->forDomain('example.org');
        $this->client->maintenances()->forDomain('example.org', false);

        self::assertSame(['GET https://apiv2.myracloud.com/domain/1140/maintenances', 'GET https://apiv2.myracloud.com/domain/1140/maintenances?presentAndFuture=false'], array_slice($this->sent(), 1));
    }

    public function testManagesTemplatesAsCaptured(): void
    {
        $this->http->queue(Fixture::response('v2/maintenance-templates/create'), Fixture::response('v2/maintenance-templates/update'), Fixture::response('v2/maintenance-templates/list'), Fixture::response('v2/maintenance-templates/delete'));
        $templates = $this->client->maintenanceTemplates();

        $created = $templates->create('example.org', new MaintenanceTemplate('__probe__', self::sent_('v2/maintenance-templates/create', 'content')));
        self::assertSame(Fixture::requestBody('v2/maintenance-templates/create'), (string) $this->http->lastRequest()->getBody());
        $updated = $templates->update('example.org', $created->withContent(self::sent_('v2/maintenance-templates/update', 'content')));
        self::assertSame(Fixture::requestBody('v2/maintenance-templates/update'), (string) $this->http->lastRequest()->getBody());
        self::assertCount(1, $templates->list('example.org'));
        $templates->delete('example.org', $updated);

        self::assertSame([
            'POST https://apiv2.myracloud.com/domain/1140/maintenance-templates',
            'PUT https://apiv2.myracloud.com/domain/1140/maintenance-templates/' . $created->id(),
            'GET https://apiv2.myracloud.com/domain/1140/maintenance-templates',
            'DELETE https://apiv2.myracloud.com/domain/1140/maintenance-templates/' . $created->id(),
        ], array_slice($this->sent(), 1));
    }

    public function testGuardsTemplateWrites(): void
    {
        $templates = $this->client->maintenanceTemplates();
        foreach ([fn() => $templates->update('example.org', new MaintenanceTemplate('a', 'b')), fn() => $templates->delete('example.org', new MaintenanceTemplate('a', 'b')), fn() => $templates->create('example.org', new MaintenanceTemplate('a', 'b', 1, 'm'))] as $write) {
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
        return new Maintenance('map.example.org', '2099-01-01T00:00:00+0000', '2099-01-01T01:00:00+0000', self::sent_('v2/maintenance/create', 'content'), false);
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
