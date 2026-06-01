<?php

declare(strict_types=1);

namespace Myracloud\WebApi;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Myracloud\WebApi\Endpoint\CacheClearV2;
use Myracloud\WebApi\Endpoint\CacheSetting;
use Myracloud\WebApi\Endpoint\Certificate;
use Myracloud\WebApi\Endpoint\DnsRecordV2;
use Myracloud\WebApi\Endpoint\DomainV2;
use Myracloud\WebApi\Endpoint\IpFilter;
use Myracloud\WebApi\Endpoint\Maintenance;
use Myracloud\WebApi\Endpoint\Networks;
use Myracloud\WebApi\Endpoint\Redirect;
use Myracloud\WebApi\Endpoint\Statistic;
use Myracloud\WebApi\Endpoint\SubdomainSetting;
use Psr\Http\Message\RequestInterface;

class WebApiV2
{
    private const DEFAULT_API_DOMAIN = 'apiv2.myracloud.com';

    protected Client $client;

    /**
     * @var array
     */
    private array $endpointCache = [];

    /**
     * @param array $connectionConfig
     * @param callable|null $requestHandler default is CurlHandler
     */
    public function __construct(
        string $token,
        string $site = self::DEFAULT_API_DOMAIN,
        array $connectionConfig = [],
        ?callable $requestHandler = null
    ) {
        if (!is_callable($requestHandler)) {
            $requestHandler = new CurlHandler();
        }

        $stack = HandlerStack::create($requestHandler);
        $stack->push(
            Middleware::mapRequest(
                static fn(RequestInterface $request) => $request->withHeader('Authorization', 'Bearer ' . $token),
            ),
        );

        if ($site === '') {
            $site = self::DEFAULT_API_DOMAIN;
        }

        $this->client = new Client(
            $connectionConfig + [
                'base_uri' => 'https://' . $site . '/',
                'handler'  => $stack,
            ],
        );
    }

    public static function isV2(string $site): bool
    {
        return $site === self::DEFAULT_API_DOMAIN;
    }

    /**
     * @template T of object
     * @param class-string<T> $className
     * @return T the created instance
     */
    private function getInstance(string $className)
    {
        return $this->endpointCache[$className] ??= new $className($this->client);
    }

    public function getDomainEndpoint(): DomainV2
    {
        return $this->getInstance(DomainV2::class);
    }

    public function getRedirectEndpoint(): Redirect
    {
        return $this->getInstance(Redirect::class);
    }

    public function getCacheSettingsEndpoint(): CacheSetting
    {
        return $this->getInstance(CacheSetting::class);
    }

    public function getCertificateEndpoint(): Certificate
    {
        return $this->getInstance(Certificate::class);
    }

    public function getSubdomainSettingsEndpoint(): SubdomainSetting
    {
        return $this->getInstance(SubdomainSetting::class);
    }

    public function getDnsRecordEndpoint(): DnsRecordV2
    {
        return $this->getInstance(DnsRecordV2::class);
    }

    public function getStatisticEndpoint(): Statistic
    {
        return $this->getInstance(Statistic::class);
    }

    public function getMaintenanceEndpoint(): Maintenance
    {
        return $this->getInstance(Maintenance::class);
    }

    public function getIpFilterEndpoint(): IpFilter
    {
        return $this->getInstance(IpFilter::class);
    }

    public function getCacheClearEndpoint(): CacheClearV2
    {
        return $this->getInstance(CacheClearV2::class);
    }

    public function getNetworksEndpoint(): Networks
    {
        return $this->getInstance(Networks::class);
    }
}
