<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Contract;

use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Model\Zone;

/**
 * A domain's zone for BIND, as data or as zone file (read only). See {@see Client} on
 * implementing this interface. v1 and v2 answer the same (captured); v2 can also export the
 * zone with the origin's addresses ({@see \Myracloud\WebApi\V2\Zones}).
 */
interface Zones
{
    /**
     * @throws MyraException
     */
    public function get(string $domain): Zone;

    /**
     * The zone file (text).
     *
     * @throws MyraException
     */
    public function file(string $domain): string;
}
