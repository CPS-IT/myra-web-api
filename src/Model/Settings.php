<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * The settings of a subdomain (vhost): its own and the inherited ones.
 *
 * Settings are a map (94 names, booleans, strings, lists — Myra adds new ones), not typed
 * fields. Values come as each version sends them: v1 sends numbers as strings ("540"), v2 as
 * numbers (540).
 */
final readonly class Settings
{
    /**
     * @param array<string, mixed> $own the vhost's own settings (Myra: `domain`)
     * @param array<string, mixed> $inherited everything inherited, merged (Myra: `parent`)
     * @param list<array<string, mixed>> $layers the inheritance layers, most general first, the
     *                                           vhost's own last (Myra: `targetObject`)
     * @param array<mixed> $raw the complete answer
     */
    public function __construct(
        private string $subdomain,
        private array $own,
        private array $inherited,
        private array $layers = [],
        private array $raw = [],
    ) {}

    /**
     * @internal
     *
     * @throws TransportException
     */
    public static function fromJson(JsonObject $json, string $subdomain): self
    {
        $layers = array_map(
            static fn(JsonObject $layer): array => self::map(array_diff_key($layer->toArray(), ['objectType' => true])),
            $json->objects('targetObject'),
        );

        return new self($subdomain, self::map($json->object('domain')->toArray()), self::map($json->object('parent')->toArray()), $layers, $json->toArray());
    }

    public function subdomain(): string
    {
        return $this->subdomain;
    }

    /**
     * The vhost's own settings — send these, changed, to update (see Contract\SubdomainSettings).
     *
     * @return array<string, mixed>
     */
    public function own(): array
    {
        return $this->own;
    }

    /**
     * @return array<string, mixed>
     */
    public function inherited(): array
    {
        return $this->inherited;
    }

    /**
     * The value that applies: the vhost's own, else the inherited one; null if neither has it.
     */
    public function value(string $name): mixed
    {
        return array_key_exists($name, $this->own) ? $this->own[$name] : ($this->inherited[$name] ?? null);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->own) || array_key_exists($name, $this->inherited);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function layers(): array
    {
        return $this->layers;
    }

    /**
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function map(array $values): array
    {
        $map = [];
        foreach ($values as $name => $value) {
            $map[(string) $name] = $value;
        }

        return $map;
    }
}
