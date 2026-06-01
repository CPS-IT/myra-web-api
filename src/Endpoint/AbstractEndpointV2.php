<?php
declare(strict_types=1);

namespace Myracloud\WebApi\Endpoint;

use DateTimeInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Utils;
use Myracloud\WebApi\Exception\EndpointException;
use Psr\Http\Message\ResponseInterface;

abstract class AbstractEndpointV2
{
    protected const ENDPOINT = '';

    /**
     * Domain constructor.
     *
     * @param Client $client
     * @throws EndpointException
     */
    public function __construct(
        protected readonly Client $client
    ) {
        if (static::ENDPOINT === '') {
            throw new EndpointException('Must define endpoint in '. static::class .'::ENDPOINT');
        }
    }

    /**
     * @return array
     * @throws GuzzleException
     */
    public function delete(int $id): array
    {
        return $this->handleResponse(
            $this->client->request('DELETE', static::ENDPOINT . '/' . $id)
        );
    }

    /**
     * @return array
     * @throws GuzzleException
     */
    public function getList(?int $id = null): array
    {
        return $this->handleResponse(
            $this->client->get(
                static::ENDPOINT . ($id !== null ? '/' . $id : '')
            )
        );
    }

    public function getEndPoint(): string
    {
        return static::ENDPOINT;
    }

    /**
     * @param ResponseInterface $response
     * @return array
     */
    protected function handleResponse(ResponseInterface $response): array
    {
        if ($response->getStatusCode() != 200) {
            throw new TransferException(
                'Invalid Response. ' . $response->getStatusCode() . ' ' . $response->getReasonPhrase()
            );
        }

        return Utils::jsonDecode($response->getBody()->getContents(), true);
    }
}
