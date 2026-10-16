<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V1;

use Myracloud\WebApi\Contract;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\DomainName;
use Myracloud\WebApi\Model\Settings;

/**
 * `/{lang}/rapi/subdomainSetting/{subdomain}`: GET (`?flat` merged), POST updates.
 */
final readonly class SubdomainSettings implements Contract\SubdomainSettings
{
    /**
     * @internal built by {@see Client}
     */
    public function __construct(private Transport $transport) {}

    /**
     * @phpstan-impure reads remote state
     */
    public function get(string $subdomain): Settings
    {
        $subdomain = DomainName::normalize($subdomain);

        return Settings::fromJson($this->transport->send('GET', self::path($subdomain)), $subdomain);
    }

    /**
     * @phpstan-impure reads remote state
     */
    public function effective(string $subdomain): array
    {
        // literally "?flat", as captured: the HMAC signature covers the query
        return self::map($this->transport->send('GET', self::path($subdomain) . '?flat')->toArray());
    }

    public function update(string $subdomain, array $settings): Settings
    {
        if ($settings === []) {
            // a JSON object, as probed — an empty PHP array would be sent as []
            $this->transport->sendRaw('POST', self::path($subdomain), [], '{}', 'application/json');
        } else {
            $this->transport->send('POST', self::path($subdomain), body: $settings);
        }

        return $this->get($subdomain);
    }

    private static function path(string $subdomain): string
    {
        return 'subdomainSetting/' . rawurlencode(DomainName::normalize($subdomain));
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
