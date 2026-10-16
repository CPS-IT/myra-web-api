<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Contract;

use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Model\Settings;

/**
 * The settings of a subdomain (vhost). See {@see Client} on implementing this interface;
 * `$subdomain` as in {@see Redirects}. Settings cannot be created or deleted, only read and
 * changed; there is no optimistic lock.
 */
interface SubdomainSettings
{
    /**
     * Own and inherited settings.
     *
     * @phpstan-impure reads remote state: two calls may differ
     *
     * @throws MyraException
     */
    public function get(string $subdomain): Settings;

    /**
     * The settings that apply, merged by Myra (`?flat`).
     *
     * @phpstan-impure reads remote state: two calls may differ
     *
     * @return array<string, mixed>
     *
     * @throws MyraException
     */
    public function effective(string $subdomain): array;

    /**
     * Sets the vhost's own settings and returns them read again.
     *
     * Whether Myra replaces the own settings with these or merges them is NOT verified (only
     * an unchanged write was probed): always send the complete own settings with your
     * changes — `['hsts' => true] + $settings->own()` — which is correct either way.
     *
     * @param array<string, mixed> $settings
     *
     * @throws MyraException
     */
    public function update(string $subdomain, array $settings): Settings;
}
