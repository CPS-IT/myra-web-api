<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V2;

use Myracloud\WebApi\Contract;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\CacheClear;
use Myracloud\WebApi\Model\CacheClearBody;

/**
 * `PUT /domain/{domainId}/cache-clear`; the confirmation is in `data`.
 */
final readonly class CacheClearing implements Contract\CacheClearing
{
    /**
     * @internal built by {@see Client}
     */
    public function __construct(
        private Transport $transport,
        private DomainIdResolver $domainIds,
    ) {}

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
        $response = $this->transport->send('PUT', 'domain/' . $this->domainIds->resolve($domain) . '/cache-clear', body: $body);

        return array_map(CacheClear::fromJson(...), $response->objects('data'));
    }
}
