<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V1;

use Myracloud\WebApi\Contract;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\Domain;
use Myracloud\WebApi\Model\Page;

/**
 * `GET /{lang}/rapi/domains/{page}`
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

    public function page(int $page): Page
    {
        $body = $this->transport->send('GET', 'domains/' . Page::number($page));

        return Page::fromJson($body, 'list', Domain::fromJson(...));
    }
}
