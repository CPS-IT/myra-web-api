<?php
declare(strict_types=1);

namespace Myracloud\WebApi\Endpoint;

use DateTimeInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

class DomainV2 extends AbstractEndpointV2
{
    protected const ENDPOINT = 'domains';

    /**
     * @param string $name
     * @param bool $autoUpdate
     * @return array
     * @throws GuzzleException
     */
    public function create(string $name, bool $autoUpdate = false): array
    {
        $options[RequestOptions::JSON] =
            [
                'name'       => $name,
                'autoUpdate' => $autoUpdate,
            ];
        return $this->handleResponse($this->client->request('PUT', static::ENDPOINT, $options));
    }

    /**
     * @return array
     * @throws GuzzleException
     */
    public function update(int $id, DateTimeInterface $modified, bool $autoUpdate = false): array
    {
        $options[RequestOptions::JSON] =
            [
                'modified'   => $modified->format(DATE_RFC3339),
                'autoUpdate' => $autoUpdate,
            ];

        return $this->handleResponse(
            $this->client->request('POST', static::ENDPOINT . '/' . $id, $options)
        );
    }
}
