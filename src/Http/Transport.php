<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Http;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\RequestOptions;
use JsonException;
use Myracloud\WebApi\Auth\Authentication;
use Myracloud\WebApi\Exception\AccessDeniedException;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Exception\RequestRejectedException;
use Myracloud\WebApi\Exception\TransportException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Sends one JSON request to the Myra API and returns the parsed envelope.
 *
 * Any PSR-18 client works; without one, Guzzle is used. Its PSR-18 `sendRequest()` already
 * runs without `http_errors` and redirects, so the envelope decides; the timeouts are set
 * here because Guzzle's default is "wait forever".
 *
 * @internal built by V1\Client and V2\Client
 */
final readonly class Transport
{
    private string $baseUri;
    private ClientInterface $client;
    private RequestFactoryInterface $requestFactory;
    private StreamFactoryInterface $streamFactory;

    public function __construct(
        private Authentication $authentication,
        string $baseUri,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->baseUri = rtrim($baseUri, '/');
        $this->client = $client ?? self::defaultClient();
        $this->requestFactory = $requestFactory ?? new HttpFactory();
        $this->streamFactory = $streamFactory ?? new HttpFactory();
    }

    public static function defaultClient(): ClientInterface
    {
        return new GuzzleClient(self::defaultOptions());
    }

    /**
     * Guzzle's own default is no timeout at all.
     *
     * @return array{connect_timeout: int, timeout: int}
     */
    public static function defaultOptions(): array
    {
        return [RequestOptions::CONNECT_TIMEOUT => 10, RequestOptions::TIMEOUT => 30];
    }

    /**
     * @param string $path relative to the base URI, e.g. "domain/1000/cache-clear"
     * @param array<string, scalar> $query
     * @param array<mixed>|null $body sent as JSON; null sends no body
     *
     * @throws InvalidArgumentException if the body cannot be encoded as JSON
     * @throws RequestRejectedException
     * @throws AccessDeniedException
     * @throws TransportException
     */
    public function send(string $method, string $path, array $query = [], ?array $body = null): JsonObject
    {
        $uri = $this->baseUri . '/' . ltrim($path, '/');
        if ($query !== []) {
            $uri .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $request = $this->requestFactory->createRequest($method, $uri)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Content-Type', 'application/json');
        if ($body !== null) {
            $request = $request->withBody($this->streamFactory->createStream(self::encode($body)));
        }
        // Last, on the final request: an HMAC signature covers method, path, query and body.
        $request = $this->authentication->authenticate($request);

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new TransportException(sprintf('%s %s: %s', $method, $uri, $exception->getMessage()), $exception);
        }

        return Envelope::parse($request, $response);
    }

    /**
     * @param array<mixed> $body
     */
    private static function encode(array $body): string
    {
        try {
            return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The request body cannot be encoded as JSON: ' . $exception->getMessage(), previous: $exception);
        }
    }
}
