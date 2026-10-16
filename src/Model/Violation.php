<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;
use Stringable;

/**
 * One reason why Myra rejected a request (an entry of `violationList`).
 */
final readonly class Violation implements Stringable
{
    /**
     * @param string $propertyPath the field the violation is about, '' for the whole request
     * @param string|null $code machine-readable reason, e.g. DOMAIN_DOES_NOT_EXIST (not always sent)
     * @param array<string> $translations message by language, e.g. ['de' => '…'] (not always sent)
     */
    public function __construct(
        private string $propertyPath,
        private string $message,
        private ?string $code = null,
        private array $translations = [],
    ) {}

    /**
     * @internal
     *
     * @throws TransportException if a field has an unexpected type
     */
    public static function fromJson(JsonObject $json): self
    {
        return new self(
            $json->nullableString('propertyPath') ?? '',
            $json->nullableString('message') ?? '',
            $json->nullableString('code'),
            $json->has('translations') ? $json->stringMap('translations') : [],
        );
    }

    public function propertyPath(): string
    {
        return $this->propertyPath;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function code(): ?string
    {
        return $this->code;
    }

    /**
     * @return array<string> message by language, e.g. ['de' => '…']
     */
    public function translations(): array
    {
        return $this->translations;
    }

    /**
     * E.g. "fqdn: The given domain does not exist. (DOMAIN_DOES_NOT_EXIST)".
     */
    public function __toString(): string
    {
        return ($this->propertyPath === '' ? '' : $this->propertyPath . ': ')
            . $this->message
            . ($this->code === null ? '' : ' (' . $this->code . ')');
    }
}
