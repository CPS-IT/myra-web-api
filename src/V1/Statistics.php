<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V1;

use Myracloud\WebApi\Contract;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\StatisticQuery;
use Myracloud\WebApi\Model\StatisticResult;

/**
 * `POST /{lang}/rapi/statistic/query`
 */
final readonly class Statistics implements Contract\Statistics
{
    /**
     * @internal built by {@see Client}
     */
    public function __construct(private Transport $transport) {}

    public function query(StatisticQuery $query): StatisticResult
    {
        return StatisticResult::fromJson($this->transport->send('POST', 'statistic/query', body: $query->toJson()));
    }
}
