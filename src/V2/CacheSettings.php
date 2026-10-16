<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V2;

use Myracloud\WebApi\Contract;
use Myracloud\WebApi\Http\JsonObject;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\CacheSetting;
use Myracloud\WebApi\Model\DomainName;
use Myracloud\WebApi\Model\Page;
use Myracloud\WebApi\Model\Persisted;

/**
 * `/domain/{domainId}/{vHostName}/cache-settings` (the vhost before the area): GET lists
 * (`?page=`), POST creates; with `/{id}`: PUT updates, DELETE deletes.
 */
final readonly class CacheSettings implements Contract\CacheSettings
{
    /**
     * @internal built by {@see Client}
     */
    public function __construct(
        private Transport $transport,
        private DomainIdResolver $domainIds,
    ) {}

    public function list(string $subdomain): array
    {
        return Page::collect(fn(int $page): Page => $this->page($subdomain, $page));
    }

    public function page(string $subdomain, int $page): Page
    {
        $subdomain = DomainName::normalize($subdomain);
        $page = Page::number($page);

        return Page::fromJson(
            $this->transport->send('GET', $this->path($subdomain), Domains::query($page)),
            'data',
            static fn(JsonObject $json): CacheSetting => CacheSetting::fromJson($json, $subdomain),
        );
    }

    public function create(CacheSetting $setting): CacheSetting
    {
        Persisted::assertNew($setting->id(), 'cache setting');

        return CacheSetting::fromJson($this->transport->send('POST', $this->path($setting->subdomain()), body: $setting->toJson())->first('data'), $setting->subdomain());
    }

    public function update(CacheSetting $setting): CacheSetting
    {
        [$id] = Persisted::versionOf($setting->id(), $setting->modified(), 'cache setting');

        return CacheSetting::fromJson($this->transport->send('PUT', $this->path($setting->subdomain()) . '/' . $id, body: $setting->toJson())->first('data'), $setting->subdomain());
    }

    public function delete(CacheSetting $setting): void
    {
        [$id] = Persisted::versionOf($setting->id(), $setting->modified(), 'cache setting');
        $this->transport->send('DELETE', $this->path($setting->subdomain()) . '/' . $id);
    }

    private function path(string $subdomain): string
    {
        $subdomain = DomainName::normalize($subdomain);

        return 'domain/' . $this->domainIds->resolveHost($subdomain) . '/' . rawurlencode($subdomain) . '/cache-settings';
    }
}
