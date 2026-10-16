<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use SensitiveParameter;
use SensitiveParameterValue;

/**
 * A certificate to upload: the certificate, its private key and the vhosts to assign it to
 * (`SslCertVO` for create). To replace an existing certificate but keep its IP addresses,
 * name it in $replaces (`certToRefresh`).
 *
 * The key and the password are hidden from var_dump, print_r and stack traces.
 */
final readonly class SslCertificateUpload
{
    private SensitiveParameterValue $key;
    private ?SensitiveParameterValue $password;

    /**
     * @param string $certificate PEM
     * @param string $key the private key, PEM (unencrypted, or encrypted with $password)
     * @param list<string> $subdomains the vhosts to assign it to
     * @param list<string> $intermediates the chain, one PEM per certificate
     * @param int|null $replaces id of a certificate to refresh with this one (`certToRefresh`)
     * @param bool $force refresh even if the certificates do not match (`certRefreshForced`)
     */
    public function __construct(
        private string $certificate,
        #[SensitiveParameter]
        string $key,
        private array $subdomains,
        private array $intermediates = [],
        #[SensitiveParameter]
        ?string $password = null,
        private ?int $replaces = null,
        private bool $force = false,
    ) {
        $this->key = new SensitiveParameterValue($key);
        $this->password = $password === null ? null : new SensitiveParameterValue($password);
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function toJson(): array
    {
        $json = [
            'objectType' => 'SslCertVO',
            'cert' => $this->certificate,
            'key' => $this->key->getValue(),
            'subdomains' => $this->subdomains,
            'intermediates' => array_map(static fn(string $pem): array => ['cert' => $pem], $this->intermediates),
        ];
        if ($this->password !== null) {
            $json['password'] = $this->password->getValue();
        }
        if ($this->replaces !== null) {
            $json['certToRefresh'] = $this->replaces;
            $json['certRefreshForced'] = $this->force;
        }

        return $json;
    }
}
