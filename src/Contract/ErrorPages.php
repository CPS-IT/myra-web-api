<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Contract;

use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Model\ErrorPage;

/**
 * The error pages of a domain's subdomains. See {@see Client} on implementing this
 * interface. `$domain` is the zone; pages are selected by subdomain and error code
 * (ErrorPage::CODES).
 */
interface ErrorPages
{
    /**
     * All error pages of the domain, without their content.
     *
     * @return list<ErrorPage>
     *
     * @throws MyraException
     */
    public function list(string $domain): array;

    /**
     * One error page with its content.
     *
     * @throws MyraException
     */
    public function get(string $domain, int $id): ErrorPage;

    /**
     * Sets the page for every selected subdomain and code — creates it or replaces its content.
     *
     * @param array<string, list<int>> $codesBySubdomain e.g. ['www.example.com' => [500, 503]]
     *
     * @throws InvalidArgumentException for an empty selection or an unsupported code
     * @throws MyraException
     */
    public function set(string $domain, string $html, array $codesBySubdomain): void;

    /**
     * @param array<string, list<int>> $codesBySubdomain
     *
     * @throws InvalidArgumentException for an empty selection or an unsupported code
     * @throws MyraException
     */
    public function delete(string $domain, array $codesBySubdomain): void;
}
