<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * A cache rule of a subdomain (`CacheSettingVO`, the same in v1 and v2).
 *
 * Myra's object does not name its subdomain; the endpoints attach the vhost it was read
 * from, so update and delete know where it belongs.
 */
final readonly class CacheSetting
{
    public const TYPE_EXACT = 'exact';
    public const TYPE_PREFIX = 'prefix';
    public const TYPE_SUFFIX = 'suffix';
    /** TTL values with a meaning beyond seconds. */
    public const TTL_NO_CACHE = -1;
    public const TTL_LIKE_ORIGIN = 0;

    /**
     * @param string $subdomain the vhost, e.g. "www.example.com"
     * @param string $path the path to match (TYPE_…; a regular expression is possible)
     * @param int $ttl seconds; TTL_NO_CACHE or TTL_LIKE_ORIGIN
     * @param int|null $notFoundTtl how long 404 answers are cached (same values), null = Myra's default
     * @param bool $enforce set Cache-Control max-age regardless of the origin's answer
     * @param string|null $modified version for optimistic locking, exactly as Myra sent it
     * @param array<mixed> $raw the complete object as Myra sent it
     */
    public function __construct(
        private string $subdomain,
        private string $path,
        private int $ttl,
        private string $type = self::TYPE_PREFIX,
        private ?int $notFoundTtl = null,
        private bool $enforce = false,
        private int $sort = 0,
        private bool $enabled = true,
        private ?string $comment = null,
        private ?int $id = null,
        private ?string $modified = null,
        private ?string $created = null,
        private array $raw = [],
    ) {}

    /**
     * @internal
     *
     * @throws TransportException
     */
    public static function fromJson(JsonObject $json, string $subdomain): self
    {
        return new self(
            $subdomain,
            $json->string('path'),
            $json->int('ttl'),
            $json->string('type'),
            $json->has('notFoundTtl') ? $json->int('notFoundTtl') : null,
            $json->bool('enforce'),
            $json->int('sort'),
            $json->bool('enabled'),
            $json->nullableString('comment'),
            $json->int('id'),
            $json->string('modified'),
            $json->nullableString('created'),
            $json->toArray(),
        );
    }

    /**
     * @internal
     *
     * @return array<string, scalar>
     */
    public function toJson(): array
    {
        $json = ['path' => $this->path, 'ttl' => $this->ttl, 'type' => $this->type];
        if ($this->notFoundTtl !== null) {
            $json['notFoundTtl'] = $this->notFoundTtl;
        }
        $json += ['enforce' => $this->enforce, 'sort' => $this->sort, 'enabled' => $this->enabled];
        if ($this->comment !== null) {
            $json['comment'] = $this->comment;
        }
        if ($this->id !== null) {
            $json['id'] = $this->id;
        }
        if ($this->modified !== null) {
            $json['modified'] = $this->modified;
        }

        return $json;
    }

    public function subdomain(): string
    {
        return $this->subdomain;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function ttl(): int
    {
        return $this->ttl;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function notFoundTtl(): ?int
    {
        return $this->notFoundTtl;
    }

    public function isEnforced(): bool
    {
        return $this->enforce;
    }

    public function sort(): int
    {
        return $this->sort;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function comment(): ?string
    {
        return $this->comment;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function modified(): ?string
    {
        return $this->modified;
    }

    public function created(): ?string
    {
        return $this->created;
    }

    /**
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }

    public function withPath(string $path): self
    {
        return $this->with(['path' => $path]);
    }

    public function withTtl(int $ttl): self
    {
        return $this->with(['ttl' => $ttl]);
    }

    public function withType(string $type): self
    {
        return $this->with(['type' => $type]);
    }

    public function withNotFoundTtl(?int $notFoundTtl): self
    {
        return $this->with(['notFoundTtl' => $notFoundTtl]);
    }

    public function withEnforce(bool $enforce): self
    {
        return $this->with(['enforce' => $enforce]);
    }

    public function withSort(int $sort): self
    {
        return $this->with(['sort' => $sort]);
    }

    public function withEnabled(bool $enabled): self
    {
        return $this->with(['enabled' => $enabled]);
    }

    public function withComment(?string $comment): self
    {
        return $this->with(['comment' => $comment]);
    }

    /**
     * @param array{path?: string, ttl?: int, type?: string, notFoundTtl?: int|null, enforce?: bool, sort?: int, enabled?: bool, comment?: string|null} $changes
     */
    private function with(array $changes): self
    {
        return new self(
            $this->subdomain,
            $changes['path'] ?? $this->path,
            $changes['ttl'] ?? $this->ttl,
            $changes['type'] ?? $this->type,
            array_key_exists('notFoundTtl', $changes) ? $changes['notFoundTtl'] : $this->notFoundTtl,
            $changes['enforce'] ?? $this->enforce,
            $changes['sort'] ?? $this->sort,
            $changes['enabled'] ?? $this->enabled,
            array_key_exists('comment', $changes) ? $changes['comment'] : $this->comment,
            $this->id,
            $this->modified,
            $this->created,
            $this->raw,
        );
    }
}
