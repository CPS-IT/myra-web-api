<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * A kind of organisation contact (v2 `organization/contact-types`; no objectType).
 */
final readonly class ContactType
{
    /**
     * @param string $key e.g. "TECHNICAL", "ESCALATION", "SALES", "COMPLIANCE"
     */
    public function __construct(
        private string $key,
        private string $label,
    ) {}

    /**
     * @internal
     *
     * @throws TransportException
     */
    public static function fromJson(JsonObject $json): self
    {
        return new self($json->string('key'), $json->string('label'));
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }
}
