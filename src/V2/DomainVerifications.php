<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V2;

use Myracloud\WebApi\Exception\DomainNotFoundException;
use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Exception\RequestRejectedException;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\DomainVerification;

/**
 * Domain verification (v2 only): `GET /domains/{id}/verification`, `POST …/verification/check`.
 * Verifying or revoking by hand and the foreign descendants are for Myra agents only (403).
 */
final readonly class DomainVerifications
{
    /**
     * @internal built by {@see Client}
     */
    public function __construct(
        private Transport $transport,
        private DomainIdResolver $domainIds,
    ) {}

    /**
     * @throws DomainNotFoundException
     * @throws MyraException
     */
    public function status(string $domain): DomainVerification
    {
        return DomainVerification::fromJson($this->transport->send('GET', $this->path($domain))->first('data'));
    }

    /**
     * Asks Myra to look for the consent TXT record now instead of on its own schedule; the
     * result shows in status() later. *Write unverified* (from the spec: 202 when queued).
     *
     * @throws RequestRejectedException e.g. DOMAIN_VERIFICATION_ALREADY_VERIFIED or
     *                                  DOMAIN_VERIFICATION_CHECKED_RECENTLY (HTTP 409, spec)
     * @throws DomainNotFoundException
     * @throws MyraException
     */
    public function check(string $domain): void
    {
        $this->transport->send('POST', $this->path($domain) . '/check');
    }

    private function path(string $domain): string
    {
        return 'domains/' . $this->domainIds->resolve($domain) . '/verification';
    }
}
