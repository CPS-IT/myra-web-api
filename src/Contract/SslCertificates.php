<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Contract;

use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Model\Page;
use Myracloud\WebApi\Model\SslCertificate;
use Myracloud\WebApi\Model\SslCertificateUpload;

/**
 * The SSL certificates of a domain. See {@see Client} on implementing this interface.
 *
 * Per domain in both versions (captured: v1 lists the domain's certificates also when given
 * a subdomain). Reads captured; upload() and update() are *writes unverified* (from the
 * manual / spec — no harmless test: a certificate goes live for its subdomains).
 */
interface SslCertificates
{
    /**
     * @return list<SslCertificate>
     *
     * @throws MyraException
     */
    public function list(string $domain): array;

    /**
     * @param int $page 1-based
     *
     * @return Page<SslCertificate>
     *
     * @throws MyraException
     */
    public function page(string $domain, int $page): Page;

    /**
     * Uploads a certificate and assigns it to its subdomains (deployed by Myra afterwards).
     *
     * @throws MyraException
     */
    public function upload(string $domain, SslCertificateUpload $certificate): SslCertificate;

    /**
     * Changes the assigned subdomains and/or the intermediate chain of a certificate read before.
     *
     * @throws MyraException
     */
    public function update(string $domain, SslCertificate $certificate): SslCertificate;
}
