<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\Model;

use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Http\JsonObject;
use Myracloud\WebApi\Model\Persisted;
use Myracloud\WebApi\Model\Redirect;
use Myracloud\WebApi\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Redirect::class)]
#[CoversClass(Persisted::class)]
#[UsesClass(JsonObject::class)]
final class RedirectTest extends TestCase
{
    public function testReadsTheCapturedRedirect(): void
    {
        $raw = self::captured();
        $redirect = Redirect::fromJson(new JsonObject($raw));

        self::assertSame('example.com', $redirect->subdomain());
        self::assertSame('/path-5', $redirect->source());
        self::assertSame('https://example.com/path-6', $redirect->destination());
        self::assertSame(Redirect::TYPE_REDIRECT, $redirect->type());
        self::assertSame(Redirect::MATCH_EXACT, $redirect->matchingType());
        self::assertSame(1, $redirect->sort());
        self::assertTrue($redirect->isEnabled());
        self::assertFalse($redirect->isExpertMode());
        self::assertNull($redirect->comment());
        self::assertSame(90392, $redirect->id());
        self::assertSame('2018-11-01T14:41:24+0100', $redirect->modified());
        self::assertSame('2018-11-01T14:38:08+0100', $redirect->created());
        self::assertSame($raw, $redirect->raw());
    }

    public function testANewRedirectHasDefaultsAndNoVersion(): void
    {
        $redirect = new Redirect('www.example.com', '/old', 'https://www.example.com/new');

        self::assertNull($redirect->id());
        self::assertNull($redirect->modified());
        self::assertNull($redirect->created());
        self::assertSame([], $redirect->raw());
        self::assertSame([
            'subDomainName' => 'www.example.com',
            'source' => '/old',
            'destination' => 'https://www.example.com/new',
            'type' => 'permanent',
            'matchingType' => 'exact',
            'sort' => 0,
            'enabled' => true,
            'expertMode' => false,
        ], $redirect->toJson());
    }

    public function testAPersistedRedirectSendsItsVersion(): void
    {
        $json = Redirect::fromJson(new JsonObject(self::captured()))->withComment('moved')->toJson();

        self::assertSame(90392, $json['id']);
        self::assertSame('2018-11-01T14:41:24+0100', $json['modified']);
        self::assertSame('moved', $json['comment']);
    }

    public function testChangesKeepIdentityAndVersion(): void
    {
        $original = Redirect::fromJson(new JsonObject(self::captured()));
        $changed = $original
            ->withSource('/a')->withDestination('https://example.com/b')->withType(Redirect::TYPE_PERMANENT)
            ->withMatchingType(Redirect::MATCH_PREFIX)->withSort(5)->withEnabled(false)->withExpertMode(true)->withComment('c');

        self::assertSame(['/a', 'https://example.com/b', 'permanent', 'prefix', 5, false, true, 'c'], [
            $changed->source(), $changed->destination(), $changed->type(), $changed->matchingType(),
            $changed->sort(), $changed->isEnabled(), $changed->isExpertMode(), $changed->comment(),
        ]);
        self::assertSame([$original->subdomain(), $original->id(), $original->modified(), $original->created(), $original->raw()], [$changed->subdomain(), $changed->id(), $changed->modified(), $changed->created(), $changed->raw()]);
        self::assertSame('/path-5', $original->source());
        self::assertNull($changed->withComment(null)->comment());
        self::assertSame('c', $changed->withSort(1)->comment());
    }

    public function testCreateTakesOnlyNewObjects(): void
    {
        Persisted::assertNew(null, 'redirect');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot create a redirect that already has an id (7); update it instead.');

        Persisted::assertNew(7, 'redirect');
    }

    public function testChangesNeedIdAndVersion(): void
    {
        self::assertSame([7, '2026'], Persisted::versionOf(7, '2026', 'redirect'));

        foreach ([[null, '2026'], [7, null]] as [$id, $modified]) {
            try {
                Persisted::versionOf($id, $modified, 'redirect');
                self::fail('No exception thrown.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('A redirect needs id and modified to be changed: use the object Myra returned (list, get or create).', $exception->getMessage());
            }
        }
    }

    /**
     * @return array<mixed>
     */
    private static function captured(): array
    {
        $body = Fixture::load('v2/redirects/list-apex')['response']['body'];
        self::assertIsArray($body);
        self::assertIsArray($body['data']);
        self::assertIsArray($body['data'][0]);

        return $body['data'][0];
    }
}
