<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * Load balancing settings of a DNS record (`UpstreamOptionsVO`) — a versioned object of its
 * own, with `id` and `modified`.
 */
final readonly class UpstreamOptions
{
    /**
     * @param string $failTimeout seconds, as Myra sends it (a string, e.g. "1")
     * @param array<mixed> $raw the complete object as Myra sent it
     */
    public function __construct(
        private int $weight = 1,
        private int $maxFails = 100,
        private string $failTimeout = '1',
        private bool $backup = false,
        private bool $down = false,
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
        return new self(
            $json->int('weight'),
            $json->int('maxFails'),
            $json->string('failTimeout'),
            $json->bool('backup'),
            $json->bool('down'),
            $json->has('id') ? $json->int('id') : null,
            $json->nullableString('modified'),
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
        $json = ['weight' => $this->weight, 'maxFails' => $this->maxFails, 'failTimeout' => $this->failTimeout, 'backup' => $this->backup, 'down' => $this->down];
        if ($this->id !== null) {
            $json['id'] = $this->id;
        }
        if ($this->modified !== null) {
            $json['modified'] = $this->modified;
        }

        return $json;
    }

    public function weight(): int
    {
        return $this->weight;
    }

    public function maxFails(): int
    {
        return $this->maxFails;
    }

    public function failTimeout(): string
    {
        return $this->failTimeout;
    }

    public function isBackup(): bool
    {
        return $this->backup;
    }

    public function isDown(): bool
    {
        return $this->down;
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

    public function withWeight(int $weight): self
    {
        return new self($weight, $this->maxFails, $this->failTimeout, $this->backup, $this->down, $this->id, $this->modified, $this->raw);
    }

    public function withMaxFails(int $maxFails): self
    {
        return new self($this->weight, $maxFails, $this->failTimeout, $this->backup, $this->down, $this->id, $this->modified, $this->raw);
    }

    public function withFailTimeout(string $failTimeout): self
    {
        return new self($this->weight, $this->maxFails, $failTimeout, $this->backup, $this->down, $this->id, $this->modified, $this->raw);
    }

    public function withBackup(bool $backup): self
    {
        return new self($this->weight, $this->maxFails, $this->failTimeout, $backup, $this->down, $this->id, $this->modified, $this->raw);
    }

    public function withDown(bool $down): self
    {
        return new self($this->weight, $this->maxFails, $this->failTimeout, $this->backup, $down, $this->id, $this->modified, $this->raw);
    }
}
