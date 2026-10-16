<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;
use Myracloud\WebApi\Model\Settings;
use Myracloud\WebApi\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Settings::class)]
#[UsesClass(JsonObject::class)]
#[UsesClass(TransportException::class)]
final class SettingsTest extends TestCase
{
    public function testReadsOwnInheritedAndLayers(): void
    {
        $raw = self::body('v1/settings/get-www');
        $settings = Settings::fromJson(new JsonObject($raw), 'www.example.com');

        self::assertSame('www.example.com', $settings->subdomain());
        self::assertSame(['antibot_post_flood', 'antibot_post_flood_threshold'], array_keys($settings->own()));
        self::assertCount(94, $settings->inherited());
        self::assertSame([93, 0, 12, 0, 2], array_map('count', $settings->layers()));
        self::assertSame($settings->own(), $settings->layers()[4]);
        self::assertArrayNotHasKey('objectType', $settings->layers()[0]);
        self::assertSame($raw, $settings->raw());
    }

    public function testOwnSettingsWinOverInheritedOnes(): void
    {
        $settings = new Settings('www.example.com', ['hsts' => true, 'gone' => null], ['hsts' => false, 'access_log' => false]);

        self::assertTrue($settings->value('hsts'));
        self::assertFalse($settings->value('access_log'));
        self::assertNull($settings->value('gone'));
        self::assertNull($settings->value('unknown'));
        self::assertTrue($settings->has('gone'));
        self::assertTrue($settings->has('access_log'));
        self::assertFalse($settings->has('unknown'));
        self::assertSame([], $settings->layers());
        self::assertSame([], $settings->raw());
    }

    public function testNoOwnSettingsComeAsAnEmptyList(): void
    {
        $settings = Settings::fromJson(new JsonObject(self::body('v2/settings/get-empty')), 'map.example.org');

        self::assertSame([], $settings->own());
        self::assertCount(94, $settings->inherited());
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
