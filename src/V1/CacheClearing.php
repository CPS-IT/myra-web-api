<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V1;

use Myracloud\WebApi\Contract;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\CacheClear;
use Myracloud\WebApi\Model\CacheClearBody;
use Myracloud\WebApi\Model\DomainName;

/**
 * `PUT /{lang}/rapi/cacheClear/{domain}`; the confirmation is in `targetObject`.
 */
final readonly class CacheClearing implements Contract\CacheClearing
{
    /**
     * @internal built by {@see Client}
     */
    public function __construct(private Transport $transport) {}

    public function clear(string $domain, string $fqdn, string $resource, bool $recursive = false): array
    {
        return $this->send($domain, CacheClearBody::single($fqdn, $resource, $recursive));
    }

    public function clearBulk(string $domain, string $fqdn, array $resources, bool $recursive = false): array
    {
        return $this->send($domain, CacheClearBody::bulk($fqdn, $resources, $recursive));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<CacheClear>
     */
    private function send(string $domain, array $body): array
    {
        $response = $this->transport->send('PUT', 'cacheClear/' . rawurlencode(DomainName::normalize($domain)), body: $body);

        return array_map(CacheClear::fromJson(...), $response->objects('targetObject'));
    }
}
