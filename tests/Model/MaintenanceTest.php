<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\Model;

use Myracloud\WebApi\Http\JsonObject;
use Myracloud\WebApi\Model\Maintenance;
use Myracloud\WebApi\Model\MaintenanceTemplate;
use Myracloud\WebApi\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Maintenance::class)]
#[CoversClass(MaintenanceTemplate::class)]
#[UsesClass(JsonObject::class)]
final class MaintenanceTest extends TestCase
{
    public function testReadsTheCapturedMaintenance(): void
    {
        $raw = self::first('v2/maintenance/get');
        $maintenance = Maintenance::fromJson(new JsonObject($raw));

        self::assertSame(['map.example.org', '2099-01-01T01:00:00+0100', '2099-01-01T02:00:00+0100', false], [$maintenance->subdomain(), $maintenance->start(), $maintenance->end(), $maintenance->isActive()]);
        self::assertNotNull($maintenance->content());
        self::assertNotNull($maintenance->id());
        self::assertNotNull($maintenance->modified());
        self::assertNotNull($maintenance->created());
        self::assertSame($raw, $maintenance->raw());
    }

    public function testANewMaintenanceSendsWhatIsSet(): void
    {
        $new = new Maintenance('www.example.com', '2099-01-01T00:00:00+0000');

        self::assertSame(['fqdn' => 'www.example.com', 'start' => '2099-01-01T00:00:00+0000'], $new->toJson(false));
        self::assertSame(['fqdn' => 'www.example.com', 'start' => '2099-01-01T00:00:00+0000', 'active' => false], $new->toJson(true));
        self::assertSame(
            ['fqdn' => 'www.example.com', 'start' => 's', 'end' => 'e', 'content' => '<html/>', 'active' => true],
            (new Maintenance('www.example.com', 's', 'e', '<html/>', true))->toJson(true),
        );
    }

    /**
     * Read before: the complete object goes back — without objectType, created, permissions,
     * and without active where v1 generates it.
     */
    public function testAnUpdateSendsTheCompleteObject(): void
    {
        $raw = self::first('v2/maintenance/get');
        $maintenance = Maintenance::fromJson(new JsonObject($raw))->withContent('<html>x</html>');

        $v2 = $maintenance->toJson(true);
        self::assertSame('<html>x</html>', $v2['content']);
        self::assertSame([$raw['defaultPage'], $raw['vhostId'], $raw['id'], $raw['modified'], false], [$v2['defaultPage'], $v2['vhostId'], $v2['id'], $v2['modified'], $v2['active']]);
        self::assertSame([], array_intersect_key($v2, ['objectType' => 1, 'created' => 1, 'permissions' => 1]));
        self::assertArrayNotHasKey('active', $maintenance->toJson(false));
    }

    public function testChangesKeepIdentityAndVersion(): void
    {
        $original = Maintenance::fromJson(new JsonObject(self::first('v2/maintenance/get')));
        $changed = $original->withStart('a')->withEnd('b')->withContent('c')->withActive(true);

        self::assertSame(['a', 'b', 'c', true], [$changed->start(), $changed->end(), $changed->content(), $changed->isActive()]);
        self::assertSame([$original->subdomain(), $original->id(), $original->modified(), $original->created(), $original->raw()], [$changed->subdomain(), $changed->id(), $changed->modified(), $changed->created(), $changed->raw()]);
        self::assertNull($changed->withEnd(null)->end());
        self::assertNull($changed->withContent(null)->content());
    }

    public function testActiveMayBeMissing(): void
    {
        $raw = self::first('v2/maintenance/get');
        unset($raw['active']);

        self::assertFalse(Maintenance::fromJson(new JsonObject($raw))->isActive());
    }

    public function testReadsAndChangesATemplate(): void
    {
        $raw = self::first('v2/maintenance-templates/create');
        $template = MaintenanceTemplate::fromJson(new JsonObject($raw));
        $changed = $template->withName('n')->withContent('<html>c</html>');

        self::assertSame('__probe__', $template->name());
        self::assertNotNull($template->id());
        self::assertSame($raw, $template->raw());
        self::assertSame(['n', '<html>c</html>', $template->id(), $template->modified()], [$changed->name(), $changed->content(), $changed->id(), $changed->modified()]);
        // read before: Myra's field order (id and modified first), as sent and captured
        self::assertSame(['id' => $template->id(), 'modified' => $template->modified(), 'name' => 'n', 'content' => '<html>c</html>'], $changed->toJson());
        self::assertSame(['name' => 'a', 'content' => 'b'], (new MaintenanceTemplate('a', 'b'))->toJson());
        self::assertSame([null, null, []], [(new MaintenanceTemplate('a', 'b'))->id(), (new MaintenanceTemplate('a', 'b'))->modified(), (new MaintenanceTemplate('a', 'b'))->raw()]);
    }

    /**
     * @return array<mixed>
     */
    private static function first(string $fixture): array
    {
        $body = Fixture::load($fixture)['response']['body'];
        self::assertIsArray($body);
        $items = $body['data'] ?? $body['targetObject'];
        self::assertIsArray($items);
        self::assertIsArray($items[0]);

        return $items[0];
    }
}
