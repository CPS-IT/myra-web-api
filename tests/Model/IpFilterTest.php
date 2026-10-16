<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\Model;

use Myracloud\WebApi\Http\JsonObject;
use Myracloud\WebApi\Model\IpFilter;
use Myracloud\WebApi\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IpFilter::class)]
#[UsesClass(JsonObject::class)]
final class IpFilterTest extends TestCase
{
    public function testReadsTheCapturedEntries(): void
    {
        $items = self::items('v2/ipfilter/list-with-imports');
        $filters = array_map(static fn(array $i): IpFilter => IpFilter::fromJson(new JsonObject($i)), $items);
        $byValue = array_combine(array_map(static fn(IpFilter $f): string => $f->value(), $filters), $filters);

        $probe = $byValue['192.0.2.1/32'];
        self::assertSame(['map.example.org', IpFilter::TYPE_BLACKLIST, false, '__probe__ updated', null], [$probe->subdomain(), $probe->type(), $probe->isEnabled(), $probe->comment(), $probe->expireDate()]);
        self::assertNotNull($probe->id());
        self::assertNotNull($probe->modified());
        self::assertNotNull($probe->created());
        self::assertSame('2099-12-31T23:59:59+0100', $byValue['192.0.2.2/32']->expireDate());
        self::assertTrue($byValue['192.0.2.2/32']->isEnabled());
        self::assertSame($items[0], $filters[0]->raw());
    }

    public function testANewEntrySendsOnlyWhatIsSet(): void
    {
        self::assertSame(
            ['subDomainName' => 'www.example.com', 'type' => 'BLACKLIST', 'value' => '192.0.2.0/24', 'enabled' => true],
            (new IpFilter('www.example.com', IpFilter::TYPE_BLACKLIST, '192.0.2.0/24'))->toJson(),
        );
        self::assertSame(
            ['subDomainName' => 'www.example.com', 'type' => 'WHITELIST', 'value' => '192.0.2.1', 'enabled' => false, 'comment' => 'c', 'expireDate' => '2099-01-01T00:00:00+0000'],
            (new IpFilter('www.example.com', IpFilter::TYPE_WHITELIST, '192.0.2.1', false, 'c', '2099-01-01T00:00:00+0000'))->toJson(),
        );
    }

    public function testChangesKeepIdentityAndVersion(): void
    {
        $original = IpFilter::fromJson(new JsonObject(self::items('v2/ipfilter/get')[0]));
        $changed = $original->withType(IpFilter::TYPE_BLACKLIST)->withValue('192.0.2.9/32')->withEnabled(false)->withComment('x')->withExpireDate('2099-01-01T00:00:00+0000');

        self::assertSame(['BLACKLIST', '192.0.2.9/32', false, 'x', '2099-01-01T00:00:00+0000'], [$changed->type(), $changed->value(), $changed->isEnabled(), $changed->comment(), $changed->expireDate()]);
        self::assertSame([$original->subdomain(), $original->id(), $original->modified(), $original->created(), $original->raw()], [$changed->subdomain(), $changed->id(), $changed->modified(), $changed->created(), $changed->raw()]);
        self::assertSame(['id' => $original->id(), 'modified' => $original->modified()], array_intersect_key($changed->toJson(), ['id' => 0, 'modified' => 0]));
        self::assertNull($changed->withComment(null)->withExpireDate(null)->comment());
        self::assertSame('x', $changed->withExpireDate(null)->comment());
        self::assertSame('2099-01-01T00:00:00+0000', $changed->withComment(null)->expireDate());
    }

    /**
     * @return list<array<mixed>>
     */
    private static function items(string $fixture): array
    {
        $body = Fixture::load($fixture)['response']['body'];
        self::assertIsArray($body);
        $items = $body['data'] ?? $body['list'];
        self::assertIsArray($items);
        /** @var list<array<mixed>> $items */
        return $items;
    }
}
