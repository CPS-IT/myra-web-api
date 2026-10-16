<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;

/**
 * An SSL certificate of a domain as Myra reports it (`SslCertVO`, the same in v1 and v2).
 * Myra never returns the certificate itself nor its key; uploading goes through
 * {@see SslCertificateUpload}. An update changes the assigned subdomains and the
 * intermediate chain only: withSubdomains() / withIntermediates() on a certificate read before.
 */
final readonly class SslCertificate
{
    /**
     * @param list<string> $subdomains the vhosts it is assigned to
     * @param list<string> $subjectAlternatives the names it is valid for
     * @param list<string>|null $intermediatePems a new intermediate chain (PEM) for update(); null keeps the chain as read
     * @param array<mixed> $raw the complete object as Myra sent it (intermediates, multidomain, …)
     */
    public function __construct(
        private int $id,
        private string $modified,
        private array $subdomains,
        private string $subject = '',
        private ?string $validFrom = null,
        private ?string $validTo = null,
        private ?string $fingerprint = null,
        private ?string $serialNumber = null,
        private array $subjectAlternatives = [],
        private bool $wildcard = false,
        private bool $managed = false,
        private ?string $sslConfigurationName = null,
        private ?array $intermediatePems = null,
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
            $json->int('id'),
            $json->string('modified'),
            $json->has('subdomains') ? $json->strings('subdomains') : [],
            $json->nullableString('subject') ?? '',
            $json->nullableString('validFrom'),
            $json->nullableString('validTo'),
            $json->nullableString('fingerprint'),
            $json->nullableString('serialNumber'),
            $json->has('subjectAlternatives') ? $json->strings('subjectAlternatives') : [],
            $json->has('wildcard') && $json->bool('wildcard'),
            $json->has('managed') && $json->bool('managed'),
            $json->nullableString('sslConfigurationName'),
            null,
            $json->toArray(),
        );
    }

    /**
     * The update body of the manual: id, modified, subdomains, and the intermediates only if
     * withIntermediates() set new ones — the answers carry no PEM to send back, and the read
     * object has a `password` field, so the object as read is not sent. Whether Myra keeps the
     * chain when intermediates are left out is not verified (*write unverified*).
     *
     * @internal
     *
     * @return array<string, mixed>
     */
    public function toJson(): array
    {
        $json = ['id' => $this->id, 'modified' => $this->modified, 'subdomains' => $this->subdomains];
        if ($this->intermediatePems !== null) {
            $json['intermediates'] = array_map(static fn(string $pem): array => ['cert' => $pem], $this->intermediatePems);
        }

        return $json;
    }

    /**
     * Send the intermediate chain along (withIntermediates()): whether Myra keeps it when an
     * update leaves it out is not verified.
     *
     * @param list<string> $subdomains the vhosts to assign it to (all of them, not a delta)
     */
    public function withSubdomains(array $subdomains): self
    {
        return new self($this->id, $this->modified, $subdomains, $this->subject, $this->validFrom, $this->validTo, $this->fingerprint, $this->serialNumber, $this->subjectAlternatives, $this->wildcard, $this->managed, $this->sslConfigurationName, $this->intermediatePems, $this->raw);
    }

    /**
     * @param list<string> $pems the new intermediate chain, one PEM per certificate
     */
    public function withIntermediates(array $pems): self
    {
        return new self($this->id, $this->modified, $this->subdomains, $this->subject, $this->validFrom, $this->validTo, $this->fingerprint, $this->serialNumber, $this->subjectAlternatives, $this->wildcard, $this->managed, $this->sslConfigurationName, $pems, $this->raw);
    }

    public function id(): int
    {
        return $this->id;
    }

    /**
     * Version for optimistic locking, exactly as Myra sent it.
     */
    public function modified(): string
    {
        return $this->modified;
    }

    /**
     * @return list<string>
     */
    public function subdomains(): array
    {
        return $this->subdomains;
    }

    /**
     * E.g. "C=DE, O=…, CN=example.com".
     */
    public function subject(): string
    {
        return $this->subject;
    }

    public function validFrom(): ?string
    {
        return $this->validFrom;
    }

    public function validTo(): ?string
    {
        return $this->validTo;
    }

    public function fingerprint(): ?string
    {
        return $this->fingerprint;
    }

    public function serialNumber(): ?string
    {
        return $this->serialNumber;
    }

    /**
     * @return list<string>
     */
    public function subjectAlternatives(): array
    {
        return $this->subjectAlternatives;
    }

    public function wildcard(): bool
    {
        return $this->wildcard;
    }

    /**
     * Issued and renewed by Myra (e.g. Let's Encrypt), not uploaded.
     */
    public function managed(): bool
    {
        return $this->managed;
    }

    public function sslConfigurationName(): ?string
    {
        return $this->sslConfigurationName;
    }

    /**
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
