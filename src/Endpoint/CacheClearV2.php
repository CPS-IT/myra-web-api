<?php
declare(strict_types=1);

namespace Myracloud\WebApi\Endpoint;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

class CacheClearV2 extends AbstractEndpointV2
{
    protected const ENDPOINT = 'cache-clear';

    /**
     * @return array
     * @throws GuzzleException
     */
    public function clear(int $domainId, string $fqdn, string $resource, bool $recursive = false): array
    {
        $options[RequestOptions::JSON] =
            [
                "fqdn"      => $fqdn,
                "resource"  => $resource,
                "recursive" => $recursive,
            ];
        return $this->handleResponse(
            $this->client->request('PUT', '/domain/' . $domainId . '/' . static::ENDPOINT, $options)
        );
    }
}
