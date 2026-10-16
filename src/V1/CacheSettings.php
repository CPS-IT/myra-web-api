<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V1;

use Myracloud\WebApi\Contract;
use Myracloud\WebApi\Http\JsonObject;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\CacheSetting;
use Myracloud\WebApi\Model\DomainName;
use Myracloud\WebApi\Model\Page;
use Myracloud\WebApi\Model\Persisted;

/**
 * `/{lang}/rapi/cacheSettings/{subdomain}`: GET `/{page}` lists, PUT creates, POST updates,
 * DELETE deletes (body: id, modified).
 */
final readonly class CacheSettings implements Contract\CacheSettings
{
    /**
     * @internal built by {@see Client}
     */
    public function __construct(private Transport $transport) {}

    public function list(string $subdomain): array
    {
        return Page::collect(fn(int $page): Page => $this->page($subdomain, $page));
    }

    public function page(string $subdomain, int $page): Page
    {
        $subdomain = DomainName::normalize($subdomain);

        return Page::fromJson(
            $this->transport->send('GET', self::path($subdomain) . '/' . Page::number($page)),
            'list',
            static fn(JsonObject $json): CacheSetting => CacheSetting::fromJson($json, $subdomain),
        );
    }

    public function create(CacheSetting $setting): CacheSetting
    {
        Persisted::assertNew($setting->id(), 'cache setting');

        return CacheSetting::fromJson($this->transport->send('PUT', self::path($setting->subdomain()), body: $setting->toJson())->first('targetObject'), $setting->subdomain());
    }

    public function update(CacheSetting $setting): CacheSetting
    {
        Persisted::versionOf($setting->id(), $setting->modified(), 'cache setting');

        return CacheSetting::fromJson($this->transport->send('POST', self::path($setting->subdomain()), body: $setting->toJson())->first('targetObject'), $setting->subdomain());
    }

    public function delete(CacheSetting $setting): void
    {
        [$id, $modified] = Persisted::versionOf($setting->id(), $setting->modified(), 'cache setting');
        $this->transport->send('DELETE', self::path($setting->subdomain()), body: ['id' => $id, 'modified' => $modified]);
    }

    private static function path(string $subdomain): string
    {
        return 'cacheSettings/' . rawurlencode(DomainName::normalize($subdomain));
    }
}
