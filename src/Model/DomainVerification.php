<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * Whether Myra has verified that a domain belongs to the organisation
 * (`DomainVerificationVO`, v2 only). Only verified domains are built and get certificates.
 */
final readonly class DomainVerification
{
    public const BLOCKER_FOREIGN_PARENT = 'foreign_parent';
    public const BLOCKER_FOREIGN_CHILD = 'foreign_child';
    public const BLOCKER_NAME_CONFLICT = 'name_conflict';
    public const BLOCKER_RECORD_CONFLICT = 'record_conflict';
    public const BLOCKER_MANUAL_REVOKED = 'manual_revoked';
    public const BLOCKER_NEEDS_REVIEW = 'needs_review';

    /**
     * @param string|null $blocker why it is pending, BLOCKER_*; null when verified
     * @param list<string> $recordNames where the consent TXT record may be published, in check order
     * @param string|null $recordValue the consent TXT record's value
     * @param list<string> $conflictingNames for the conflict blockers
     * @param array<mixed> $raw the complete object as Myra sent it
     */
    public function __construct(
        private bool $verified,
        private ?string $blocker = null,
        private array $recordNames = [],
        private ?string $recordValue = null,
        private array $conflictingNames = [],
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
            $json->bool('verified'),
            $json->nullableString('blocker'),
            $json->has('recordNames') ? $json->strings('recordNames') : [],
            $json->nullableString('recordValue'),
            $json->has('conflictingNames') ? $json->strings('conflictingNames') : [],
            $json->toArray(),
        );
    }

    public function verified(): bool
    {
        return $this->verified;
    }

    public function blocker(): ?string
    {
        return $this->blocker;
    }

    /**
     * @return list<string>
     */
    public function recordNames(): array
    {
        return $this->recordNames;
    }

    public function recordValue(): ?string
    {
        return $this->recordValue;
    }

    /**
     * @return list<string>
     */
    public function conflictingNames(): array
    {
        return $this->conflictingNames;
    }

    /**
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
