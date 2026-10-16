<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * The error page of one subdomain and one error code (`ErrorPageVO`, the same in v1 and v2).
 * Lists leave the content empty; get() returns it. Pages are set and deleted through a
 * selection of subdomains and codes ({@see Contract\ErrorPages}), not one by one.
 */
final readonly class ErrorPage
{
    /** The codes Myra supports; 9999 stands for blocked requests. */
    public const CODES = [400, 404, 405, 413, 429, 500, 502, 503, 504, 9999];

    /**
     * @param array<mixed> $raw the complete object as Myra sent it
     */
    public function __construct(
        private string $subdomain,
        private int $errorCode,
        private string $content,
        private int $id,
        private ?string $modified = null,
        private ?string $created = null,
        private array $raw = [],
    ) {}

    /**
     * @internal
     *
     * @param string|null $subdomain v1 groups pages by subdomain and leaves it out of the object
     *
     * @throws TransportException
     */
    public static function fromJson(JsonObject $json, ?string $subdomain = null): self
    {
        return new self(
            $subdomain ?? $json->string('subDomainName'),
            $json->int('errorCode'),
            $json->nullableString('content') ?? '',
            $json->int('id'),
            $json->nullableString('modified'),
            $json->nullableString('created'),
            $json->toArray(),
        );
    }

    public function subdomain(): string
    {
        return $this->subdomain;
    }

    public function errorCode(): int
    {
        return $this->errorCode;
    }

    /**
     * The HTML — empty in lists, see Contract\ErrorPages::get().
     */
    public function content(): string
    {
        return $this->content;
    }

    public function id(): int
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
}
