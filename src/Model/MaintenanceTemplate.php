<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * A stored maintenance page of a domain, to reuse (`MaintenanceTemplateVO`, v2 only).
 */
final readonly class MaintenanceTemplate
{
    /**
     * @param string $content HTML
     * @param array<mixed> $raw the complete object as Myra sent it
     */
    public function __construct(
        private string $name,
        private string $content,
        private ?int $id = null,
        private ?string $modified = null,
        private array $raw = [],
    ) {}

    /**
     * @internal
     *
     * @throws TransportException
     */
    public static function fromJson(JsonObject $json): self
    {
        return new self($json->string('name'), $json->string('content'), $json->int('id'), $json->nullableString('modified'), $json->toArray());
    }

    /**
     * @internal
     *
     * @return array<mixed>
     */
    public function toJson(): array
    {
        $json = ['name' => $this->name, 'content' => $this->content];
        if ($this->id !== null) {
            $json['id'] = $this->id;
        }
        if ($this->modified !== null) {
            $json['modified'] = $this->modified;
        }

        return array_merge(array_diff_key($this->raw, ['objectType' => true, 'created' => true]), $json);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function modified(): ?string
    {
        return $this->modified;
    }

    /**
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }

    public function withName(string $name): self
    {
        return new self($name, $this->content, $this->id, $this->modified, $this->raw);
    }

    public function withContent(string $content): self
    {
        return new self($this->name, $content, $this->id, $this->modified, $this->raw);
    }
}
