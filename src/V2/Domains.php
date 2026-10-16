<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V2;

use Myracloud\WebApi\Contract;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\Domain;
use Myracloud\WebApi\Model\Page;

/**
 * `GET /domains`, `?page={page}` from page 2 on; `GET /domains/{id}`.
 */
final readonly class Domains implements Contract\Domains
{
    /**
     * @internal built by {@see Client}
     */
    public function __construct(private Transport $transport) {}

    public function list(): array
    {
        return Page::collect($this->page(...));
    }

    /**
     * One domain with everything Myra has on it (permissions, verified, environment, … in
     * raw()); the id from list() or {@see Client::domainId()}.
     *
     * @throws \Myracloud\WebApi\Exception\MyraException
     */
    public function get(int $id): Domain
    {
        return Domain::fromJson($this->transport->send('GET', 'domains/' . $id)->first('data'));
    }

    public function page(int $page): Page
    {
        $body = $this->transport->send('GET', 'domains', self::query(Page::number($page)));

        return Page::fromJson($body, 'data', Domain::fromJson(...));
    }

    /**
     * Page 1 without query: the first request is then exactly the captured one (HMAC over a
     * query string is not verified against Myra yet, see docs/2.0-design.md, Open).
     *
     * @internal
     *
     * @return array<string, int>
     */
    public static function query(int $page): array
    {
        return $page === 1 ? [] : ['page' => $page];
    }
}
