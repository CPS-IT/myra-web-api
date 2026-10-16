<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * A redirect of a subdomain (`DnsRedirectVO`, the same in v1 and v2).
 *
 * New redirects are built with the constructor and have no id; {@see Contract\Redirects::create()}
 * returns them with id and `modified`. Changes go through the with…() methods on a redirect
 * read before: updates and deletes need its `id` and `modified` (optimistic locking).
 */
final readonly class Redirect
{
    public const TYPE_PERMANENT = 'permanent'; // HTTP 301
    public const TYPE_REDIRECT = 'redirect';   // HTTP 302
    public const MATCH_EXACT = 'exact';
    public const MATCH_PREFIX = 'prefix';
    public const MATCH_SUFFIX = 'suffix';

    /**
     * @param string $subdomain the vhost, e.g. "www.example.com" (`subDomainName`)
     * @param string $source the path to match, e.g. "/old"
     * @param string $type TYPE_PERMANENT (301) or TYPE_REDIRECT (302)
     * @param string $matchingType MATCH_EXACT, MATCH_PREFIX or MATCH_SUFFIX
     * @param bool $expertMode skip Myra's check for redirect loops
     * @param string|null $modified version for optimistic locking, exactly as Myra sent it
     * @param array<mixed> $raw the complete object as Myra sent it
     */
    public function __construct(
        private string $subdomain,
        private string $source,
        private string $destination,
        private string $type = self::TYPE_PERMANENT,
        private string $matchingType = self::MATCH_EXACT,
        private int $sort = 0,
        private bool $enabled = true,
        private bool $expertMode = false,
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
    public static function fromJson(JsonObject $json): self
    {
        return new self(
            $json->string('subDomainName'),
            $json->string('source'),
            $json->string('destination'),
            $json->string('type'),
            $json->string('matchingType'),
            $json->int('sort'),
            $json->bool('enabled'),
            $json->has('expertMode') && $json->bool('expertMode'),
            $json->nullableString('comment'),
            $json->int('id'),
            $json->string('modified'),
            $json->nullableString('created'),
            $json->toArray(),
        );
    }

    /**
     * The request body: the settable fields, plus `id` and `modified` once persisted.
     *
     * @internal
     *
     * @return array<string, scalar>
     */
    public function toJson(): array
    {
        $json = [
            'subDomainName' => $this->subdomain,
            'source' => $this->source,
            'destination' => $this->destination,
            'type' => $this->type,
            'matchingType' => $this->matchingType,
            'sort' => $this->sort,
            'enabled' => $this->enabled,
            'expertMode' => $this->expertMode,
        ];
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

    public function source(): string
    {
        return $this->source;
    }

    public function destination(): string
    {
        return $this->destination;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function matchingType(): string
    {
        return $this->matchingType;
    }

    public function sort(): int
    {
        return $this->sort;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isExpertMode(): bool
    {
        return $this->expertMode;
    }

    public function comment(): ?string
    {
        return $this->comment;
    }

    /**
     * Null for a redirect that is not created yet.
     */
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

    public function withSource(string $source): self
    {
        return $this->with(['source' => $source]);
    }

    public function withDestination(string $destination): self
    {
        return $this->with(['destination' => $destination]);
    }

    public function withType(string $type): self
    {
        return $this->with(['type' => $type]);
    }

    public function withMatchingType(string $matchingType): self
    {
        return $this->with(['matchingType' => $matchingType]);
    }

    public function withSort(int $sort): self
    {
        return $this->with(['sort' => $sort]);
    }

    public function withEnabled(bool $enabled): self
    {
        return $this->with(['enabled' => $enabled]);
    }

    public function withExpertMode(bool $expertMode): self
    {
        return $this->with(['expertMode' => $expertMode]);
    }

    public function withComment(?string $comment): self
    {
        return $this->with(['comment' => $comment]);
    }

    /**
     * @param array{source?: string, destination?: string, type?: string, matchingType?: string, sort?: int, enabled?: bool, expertMode?: bool, comment?: string|null} $changes
     */
    private function with(array $changes): self
    {
        return new self(
            $this->subdomain,
            $changes['source'] ?? $this->source,
            $changes['destination'] ?? $this->destination,
            $changes['type'] ?? $this->type,
            $changes['matchingType'] ?? $this->matchingType,
            $changes['sort'] ?? $this->sort,
            $changes['enabled'] ?? $this->enabled,
            $changes['expertMode'] ?? $this->expertMode,
            array_key_exists('comment', $changes) ? $changes['comment'] : $this->comment,
            $this->id,
            $this->modified,
            $this->created,
            $this->raw,
        );
    }
}
