<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;
use Myracloud\WebApi\Model\CacheClear;
use Myracloud\WebApi\Model\DnsRecord;
use Myracloud\WebApi\Model\Domain;
use Myracloud\WebApi\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Domain::class)]
#[CoversClass(DnsRecord::class)]
#[CoversClass(CacheClear::class)]
#[UsesClass(JsonObject::class)]
#[UsesClass(TransportException::class)]
final class ModelsTest extends TestCase
{
    public function testReadsADomain(): void
    {
        $raw = self::items('v2/domains/list', 'data')[0];
        $domain = Domain::fromJson(new JsonObject($raw));

        self::assertSame(1000, $domain->id());
        self::assertSame('example.com', $domain->name());
        self::assertSame($raw, $domain->raw());
    }

    public function testReadsADnsRecord(): void
    {
        $raw = self::items('v1/dns-records/list', 'list')[0];
        $record = DnsRecord::fromJson(new JsonObject($raw));

        self::assertSame(1021, $record->id());
        self::assertSame('example.com', $record->name());
        self::assertSame('A', $record->recordType());
        self::assertSame('192.0.2.10', $record->value());
        self::assertSame(300, $record->ttl());
        self::assertTrue($record->isActive());
        self::assertTrue($record->isEnabled());
        self::assertSame('example-com.cdn.example.net.', $record->alternativeCname());
        self::assertSame($raw, $record->raw());
    }

    public function testReadsADisabledDnsRecord(): void
    {
        $record = DnsRecord::fromJson(new JsonObject(self::items('v2/dns-records/list', 'data')[5]));

        self::assertSame('map.example.com', $record->name());
        self::assertTrue($record->isActive());
        self::assertFalse($record->isEnabled());
    }

    public function testADnsRecordNeedsNoAlternativeCname(): void
    {
        $record = DnsRecord::fromJson(new JsonObject(['id' => 1, 'name' => 'mx.example.com', 'recordType' => 'MX', 'value' => 'mail.example.com', 'ttl' => 300, 'active' => false, 'enabled' => true]));

        self::assertNull($record->alternativeCname());
        self::assertFalse($record->isActive());
    }

    public function testADnsRecordWithoutAModelledFieldIsAnUnexpectedResponse(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Unexpected response: enabled is missing.');

        DnsRecord::fromJson(new JsonObject(['id' => 1, 'name' => 'x', 'recordType' => 'A', 'value' => '192.0.2.1', 'ttl' => 300, 'active' => true]));
    }

    public function testReadsASingleCacheClear(): void
    {
        $raw = self::items('v1/cache-clear/single', 'targetObject')[0];
        $clear = CacheClear::fromJson(new JsonObject($raw));

        self::assertSame('example.com', $clear->fqdn());
        self::assertSame('/__probe__/nothing.txt', $clear->resource());
        self::assertFalse($clear->isRecursive());
        self::assertSame([], $clear->bulk());
        self::assertSame($raw, $clear->raw());
    }

    public function testReadsABulkCacheClear(): void
    {
        $clear = CacheClear::fromJson(new JsonObject(self::items('v2/cache-clear/bulk', 'data')[0]));

        self::assertSame(['/__probe__/a.txt', '/__probe__/b.txt'], $clear->bulk());
        self::assertSame('/__probe__/b.txt', $clear->resource());
    }

    public function testACacheClearNeedsNeitherResourceNorBulk(): void
    {
        $clear = CacheClear::fromJson(new JsonObject(['fqdn' => 'www.example.com', 'recursive' => true]));

        self::assertNull($clear->resource());
        self::assertSame([], $clear->bulk());
        self::assertTrue($clear->isRecursive());
    }

    public function testModelsCanBeBuiltWithoutAnAnswer(): void
    {
        self::assertSame([], (new Domain(1, 'example.com'))->raw());
        self::assertSame([], (new DnsRecord(1, 'example.com', 'A', '192.0.2.1', 300, true, true))->raw());
        self::assertSame([], (new CacheClear('example.com', '/*', true))->raw());
    }

    /**
     * @return list<array<mixed>>
     */
    private static function items(string $fixture, string $key): array
    {
        $body = Fixture::load($fixture)['response']['body'];
        self::assertIsArray($body);
        $items = $body[$key];
        self::assertIsArray($items);
        self::assertTrue(array_is_list($items));
        foreach ($items as $item) {
            self::assertIsArray($item);
        }
        /** @var list<array<mixed>> $items */
        return $items;
    }
}
