<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\Model;

use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Http\JsonObject;
use Myracloud\WebApi\Model\DomainName;
use Myracloud\WebApi\Model\ErrorPage;
use Myracloud\WebApi\Model\ErrorPageSelection;
use Myracloud\WebApi\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ErrorPage::class)]
#[CoversClass(ErrorPageSelection::class)]
#[UsesClass(JsonObject::class)]
#[UsesClass(DomainName::class)]
final class ErrorPageTest extends TestCase
{
    public function testReadsAPageWithItsContent(): void
    {
        $body = Fixture::load('v2/errorpages/get')['response']['body'];
        self::assertIsArray($body);
        self::assertIsArray($body['data']);
        self::assertIsArray($body['data'][0]);
        $page = ErrorPage::fromJson(new JsonObject($body['data'][0]));

        self::assertSame(['example.com', 502, 90400], [$page->subdomain(), $page->errorCode(), $page->id()]);
        self::assertNotSame('', $page->content());
        self::assertNotNull($page->modified());
        self::assertNotNull($page->created());
        self::assertSame($body['data'][0], $page->raw());
    }

    public function testTakesTheSubdomainFromTheGroupInV1(): void
    {
        $page = ErrorPage::fromJson(new JsonObject(['errorCode' => 413, 'id' => 1]), 'www.example.com');

        self::assertSame(['www.example.com', 413, '', null, null], [$page->subdomain(), $page->errorCode(), $page->content(), $page->modified(), $page->created()]);
    }

    public function testBuildsTheSelection(): void
    {
        self::assertSame(
            ['www.example.com' => [500 => true, 503 => true], 'example.com' => [9999 => true]],
            ErrorPageSelection::toJson(['WWW.example.com.' => [500, 503], 'example.com' => [9999]]),
        );
        self::assertSame('{"www.example.com":{"413":true}}', json_encode(ErrorPageSelection::toJson(['www.example.com' => [413]])));
    }

    public function testRejectsUnsupportedCodes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Myra has no error page for code 418; supported: 400, 404, 405, 413, 429, 500, 502, 503, 504, 9999.');

        ErrorPageSelection::toJson(['www.example.com' => [500, 418]]);
    }

    public function testRejectsAnEmptySelection(): void
    {
        foreach ([[], ['www.example.com' => []]] as $selection) {
            try {
                ErrorPageSelection::toJson($selection);
                self::fail('No exception thrown.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('Select at least one subdomain and error code.', $exception->getMessage());
            }
        }
    }
}
