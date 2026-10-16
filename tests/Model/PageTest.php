<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\Model;

use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Http\JsonObject;
use Myracloud\WebApi\Model\Page;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Page::class)]
#[UsesClass(JsonObject::class)]
final class PageTest extends TestCase
{
    #[TestWith([0, 10000000000000000, 4, false])]
    #[TestWith([0, 50, 100, false])]
    #[TestWith([1, 50, 0, false])]
    #[TestWith([1, 50, 49, false])]
    #[TestWith([1, 50, 50, false])]
    #[TestWith([1, 50, 51, true])]
    #[TestWith([2, 50, 100, false])]
    #[TestWith([2, 50, 101, true])]
    #[TestWith([3, 50, 101, false])]
    public function testKnowsWhetherAnotherPageFollows(int $page, int $pageSize, int $total, bool $hasNext): void
    {
        self::assertSame($hasNext, (new Page([], $page, $pageSize, $total))->hasNext());
    }

    public function testReadsAnAnswer(): void
    {
        $page = Page::fromJson(
            new JsonObject(['data' => [['id' => 1], ['id' => 2]], 'page' => 2, 'pageSize' => 2, 'count' => 5]),
            'data',
            static fn(JsonObject $item): int => $item->int('id'),
        );

        self::assertSame([1, 2], $page->items());
        self::assertSame(2, $page->page());
        self::assertSame(2, $page->pageSize());
        self::assertSame(5, $page->total());
        self::assertTrue($page->hasNext());
    }

    public function testCollectsAllPagesInOrder(): void
    {
        $requested = [];
        $items = Page::collect(static function (int $number) use (&$requested): Page {
            $requested[] = $number;

            return new Page(array_slice(['a', 'b', 'c', 'd', 'e'], ($number - 1) * 2, 2), $number, 2, 5);
        });

        self::assertSame(['a', 'b', 'c', 'd', 'e'], $items);
        self::assertSame([1, 2, 3], $requested);
    }

    public function testStopsAtAnEmptyPageWhateverTheTotalClaims(): void
    {
        $requested = [];
        $items = Page::collect(static function (int $number) use (&$requested): Page {
            $requested[] = $number;

            return new Page($number === 1 ? ['a'] : [], $number, 1, 10);
        });

        self::assertSame(['a'], $items);
        self::assertSame([1, 2], $requested);
    }

    /**
     * Captured (v2 error pages): page 0, pageSize 10^16 — one answer with everything, and the
     * same answer for every ?page.
     */
    public function testAnUnpagedAnswerIsFetchedOnce(): void
    {
        $requested = [];
        $items = Page::collect(static function (int $number) use (&$requested): Page {
            $requested[] = $number;

            return new Page(['a', 'b', 'c', 'd'], 0, 10000000000000000, 4);
        });

        self::assertSame(['a', 'b', 'c', 'd'], $items);
        self::assertSame([1], $requested);
    }

    public function testStopsWhenTheAnswerIsNotTheRequestedPage(): void
    {
        $requested = [];
        $items = Page::collect(static function (int $number) use (&$requested): Page {
            $requested[] = $number;

            return new Page(['a'], 1, 1, 5); // ?page ignored: always page 1
        });

        self::assertSame(['a', 'a'], $items);
        self::assertSame([1, 2], $requested);
    }

    public function testStopsWhenAllEntriesAreThere(): void
    {
        $requested = [];
        $items = Page::collect(static function (int $number) use (&$requested): Page {
            $requested[] = $number;

            return new Page(['a', 'b'], $number, 1, 2); // claims pageSize 1, sends 2
        });

        self::assertSame(['a', 'b'], $items);
        self::assertSame([1], $requested);
    }

    public function testAcceptsPageOneAndUp(): void
    {
        self::assertSame(1, Page::number(1));
        self::assertSame(7, Page::number(7));
    }

    #[TestWith([0])]
    #[TestWith([-1])]
    public function testRejectsPagesBelowOne(int $number): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('Pages start at 1, %d given.', $number));

        Page::number($number);
    }
}
