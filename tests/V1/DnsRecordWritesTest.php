<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\V1;

use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Exception\RequestRejectedException;
use Myracloud\WebApi\Model\DnsRecord;
use Myracloud\WebApi\Tests\Support\FakeHttpClient;
use Myracloud\WebApi\Tests\Support\Fixture;
use Myracloud\WebApi\V1;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * DNS writes and load balancing as captured (probe: TXT __probe__.<domain>, A record → 192.0.2.1).
 */
#[CoversClass(V1\DnsRecords::class)]
#[UsesClass(V1\Client::class)]
#[UsesClass(V1\Domains::class)]
#[UsesClass(V1\CacheClearing::class)]
#[UsesClass(V1\Redirects::class)]
#[UsesClass(V1\IpFilters::class)]
#[UsesClass(V1\CacheSettings::class)]
#[UsesClass(V1\SubdomainSettings::class)]
#[UsesClass(V1\Maintenances::class)]
#[UsesClass(V1\ErrorPages::class)]
#[UsesClass(V1\IpRanges::class)]
#[UsesClass(V1\Statistics::class)]
#[UsesClass(V1\SslCertificates::class)]
#[UsesClass(V1\Zones::class)]
#[UsesClass(\Myracloud\WebApi\Auth\BearerToken::class)]
#[UsesClass(\Myracloud\WebApi\Http\Transport::class)]
#[UsesClass(\Myracloud\WebApi\Http\Envelope::class)]
#[UsesClass(\Myracloud\WebApi\Http\JsonObject::class)]
#[UsesClass(\Myracloud\WebApi\Model\Page::class)]
#[UsesClass(\Myracloud\WebApi\Model\DnsRecord::class)]
#[UsesClass(\Myracloud\WebApi\Model\UpstreamOptions::class)]
#[UsesClass(\Myracloud\WebApi\Model\Persisted::class)]
#[UsesClass(\Myracloud\WebApi\Model\DomainName::class)]
#[UsesClass(\Myracloud\WebApi\Model\Domain::class)]
#[UsesClass(\Myracloud\WebApi\Model\Violation::class)]
#[UsesClass(\Myracloud\WebApi\Exception\RequestRejectedException::class)]
#[UsesClass(\Myracloud\WebApi\Exception\TransportException::class)]
final class DnsRecordWritesTest extends TestCase
{
    private FakeHttpClient $http;
    private V1\DnsRecords $records;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->records = (new V1\Client(new BearerToken('t'), httpClient: $this->http))->dnsRecords();
    }

    public function testListsTheLoadBalancedRecords(): void
    {
        $this->http->queue(Fixture::response('v1/dns-records/load-balancing'));

        $records = $this->records->loadBalancing('example.com');

        self::assertSame(['GET https://api.myracloud.com/en/rapi/dnsRecords/example.com/1?loadbalancer=true&recordTypes=A,AAAA,CNAME'], $this->sent());
        self::assertCount(6, $records);
        self::assertNotNull($records[0]->upstreamOptions());
    }

    public function testCreatesAsCaptured(): void
    {
        $this->http->queue(Fixture::response('v1/dns-records/create'));

        $created = $this->records->create('example.org', self::txt());

        self::assertSame('PUT https://api.myracloud.com/en/rapi/dnsRecords/example.org', $this->sent()[0]);
        self::assertSame(Fixture::requestBody('v1/dns-records/create'), (string) $this->http->lastRequest()->getBody());
        self::assertNull($created->upstreamOptions()); // partial UpstreamOptionsVO of a TXT record: raw only
        self::assertArrayHasKey('upstreamOptions', $created->raw());
    }

    /**
     * The update sends the complete record as read, with the change on top.
     */
    public function testUpdatesAsCaptured(): void
    {
        $this->http->queue(Fixture::response('v1/dns-records/create'), Fixture::response('v1/dns-records/update'));
        $created = $this->records->create('example.org', self::txt());

        $this->records->update('example.org', $created->withValue(self::sentValue('v1/dns-records/update')));

        self::assertSame('POST https://api.myracloud.com/en/rapi/dnsRecords/example.org', $this->sent()[1]);
        self::assertSame(Fixture::requestBody('v1/dns-records/update'), (string) $this->http->lastRequest()->getBody());
    }

    public function testAStaleVersionIsRejected(): void
    {
        $this->http->queue(Fixture::response('v1/dns-records/create'), Fixture::response('v1/dns-records/update-stale'));
        $created = $this->records->create('example.org', self::txt());

        $this->expectException(RequestRejectedException::class);
        $this->expectExceptionMessage('The record has been edited in the meantime.');

        $this->records->update('example.org', $created->withValue('x'));
    }

    public function testChangesLoadBalancingThroughTheUpdate(): void
    {
        $this->http->queue(Fixture::response('v1/dns-records/create-a'), Fixture::response('v1/dns-records/update-load-balancing'));
        $created = $this->records->create('example.org', self::a());
        $options = $created->upstreamOptions();
        self::assertNotNull($options);

        $updated = $this->records->update('example.org', $created->withUpstreamOptions($options->withWeight(2)));

        self::assertSame(Fixture::requestBody('v1/dns-records/update-load-balancing'), (string) $this->http->lastRequest()->getBody());
        self::assertSame(2, $updated->upstreamOptions()?->weight());
    }

    public function testDeletesAsCaptured(): void
    {
        $this->http->queue(Fixture::response('v1/dns-records/create'), Fixture::response('v1/dns-records/delete'));
        $created = $this->records->create('example.org', self::txt());

        $this->records->delete('example.org', $created);

        self::assertSame('DELETE https://api.myracloud.com/en/rapi/dnsRecords/example.org', $this->sent()[1]);
        self::assertSame('{"id":' . $created->id() . ',"modified":"' . $created->modified() . '"}', (string) $this->http->lastRequest()->getBody());
    }

    public function testGuardsWritesBeforeSending(): void
    {
        $persisted = new DnsRecord('x.example.org', 'A', '192.0.2.1', id: 7, modified: 'm');
        foreach ([fn() => $this->records->update('example.org', self::txt()), fn() => $this->records->delete('example.org', self::txt()), fn() => $this->records->create('example.org', $persisted)] as $write) {
            try {
                $write();
                self::fail('No exception thrown.');
            } catch (InvalidArgumentException) {
            }
        }
        self::assertSame([], $this->http->requests());
    }

    private static function txt(): DnsRecord
    {
        return new DnsRecord('__probe__.example.org', 'TXT', self::sentValue('v1/dns-records/create'), 300, comment: '__probe__');
    }

    private static function a(): DnsRecord
    {
        return new DnsRecord('myra-web-api-probe.example.org', 'A', '192.0.2.1', 300, comment: '__probe__');
    }

    /**
     * The (anonymised) value the capture sent.
     */
    private static function sentValue(string $fixture): string
    {
        $body = Fixture::load($fixture)['request']['body'];
        self::assertIsArray($body);
        self::assertIsString($body['value']);

        return $body['value'];
    }

    /**
     * @return list<string>
     */
    private function sent(): array
    {
        return array_map(static fn($r): string => $r->getMethod() . ' ' . $r->getUri(), $this->http->requests());
    }
}
