<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Contract;

use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Model\IpRange;
use Myracloud\WebApi\Model\Page;

/**
 * Myra's own networks (account-wide, read only). See {@see Client} on implementing this interface.
 *
 * The versions list different sets (captured): v1 returns only the enabled ranges, v2 all
 * of them, including disabled ones that expired years ago. list() and page() pass on what
 * the version returns; active() is the same in both — use it for a firewall allowlist.
 */
interface IpRanges
{
    /**
     * The enabled ranges, in both versions.
     *
     * @return list<IpRange>
     *
     * @throws MyraException
     */
    public function active(): array;

    /**
     * Every range the version lists (v1: enabled only, v2: all): fetches every page before it returns.
     *
     * @return list<IpRange>
     *
     * @throws MyraException
     */
    public function list(): array;

    /**
     * @param int $page 1-based
     *
     * @return Page<IpRange>
     *
     * @throws MyraException
     */
    public function page(int $page): Page;
}
