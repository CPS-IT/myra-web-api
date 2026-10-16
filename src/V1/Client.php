<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V1;

use Myracloud\WebApi\Auth\Authentication;
use Myracloud\WebApi\Contract;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Http\Transport;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Myra API v1 (`https://api.myracloud.com/{language}/rapi/…`), addressed by domain name.
 *
 * Works with both {@see \Myracloud\WebApi\Auth\HmacSignature} and
 * {@see \Myracloud\WebApi\Auth\BearerToken}.
 */
final readonly class Client implements Contract\Client
{
    public const DEFAULT_BASE_URI = 'https://api.myracloud.com';
    public const LANGUAGES = ['en', 'de'];

    private Domains $domains;
    private DnsRecords $dnsRecords;
    private CacheClearing $cacheClear;
    private Redirects $redirects;

    /**
     * @param string $language language of Myra's messages (violations): "en" or "de"
     * @param string $baseUri scheme and host, e.g. of a local emulator; "/{language}/rapi" is appended
     * @param ClientInterface|null $httpClient any PSR-18 client; default: Guzzle with timeouts
     *
     * @throws InvalidArgumentException for an unsupported language
     */
    public function __construct(
        Authentication $authentication,
        string $language = 'en',
        string $baseUri = self::DEFAULT_BASE_URI,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        if (!in_array($language, self::LANGUAGES, true)) {
            throw new InvalidArgumentException(sprintf('API v1 speaks "en" or "de", "%s" given.', $language));
        }
        $transport = new Transport(
            $authentication,
            rtrim($baseUri, '/') . '/' . $language . '/rapi',
            $httpClient,
            $requestFactory,
            $streamFactory,
        );
        $this->domains = new Domains($transport);
        $this->dnsRecords = new DnsRecords($transport);
        $this->cacheClear = new CacheClearing($transport);
        $this->redirects = new Redirects($transport);
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
}
