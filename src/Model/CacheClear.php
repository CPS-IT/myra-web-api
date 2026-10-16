<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * An enqueued cache clear as Myra confirms it (`CacheClearVO`, the same in v1 and v2).
 */
final readonly class CacheClear
{
    /**
     * @param string|null $resource the pattern; for a bulk clear Myra echoes the last entry
     * @param list<string> $bulk the patterns of a bulk clear, [] otherwise
     * @param array<mixed> $raw the complete object as Myra sent it
     */
    public function __construct(
        private string $fqdn,
        private ?string $resource,
        private bool $recursive,
        private array $bulk = [],
        private array $raw = [],
    ) {}

    /**
     * @internal
     *
     * @throws TransportException
     */
    public static function fromJson(JsonObject $json): self
    {
        return new self(
            $json->string('fqdn'),
            $json->nullableString('resource'),
            $json->bool('recursive'),
            $json->has('bulk') ? $json->strings('bulk') : [],
            $json->toArray(),
        );
    }

    public function fqdn(): string
    {
        return $this->fqdn;
    }

    public function resource(): ?string
    {
        return $this->resource;
    }

    public function isRecursive(): bool
    {
        return $this->recursive;
    }

    /**
     * @return list<string>
     */
    public function bulk(): array
    {
        return $this->bulk;
    }

    /**
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
