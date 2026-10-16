<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\Model;

use Myracloud\WebApi\Http\JsonObject;
use Myracloud\WebApi\Model\DnsRecord;
use Myracloud\WebApi\Model\UpstreamOptions;
use Myracloud\WebApi\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DnsRecord::class)]
#[CoversClass(UpstreamOptions::class)]
#[UsesClass(JsonObject::class)]
final class DnsRecordTest extends TestCase
{
    public function testReadsAllModelledFields(): void
    {
        $raw = self::captured();
        $record = DnsRecord::fromJson(new JsonObject($raw));

        self::assertSame(
            ['myra-web-api-probe.example.org', 'A', '192.0.2.1', 300, false, true, 0, '__probe__'],
            [$record->name(), $record->recordType(), $record->value(), $record->ttl(), $record->isActive(), $record->isEnabled(), $record->priority(), $record->comment()],
        );
        self::assertNotNull($record->id());
        self::assertNotNull($record->modified());
        self::assertNotNull($record->created());
        self::assertNotNull($record->alternativeCname());
        self::assertSame($raw, $record->raw());
    }

    public function testANewRecordSendsItsFields(): void
    {
        self::assertSame(
            ['name' => 'www.example.com', 'recordType' => 'A', 'value' => '192.0.2.1', 'ttl' => 300, 'active' => false, 'enabled' => true, 'priority' => 0],
            (new DnsRecord('www.example.com', 'A', '192.0.2.1'))->toJson(),
        );
        self::assertSame(
            ['name' => 'mx.example.com', 'recordType' => 'MX', 'value' => 'mail.example.com', 'ttl' => 600, 'active' => true, 'enabled' => false, 'priority' => 10, 'comment' => 'c',
                'upstreamOptions' => ['weight' => 1, 'maxFails' => 100, 'failTimeout' => '1', 'backup' => false, 'down' => false]],
            (new DnsRecord('mx.example.com', 'MX', 'mail.example.com', 600, true, false, 10, 'c', new UpstreamOptions()))->toJson(),
        );
    }

    /**
     * Read before: the complete object goes back (without objectType and created), changes on top.
     */
    public function testAnUpdateSendsTheCompleteRecord(): void
    {
        $raw = self::captured();
        $json = DnsRecord::fromJson(new JsonObject($raw))->withValue('192.0.2.2')->toJson();

        self::assertSame('192.0.2.2', $json['value']);
        self::assertSame($raw['endpoints'], $json['endpoints']);
        self::assertSame($raw['serviceType'], $json['serviceType']);
        self::assertSame([$raw['id'], $raw['modified']], [$json['id'], $json['modified']]);
        self::assertArrayNotHasKey('objectType', $json);
        self::assertArrayNotHasKey('created', $json);
        self::assertSame(array_keys(array_diff_key($raw, ['objectType' => 1, 'created' => 1])), array_keys($json));
    }

    public function testChangesKeepIdentityAndVersion(): void
    {
        $original = DnsRecord::fromJson(new JsonObject(self::captured()));
        $options = new UpstreamOptions(2);
        $changed = $original->withValue('192.0.2.9')->withTtl(600)->withActive(true)->withEnabled(false)->withPriority(5)->withComment('c')->withUpstreamOptions($options);

        self::assertSame(['192.0.2.9', 600, true, false, 5, 'c', $options], [$changed->value(), $changed->ttl(), $changed->isActive(), $changed->isEnabled(), $changed->priority(), $changed->comment(), $changed->upstreamOptions()]);
        self::assertSame(
            [$original->name(), $original->recordType(), $original->alternativeCname(), $original->id(), $original->modified(), $original->created(), $original->raw()],
            [$changed->name(), $changed->recordType(), $changed->alternativeCname(), $changed->id(), $changed->modified(), $changed->created(), $changed->raw()],
        );
        self::assertNull($changed->withComment(null)->comment());
        self::assertNull($changed->withUpstreamOptions(null)->upstreamOptions());
        self::assertSame($options, $changed->withComment(null)->upstreamOptions());
        self::assertSame('c', $changed->withUpstreamOptions(null)->comment());
    }

    public function testReadsAndChangesUpstreamOptions(): void
    {
        $options = DnsRecord::fromJson(new JsonObject(self::captured()))->upstreamOptions();
        self::assertNotNull($options);
        $changed = $options->withWeight(2)->withMaxFails(3)->withFailTimeout('10')->withBackup(true)->withDown(true);

        self::assertSame([2, 3, '10', true, true], [$changed->weight(), $changed->maxFails(), $changed->failTimeout(), $changed->isBackup(), $changed->isDown()]);
        self::assertSame([$options->id(), $options->modified(), $options->raw()], [$changed->id(), $changed->modified(), $changed->raw()]);
        self::assertSame(['weight' => 2, 'maxFails' => 3, 'failTimeout' => '10', 'backup' => true, 'down' => true, 'id' => $options->id(), 'modified' => $options->modified()], $changed->toJson());
        self::assertSame(['weight' => 1, 'maxFails' => 100, 'failTimeout' => '1', 'backup' => false, 'down' => false], (new UpstreamOptions())->toJson());
        self::assertSame([null, null, []], [(new UpstreamOptions())->id(), (new UpstreamOptions())->modified(), (new UpstreamOptions())->raw()]);
    }

    /**
     * @return array<mixed>
     */
    private static function captured(): array
    {
        $body = Fixture::load('v1/dns-records/create-a')['response']['body'];
        self::assertIsArray($body);
        self::assertIsArray($body['targetObject']);
        self::assertIsArray($body['targetObject'][0]);

        return $body['targetObject'][0];
    }
}
