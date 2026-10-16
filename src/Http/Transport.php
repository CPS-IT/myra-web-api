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
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
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
        return Envelope::parse(...$this->exchange($method, $path, $query, $body === null ? null : self::encode($body), 'application/json'));
    }

    /**
     * A request with a body that is not JSON (e.g. CSV), answered with an envelope.
     *
     * @param array<string, scalar> $query
     *
     * @throws RequestRejectedException
     * @throws AccessDeniedException
     * @throws TransportException
     */
    public function sendRaw(string $method, string $path, array $query, string $body, string $contentType): JsonObject
    {
        return Envelope::parse(...$this->exchange($method, $path, $query, $body, $contentType));
    }

    /**
     * A GET answered with text instead of an envelope (CSV export, zone file); failures still
     * come as envelopes or the usual denials.
     *
     * @param array<string, scalar> $query
     *
     * @throws RequestRejectedException
     * @throws AccessDeniedException
     * @throws TransportException
     */
    public function fetchText(string $path, array $query = []): string
    {
        [$request, $response] = $this->exchange('GET', $path, $query, null, 'application/json');
        $status = $response->getStatusCode();
        $type = $response->getHeaderLine('Content-Type');
        if ($status >= 200 && $status < 300 && !str_contains(strtolower($type), 'json')) {
            return (string) $response->getBody();
        }
        Envelope::parse($request, $response); // throws for every failure

        throw new TransportException(sprintf('%s %s: expected text, got %s (HTTP %d).', 'GET', $request->getUri(), $type === '' ? 'no content type' : $type, $status));
    }

    /**
     * A request answered with a bare JSON list instead of an envelope (v2 statistics);
     * failures still come as envelopes or the usual denials. Myra answers `null` for no
     * entries (captured); an empty body is not a list.
     *
     * @param array<mixed>|null $body sent as JSON; null sends no body
     *
     * @return list<JsonObject>
     *
     * @throws InvalidArgumentException if the body cannot be encoded as JSON
     * @throws RequestRejectedException
     * @throws AccessDeniedException
     * @throws TransportException
     */
    public function sendList(string $method, string $path, ?array $body = null): array
    {
        [$request, $response] = $this->exchange($method, $path, [], $body === null ? null : self::encode($body), 'application/json');
        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            $decoded = json_decode((string) $response->getBody(), true);
            if ($decoded === null && trim((string) $response->getBody()) === 'null') {
                return [];
            }
            if (is_array($decoded) && array_is_list($decoded)) {
                return (new JsonObject(['list' => $decoded]))->objects('list');
            }
        }
        Envelope::parse($request, $response); // throws for every failure

        throw new TransportException(sprintf('%s %s: expected a JSON list (HTTP %d).', $method, $request->getUri(), $status));
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return array{RequestInterface, ResponseInterface}
     *
     * @throws TransportException
     */
    private function exchange(string $method, string $path, array $query, ?string $body, string $contentType): array
    {
        $uri = $this->baseUri . '/' . ltrim($path, '/');
        if ($query !== []) {
            $uri .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $request = $this->requestFactory->createRequest($method, $uri)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Content-Type', $contentType);
        if ($body !== null) {
            $request = $request->withBody($this->streamFactory->createStream($body));
        }
        // Last, on the final request: an HMAC signature covers method, path, query, content type and body.
        $request = $this->authentication->authenticate($request);

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new TransportException(sprintf('%s %s: %s', $method, $uri, $exception->getMessage()), $exception);
        }

        return [$request, $response];
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
