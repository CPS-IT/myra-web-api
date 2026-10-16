<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V2;

use Myracloud\WebApi\Contract;
use Myracloud\WebApi\Exception\DomainNotFoundException;
use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\Page;
use Myracloud\WebApi\Model\SslCertificate;
use Myracloud\WebApi\Model\SslCertificateUpload;
use Myracloud\WebApi\Model\SslConfiguration;

/**
 * Reads `/domain/{domainId}/ssl/certificates[/{id}]`; writes `/domain/{domainId}/certificates`
 * (POST) and `…/certificates/{id}` (PUT, DELETE) — without `/ssl`, as in the spec.
 * Myra's TLS presets: `GET /ssl-configurations`.
 */
final readonly class SslCertificates implements Contract\SslCertificates
{
    /**
     * @internal built by {@see Client}
     */
    public function __construct(
        private Transport $transport,
        private DomainIdResolver $domainIds,
    ) {}

    public function list(string $domain): array
    {
        return Page::collect(fn(int $page): Page => $this->page($domain, $page));
    }

    public function page(string $domain, int $page): Page
    {
        $body = $this->transport->send('GET', 'domain/' . $this->domainIds->resolve($domain) . '/ssl/certificates', Domains::query(Page::number($page)));

        return Page::fromJson($body, 'data', SslCertificate::fromJson(...));
    }

    /**
     * @throws DomainNotFoundException
     * @throws MyraException
     */
    public function get(string $domain, int $id): SslCertificate
    {
        return SslCertificate::fromJson($this->transport->send('GET', 'domain/' . $this->domainIds->resolve($domain) . '/ssl/certificates/' . $id)->first('data'));
    }

    public function upload(string $domain, SslCertificateUpload $certificate): SslCertificate
    {
        return SslCertificate::fromJson($this->transport->send('POST', $this->writePath($domain), body: $certificate->toJson())->first('data'));
    }

    public function update(string $domain, SslCertificate $certificate): SslCertificate
    {
        return SslCertificate::fromJson($this->transport->send('PUT', $this->writePath($domain) . '/' . $certificate->id(), body: $certificate->toJson())->first('data'));
    }

    /**
     * *Write unverified.* Only a certificate assigned to no subdomain can be deleted (spec:
     * otherwise HTTP 400) — withSubdomains([]) and update() first.
     *
     * @throws DomainNotFoundException
     * @throws MyraException
     */
    public function delete(string $domain, SslCertificate $certificate): void
    {
        $this->transport->send('DELETE', $this->writePath($domain) . '/' . $certificate->id());
    }

    /**
     * Myra's TLS presets, referenced by a certificate's sslConfigurationName.
     *
     * @return list<SslConfiguration>
     *
     * @throws MyraException
     */
    public function configurations(): array
    {
        return Page::collect(fn(int $page): Page => Page::fromJson($this->transport->send('GET', 'ssl-configurations', Domains::query($page)), 'data', SslConfiguration::fromJson(...)));
    }

    private function writePath(string $domain): string
    {
        return 'domain/' . $this->domainIds->resolve($domain) . '/certificates';
    }
}
