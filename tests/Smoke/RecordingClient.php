<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\Smoke;

use Closure;
use GuzzleHttp\Client as Guzzle;
use LogicException;
use Myracloud\WebApi\Http\Transport;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 client for runs against the real API: sends through Guzzle, keeps every exchange
 * in memory (never on disk), and refuses — before sending — every write its guard does not
 * allow.
 */
final class RecordingClient implements ClientInterface
{
    /** @var list<array{RequestInterface, ResponseInterface}> */
    private array $exchanges = [];
    private Guzzle $guzzle;

    /**
     * @param Closure(RequestInterface): bool $allowWrite
     */
    public function __construct(private readonly Closure $allowWrite)
    {
        $this->guzzle = new Guzzle(Transport::defaultOptions());
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() !== 'GET' && !($this->allowWrite)($request)) {
            throw new LogicException('Refused by the write guard: ' . $request->getMethod() . ' ' . $request->getUri()->getPath());
        }
        usleep(300_000);
        $response = $this->guzzle->sendRequest($request);
        $this->exchanges[] = [$request, $response];

        return $response;
    }

    /**
     * The exchanges since the last call, oldest first.
     *
     * @return list<array{RequestInterface, ResponseInterface}>
     */
    public function take(): array
    {
        $exchanges = $this->exchanges;
        $this->exchanges = [];

        return $exchanges;
    }
}
