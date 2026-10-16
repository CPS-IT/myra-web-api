<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V1;

use Myracloud\WebApi\Contract;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\DomainName;
use Myracloud\WebApi\Model\Page;
use Myracloud\WebApi\Model\SslCertificate;
use Myracloud\WebApi\Model\SslCertificateUpload;

/**
 * `/{lang}/rapi/certificates/{domain}`: GET `/{page}` lists, PUT uploads, POST updates.
 * There is no delete in v1: Myra removes a certificate that has been assigned to no
 * subdomain for a week (manual 14.2.5).
 */
final readonly class SslCertificates implements Contract\SslCertificates
{
    /**
     * @internal built by {@see Client}
     */
    public function __construct(private Transport $transport) {}

    public function list(string $domain): array
    {
        return Page::collect(fn(int $page): Page => $this->page($domain, $page));
    }

    public function page(string $domain, int $page): Page
    {
        return Page::fromJson($this->transport->send('GET', $this->path($domain) . '/' . Page::number($page)), 'list', SslCertificate::fromJson(...));
    }

    public function upload(string $domain, SslCertificateUpload $certificate): SslCertificate
    {
        return SslCertificate::fromJson($this->transport->send('PUT', $this->path($domain), body: $certificate->toJson())->first('targetObject'));
    }

    public function update(string $domain, SslCertificate $certificate): SslCertificate
    {
        return SslCertificate::fromJson($this->transport->send('POST', $this->path($domain), body: $certificate->toJson())->first('targetObject'));
    }

    private function path(string $domain): string
    {
        return 'certificates/' . rawurlencode(DomainName::normalize($domain));
    }
}
