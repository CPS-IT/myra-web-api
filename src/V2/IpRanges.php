<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V2;

use Myracloud\WebApi\Contract;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\IpRange;
use Myracloud\WebApi\Model\Page;

/**
 * `GET /ip-ranges`, `?page={page}` from page 2 on.
 */
final readonly class IpRanges implements Contract\IpRanges
{
    /**
     * @internal built by {@see Client}
     */
    public function __construct(private Transport $transport) {}

    public function active(): array
    {
        return array_values(array_filter($this->list(), static fn(IpRange $range): bool => $range->enabled()));
    }

    public function list(): array
    {
        return Page::collect($this->page(...));
    }

    public function page(int $page): Page
    {
        $body = $this->transport->send('GET', 'ip-ranges', Domains::query(Page::number($page)));

        return Page::fromJson($body, 'data', IpRange::fromJson(...));
    }
}
