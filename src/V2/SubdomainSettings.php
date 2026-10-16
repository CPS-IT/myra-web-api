<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V2;

use Myracloud\WebApi\Contract;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\DomainName;
use Myracloud\WebApi\Model\Settings;

/**
 * `/domain/{domainId}/{vHostName}/settings`: GET (`?flat` merged; answers in `targetObject` like
 * v1), POST updates (answers in `data`).
 */
final readonly class SubdomainSettings implements Contract\SubdomainSettings
{
    /**
     * @internal built by {@see Client}
     */
    public function __construct(
        private Transport $transport,
        private DomainIdResolver $domainIds,
    ) {}

    /**
     * @phpstan-impure reads remote state
     */
    public function get(string $subdomain): Settings
    {
        $subdomain = DomainName::normalize($subdomain);

        return Settings::fromJson($this->transport->send('GET', $this->path($subdomain)), $subdomain);
    }

    /**
     * @phpstan-impure reads remote state
     */
    public function effective(string $subdomain): array
    {
        // literally "?flat", as captured: the HMAC signature covers the query
        return self::map($this->transport->send('GET', $this->path($subdomain) . '?flat')->toArray());
    }

    public function update(string $subdomain, array $settings): Settings
    {
        if ($settings === []) {
            // a JSON object, as probed — an empty PHP array would be sent as []
            $this->transport->sendRaw('POST', $this->path($subdomain), [], '{}', 'application/json');
        } else {
            $this->transport->send('POST', $this->path($subdomain), body: $settings);
        }

        return $this->get($subdomain);
    }

    private function path(string $subdomain): string
    {
        $subdomain = DomainName::normalize($subdomain);

        return 'domain/' . $this->domainIds->resolveHost($subdomain) . '/' . rawurlencode($subdomain) . '/settings';
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function map(array $values): array
    {
        $map = [];
        foreach ($values as $name => $value) {
            $map[(string) $name] = $value;
        }

        return $map;
    }
}
