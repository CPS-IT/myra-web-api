<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Contract;

use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Model\StatisticQuery;
use Myracloud\WebApi\Model\StatisticResult;

/**
 * Statistics (read only, sent as POST). See {@see Client} on implementing this interface.
 *
 * v1 and v2 take the same query and answer the same StatisticVO (captured). v2 adds ready-made
 * dashboard statistics ({@see \Myracloud\WebApi\V2\Statistics}).
 */
interface Statistics
{
    /**
     * @throws InvalidArgumentException without a data source
     * @throws MyraException — an unknown data source is a TransportException (Myra: HTTP 500,
     *                        no details; captured with v1)
     */
    public function query(StatisticQuery $query): StatisticResult;
}
