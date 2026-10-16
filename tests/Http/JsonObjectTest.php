<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\Http;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonObject::class)]
#[UsesClass(TransportException::class)]
final class JsonObjectTest extends TestCase
{
    private const DATA = [
        'name' => 'example.com',
        'nothing' => null,
        'ttl' => 300,
        'active' => false,
        'upstream' => ['weight' => 1],
        'empty' => [],
        'list' => [['id' => 1], ['id' => 2]],
        'bulk' => ['/a', '/b'],
        'translations' => ['en' => 'A', 'de' => 'B'],
        'avg' => 6.5,
        'codes' => ['200' => ['sum' => 1], 'Other' => ['sum' => 2]],
    ];

    public function testReadsTypedFields(): void
    {
        $json = new JsonObject(self::DATA);

        self::assertTrue($json->has('name'));
        self::assertTrue($json->has('nothing'));
        self::assertFalse($json->has('missing'));
        self::assertSame('example.com', $json->string('name'));
        self::assertSame('example.com', $json->nullableString('name'));
        self::assertNull($json->nullableString('nothing'));
        self::assertNull($json->nullableString('missing'));
        self::assertSame(300, $json->int('ttl'));
        self::assertFalse($json->bool('active'));
        self::assertSame(['weight' => 1], $json->object('upstream')->toArray());
        self::assertSame([], $json->object('empty')->toArray());
        self::assertSame([['id' => 1], ['id' => 2]], array_map(static fn(JsonObject $o): array => $o->toArray(), $json->objects('list')));
        self::assertSame([], $json->objects('empty'));
        self::assertSame(['/a', '/b'], $json->strings('bulk'));
        self::assertSame([], $json->strings('empty'));
        self::assertSame(['en' => 'A', 'de' => 'B'], $json->stringMap('translations'));
        self::assertSame([], $json->stringMap('empty'));
        self::assertSame(300, $json->number('ttl'));
        self::assertSame(6.5, $json->number('avg'));
        self::assertSame([200 => ['sum' => 1], 'Other' => ['sum' => 2]], array_map(static fn(JsonObject $o): array => $o->toArray(), $json->objectMap('codes')));
        self::assertSame([], $json->objectMap('empty'));
        self::assertSame(self::DATA, $json->toArray());
    }

    public function testNamesEntriesByTheirPath(): void
    {
        $json = new JsonObject(['graph' => ['total' => ['max' => 'x'], '200' => ['y' => 1]]], 'body');

        self::assertSame(['total', 200], array_keys($json->objectMap('graph')));
        self::assertSame(['total', 200], array_keys((new JsonObject(['total' => [], '200' => ['y' => 1]]))->entries()));

        try {
            $json->objectMap('graph')['total']->number('max');
            self::fail('No exception thrown.');
        } catch (TransportException $exception) {
            self::assertSame('Unexpected response: body.graph.total.max is not a number but string.', $exception->getMessage());
        }

        $this->expectExceptionMessage('Unexpected response: body.codes.200 is not an object but int.');
        (new JsonObject(['codes' => ['200' => 5]], 'body'))->objectMap('codes');
    }

    /**
     * @return iterable<string, array{callable(JsonObject): mixed, string}>
     */
    public static function failures(): iterable
    {
        yield 'missing string' => [static fn(JsonObject $j): mixed => $j->string('missing'), 'Unexpected response: missing is missing.'];
        yield 'string' => [static fn(JsonObject $j): mixed => $j->string('ttl'), 'Unexpected response: ttl is not a string but int.'];
        yield 'nullable string' => [static fn(JsonObject $j): mixed => $j->nullableString('ttl'), 'Unexpected response: ttl is not a string or null but int.'];
        yield 'int' => [static fn(JsonObject $j): mixed => $j->int('name'), 'Unexpected response: name is not an integer but string.'];
        yield 'missing int' => [static fn(JsonObject $j): mixed => $j->int('missing'), 'Unexpected response: missing is missing.'];
        yield 'bool' => [static fn(JsonObject $j): mixed => $j->bool('nothing'), 'Unexpected response: nothing is not a boolean but null.'];
        yield 'missing bool' => [static fn(JsonObject $j): mixed => $j->bool('missing'), 'Unexpected response: missing is missing.'];
        yield 'object from scalar' => [static fn(JsonObject $j): mixed => $j->object('name'), 'Unexpected response: name is not an object but string.'];
        yield 'object from list' => [static fn(JsonObject $j): mixed => $j->object('bulk'), 'Unexpected response: bulk is not an object but array.'];
        yield 'missing object' => [static fn(JsonObject $j): mixed => $j->object('missing'), 'Unexpected response: missing is missing.'];
        yield 'objects from object' => [static fn(JsonObject $j): mixed => $j->objects('upstream'), 'Unexpected response: upstream is not a list of objects but array.'];
        yield 'objects from scalar' => [static fn(JsonObject $j): mixed => $j->objects('name'), 'Unexpected response: name is not a list of objects but string.'];
        yield 'objects of strings' => [static fn(JsonObject $j): mixed => $j->objects('bulk'), 'Unexpected response: bulk[0] is not an object but string.'];
        yield 'strings from object' => [static fn(JsonObject $j): mixed => $j->strings('translations'), 'Unexpected response: translations is not a list of strings but array.'];
        yield 'strings of objects' => [static fn(JsonObject $j): mixed => $j->strings('list'), 'Unexpected response: list[0] is not a string but array.'];
        yield 'string map from list' => [static fn(JsonObject $j): mixed => $j->stringMap('bulk'), 'Unexpected response: bulk is not an object of strings but array.'];
        yield 'string map from scalar' => [static fn(JsonObject $j): mixed => $j->stringMap('ttl'), 'Unexpected response: ttl is not an object of strings but int.'];
        yield 'number' => [static fn(JsonObject $j): mixed => $j->number('name'), 'Unexpected response: name is not a number but string.'];
        yield 'missing number' => [static fn(JsonObject $j): mixed => $j->number('missing'), 'Unexpected response: missing is missing.'];
        yield 'object map from list' => [static fn(JsonObject $j): mixed => $j->objectMap('bulk'), 'Unexpected response: bulk is not an object but array.'];
        yield 'object map of scalars' => [static fn(JsonObject $j): mixed => $j->objectMap('translations'), 'Unexpected response: translations.en is not an object but string.'];
        yield 'string map of ints' => [static fn(JsonObject $j): mixed => $j->stringMap('upstream'), 'Unexpected response: upstream.weight is not a string but int.'];
    }

    /**
     * @param callable(JsonObject): mixed $read
     */
    #[DataProvider('failures')]
    public function testNamesTheFieldThatDoesNotFit(callable $read, string $message): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage($message);

        $read(new JsonObject(self::DATA));
    }

    public function testNamesNestedFieldsByTheirPath(): void
    {
        $json = new JsonObject(['list' => [['a' => 1], ['up' => ['w' => 'x'], 'items' => [1, 'y']]]]);
        $second = $json->objects('list')[1];

        self::assertSame(['w' => 'x'], $second->object('up')->toArray());

        try {
            $second->object('up')->int('w');
            self::fail('No exception thrown.');
        } catch (TransportException $exception) {
            self::assertSame('Unexpected response: list[1].up.w is not an integer but string.', $exception->getMessage());
        }

        $this->expectExceptionMessage('Unexpected response: list[1].items[0] is not a string but int.');
        $second->strings('items');
    }

    public function testReadsTheFirstObjectOfAList(): void
    {
        $json = new JsonObject(['data' => [['id' => 1], ['id' => 2]], 'empty' => []], 'body');

        self::assertSame(['id' => 1], $json->first('data')->toArray());

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Unexpected response: body.empty is empty.');
        $json->first('empty');
    }

    public function testNamesListItemsByIndex(): void
    {
        $json = new JsonObject(['list' => [['a' => 1], 'b']]);

        $this->expectExceptionMessage('Unexpected response: list[1] is not an object but string.');
        $json->objects('list');
    }

    public function testNamesNonStringListItemsByIndex(): void
    {
        $this->expectExceptionMessage('Unexpected response: bulk[1] is not a string but null.');
        (new JsonObject(['bulk' => ['/a', null]]))->strings('bulk');
    }
}
