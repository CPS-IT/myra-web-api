<?php
declare(strict_types=1);

namespace Myracloud\WebApi\Endpoint;

use DateTimeInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Myracloud\WebApi\Exception\EndpointException;
use Myracloud\WebApi\Exception\UnexpectedResponseException;
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
     * @throws UnexpectedResponseException
     */
    protected function handleResponse(ResponseInterface $response): array
    {
        if ($response->getStatusCode() != 200) {
            throw new UnexpectedResponseException(
                'Invalid Response. ' . $response->getStatusCode() . ' ' . $response->getReasonPhrase()
            );
        }

        try {
            return json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new UnexpectedResponseException('json_decode error: ' . $e->getMessage(), 0, $e);
        }
    }
}
