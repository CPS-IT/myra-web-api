<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\Support;

use LogicException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 client for tests: records every request and answers with queued responses (or
 * throws queued client exceptions), in order. No network.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<ResponseInterface|ClientExceptionInterface> */
    private array $queue = [];
    /** @var list<RequestInterface> */
    private array $requests = [];

    public function queue(ResponseInterface|ClientExceptionInterface ...$answers): self
    {
        array_push($this->queue, ...$answers);

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $answer = array_shift($this->queue) ?? throw new LogicException('Unexpected request: ' . $request->getMethod() . ' ' . $request->getUri());
        if ($answer instanceof ClientExceptionInterface) {
            throw $answer;
        }

        return $answer;
    }

    /**
     * @return list<RequestInterface>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    public function lastRequest(): RequestInterface
    {
        return $this->requests[array_key_last($this->requests) ?? throw new LogicException('No request was sent.')];
    }
}
