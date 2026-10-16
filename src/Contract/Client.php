<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Contract;

/**
 * The operations both API versions offer; implemented by V1\Client and V2\Client.
 *
 * The interfaces in Contract\ are for type hints. Methods may be added in minor releases,
 * so implementing them outside this package is not covered by the compatibility promise.
 */
interface Client
{
    public function domains(): Domains;

    public function dnsRecords(): DnsRecords;

    public function cacheClear(): CacheClearing;

    public function redirects(): Redirects;

    public function ipFilters(): IpFilters;

    public function cacheSettings(): CacheSettings;

    public function subdomainSettings(): SubdomainSettings;

    public function maintenances(): Maintenances;

    public function errorPages(): ErrorPages;

    public function ipRanges(): IpRanges;

    public function statistics(): Statistics;

    public function sslCertificates(): SslCertificates;

    public function zones(): Zones;
}
