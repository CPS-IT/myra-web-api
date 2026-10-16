<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\Model;

use Myracloud\WebApi\Http\JsonObject;
use Myracloud\WebApi\Model\CacheSetting;
use Myracloud\WebApi\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheSetting::class)]
#[UsesClass(JsonObject::class)]
final class CacheSettingTest extends TestCase
{
    public function testReadsTheCapturedRule(): void
    {
        $raw = self::created();
        $setting = CacheSetting::fromJson(new JsonObject($raw), 'map.example.org');

        self::assertSame(
            ['map.example.org', '/__probe__/', -1, 'exact', 60, false, 999, false, null],
            [$setting->subdomain(), $setting->path(), $setting->ttl(), $setting->type(), $setting->notFoundTtl(), $setting->isEnforced(), $setting->sort(), $setting->isEnabled(), $setting->comment()],
        );
        self::assertNotNull($setting->id());
        self::assertNotNull($setting->modified());
        self::assertNotNull($setting->created());
        self::assertSame($raw, $setting->raw());
    }

    public function testNotFoundTtlMayBeMissing(): void
    {
        $raw = self::created();
        unset($raw['notFoundTtl']);

        self::assertNull(CacheSetting::fromJson(new JsonObject($raw), 'map.example.org')->notFoundTtl());
    }

    public function testANewRuleSendsOnlyWhatIsSet(): void
    {
        self::assertSame(
            ['path' => '/', 'ttl' => 300, 'type' => 'prefix', 'enforce' => false, 'sort' => 0, 'enabled' => true],
            (new CacheSetting('www.example.com', '/', 300))->toJson(),
        );
        self::assertSame(
            ['path' => '/a', 'ttl' => 60, 'type' => 'suffix', 'notFoundTtl' => 5, 'enforce' => true, 'sort' => 3, 'enabled' => false, 'comment' => 'c'],
            (new CacheSetting('www.example.com', '/a', 60, CacheSetting::TYPE_SUFFIX, 5, true, 3, false, 'c'))->toJson(),
        );
    }

    public function testChangesKeepIdentityAndVersion(): void
    {
        $original = CacheSetting::fromJson(new JsonObject(self::created()), 'map.example.org');
        $changed = $original->withPath('/b')->withTtl(5)->withType(CacheSetting::TYPE_PREFIX)->withNotFoundTtl(10)->withEnforce(true)->withSort(1)->withEnabled(true)->withComment('c');

        self::assertSame(['/b', 5, 'prefix', 10, true, 1, true, 'c'], [$changed->path(), $changed->ttl(), $changed->type(), $changed->notFoundTtl(), $changed->isEnforced(), $changed->sort(), $changed->isEnabled(), $changed->comment()]);
        self::assertSame([$original->subdomain(), $original->id(), $original->modified(), $original->created(), $original->raw()], [$changed->subdomain(), $changed->id(), $changed->modified(), $changed->created(), $changed->raw()]);
        self::assertSame(['id' => $original->id(), 'modified' => $original->modified()], array_intersect_key($changed->toJson(), ['id' => 0, 'modified' => 0]));
        self::assertNull($changed->withComment(null)->comment());
        self::assertNull($changed->withNotFoundTtl(null)->notFoundTtl());
        self::assertSame('c', $changed->withNotFoundTtl(null)->comment());
        self::assertSame(10, $changed->withComment(null)->notFoundTtl());
    }

    /**
     * @return array<mixed>
     */
    private static function created(): array
    {
        $body = Fixture::load('v1/cachesettings/create')['response']['body'];
        self::assertIsArray($body);
        self::assertIsArray($body['targetObject']);
        self::assertIsArray($body['targetObject'][0]);

        return $body['targetObject'][0];
    }
}
