<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V2;

use Myracloud\WebApi\Auth\Authentication;
use Myracloud\WebApi\Contract;
use Myracloud\WebApi\Exception\DomainNotFoundException;
use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Http\Transport;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Myra API v2 (`https://apiv2.myracloud.com/…`).
 *
 * v2 addresses domains by numeric id; the client takes names like v1 and looks the id up
 * itself ({@see domainId()}). Works with both {@see \Myracloud\WebApi\Auth\HmacSignature}
 * and {@see \Myracloud\WebApi\Auth\BearerToken}.
 */
final readonly class Client implements Contract\Client
{
    public const DEFAULT_BASE_URI = 'https://apiv2.myracloud.com';

    private Domains $domains;
    private DomainIdResolver $domainIds;
    private DnsRecords $dnsRecords;
    private CacheClearing $cacheClear;
    private Redirects $redirects;

    /**
     * @param string $baseUri e.g. of a local emulator
     * @param ClientInterface|null $httpClient any PSR-18 client; default: Guzzle with timeouts
     */
    public function __construct(
        Authentication $authentication,
        string $baseUri = self::DEFAULT_BASE_URI,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $transport = new Transport($authentication, $baseUri, $httpClient, $requestFactory, $streamFactory);
        $this->domains = new Domains($transport);
        $this->domainIds = new DomainIdResolver($this->domains);
        $this->dnsRecords = new DnsRecords($transport, $this->domainIds);
        $this->cacheClear = new CacheClearing($transport, $this->domainIds);
        $this->redirects = new Redirects($transport, $this->domainIds);
    }

    public function domains(): Domains
    {
        return $this->domains;
    }

    public function dnsRecords(): DnsRecords
    {
        return $this->dnsRecords;
    }

    public function cacheClear(): CacheClearing
    {
        return $this->cacheClear;
    }

    public function redirects(): Redirects
    {
        return $this->redirects;
    }

    /**
     * The numeric id of a domain, looked up in `/domains` and cached for this client.
     *
     * @throws DomainNotFoundException
     * @throws MyraException
     */
    public function domainId(string $domain): int
    {
        return $this->domainIds->resolve($domain);
    }
}
