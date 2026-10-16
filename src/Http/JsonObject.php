<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Http;

use Myracloud\WebApi\Exception\TransportException;

/**
 * A decoded JSON object with typed access.
 *
 * A missing or mistyped field means Myra answered something this package does not
 * understand: {@see TransportException} naming the field by its path, never its value.
 *
 * @internal
 */
final readonly class JsonObject
{
    /**
     * @param array<mixed> $data
     * @param string $path where this object sits in the response, for error messages
     */
    public function __construct(
        private array $data,
        private string $path = '',
    ) {}

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * @throws TransportException
     */
    public function string(string $key): string
    {
        $value = $this->value($key);
        if (!is_string($value)) {
            throw $this->unexpected($key, 'a string', $value);
        }

        return $value;
    }

    /**
     * A missing field counts as null.
     *
     * @throws TransportException
     */
    public function nullableString(string $key): ?string
    {
        $value = $this->data[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw $this->unexpected($key, 'a string or null', $value);
        }

        return $value;
    }

    /**
     * @throws TransportException
     */
    public function int(string $key): int
    {
        $value = $this->value($key);
        if (!is_int($value)) {
            throw $this->unexpected($key, 'an integer', $value);
        }

        return $value;
    }

    /**
     * An integer or a float (statistics send both for the same field).
     *
     * @throws TransportException
     */
    public function number(string $key): int|float
    {
        $value = $this->value($key);
        if (!is_int($value) && !is_float($value)) {
            throw $this->unexpected($key, 'a number', $value);
        }

        return $value;
    }

    /**
     * @throws TransportException
     */
    public function bool(string $key): bool
    {
        $value = $this->value($key);
        if (!is_bool($value)) {
            throw $this->unexpected($key, 'a boolean', $value);
        }

        return $value;
    }

    /**
     * @throws TransportException
     */
    public function object(string $key): self
    {
        $value = $this->value($key);
        if (!self::isObject($value)) {
            throw $this->unexpected($key, 'an object', $value);
        }

        return new self($value, $this->pathOf($key));
    }

    /**
     * @return list<self>
     *
     * @throws TransportException
     */
    public function objects(string $key): array
    {
        $objects = [];
        foreach ($this->list($key, 'a list of objects') as $index => $item) {
            $path = $this->pathOf($key) . '[' . $index . ']';
            if (!self::isObject($item)) {
                throw new TransportException(sprintf('Unexpected response: %s is not an object but %s.', $path, get_debug_type($item)));
            }
            $objects[] = new self($item, $path);
        }

        return $objects;
    }

    /**
     * The first object of a list — single objects come as one-element lists (v1
     * `targetObject`, v2 `data` of a get by id or a write).
     *
     * @throws TransportException if the list is empty
     */
    public function first(string $key): self
    {
        return $this->objects($key)[0] ?? throw new TransportException(sprintf('Unexpected response: %s is empty.', $this->pathOf($key)));
    }

    /**
     * @return list<string>
     *
     * @throws TransportException
     */
    public function strings(string $key): array
    {
        $list = $this->list($key, 'a list of strings');
        foreach ($list as $index => $item) {
            if (!is_string($item)) {
                throw new TransportException(sprintf('Unexpected response: %s[%d] is not a string but %s.', $this->pathOf($key), $index, get_debug_type($item)));
            }
        }
        /** @var list<string> $list */
        return $list;
    }

    /**
     * An object of strings, e.g. `translations`. (Numeric keys come back as int, as always in PHP.)
     *
     * @return array<string>
     *
     * @throws TransportException
     */
    public function stringMap(string $key): array
    {
        $value = $this->value($key);
        if (!self::isObject($value)) {
            throw $this->unexpected($key, 'an object of strings', $value);
        }
        $map = [];
        foreach ($value as $name => $item) {
            if (!is_string($item)) {
                throw new TransportException(sprintf('Unexpected response: %s.%s is not a string but %s.', $this->pathOf($key), $name, get_debug_type($item)));
            }
            $map[$name] = $item;
        }

        return $map;
    }

    /**
     * An object whose values are objects, by key — e.g. graphs by name, statistics by status
     * code. Numeric keys come back as int, as always in PHP.
     *
     * @return array<int|string, self>
     *
     * @throws TransportException
     */
    public function objectMap(string $key): array
    {
        return $this->object($key)->entries();
    }

    /**
     * Every value of this object as an object, by key (for an answer that is such a map).
     *
     * @return array<int|string, self>
     *
     * @throws TransportException
     */
    public function entries(): array
    {
        $entries = [];
        foreach ($this->data as $name => $item) {
            $path = $this->pathOf((string) $name);
            if (!self::isObject($item)) {
                throw new TransportException(sprintf('Unexpected response: %s is not an object but %s.', $path, get_debug_type($item)));
            }
            $entries[$name] = new self($item, $path);
        }

        return $entries;
    }

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    private function value(string $key): mixed
    {
        if (!array_key_exists($key, $this->data)) {
            throw new TransportException(sprintf('Unexpected response: %s is missing.', $this->pathOf($key)));
        }

        return $this->data[$key];
    }

    /**
     * @return list<mixed>
     */
    private function list(string $key, string $expected): array
    {
        $value = $this->value($key);
        if (!is_array($value) || !array_is_list($value)) {
            throw $this->unexpected($key, $expected, $value);
        }

        return $value;
    }

    /**
     * A decoded JSON object; `{}` and `[]` both decode to an empty array.
     *
     * @phpstan-assert-if-true array<mixed> $value
     */
    private static function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    private function pathOf(string $key): string
    {
        return $this->path === '' ? $key : $this->path . '.' . $key;
    }

    private function unexpected(string $key, string $expected, mixed $value): TransportException
    {
        return new TransportException(sprintf('Unexpected response: %s is not %s but %s.', $this->pathOf($key), $expected, get_debug_type($value)));
    }
}
