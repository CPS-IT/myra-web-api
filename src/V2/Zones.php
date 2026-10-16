<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V2;

use Myracloud\WebApi\Contract;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\Zone;

/**
 * `GET /domains/{id}/bind/{ipTarget}` (data), `…/bind-raw/{ipTarget}` (zone file).
 *
 * The ipTarget: TARGET_MYRA exports the zone as the world sees it (default, like v1),
 * TARGET_ORIGIN with the origin's addresses — for the captured zone both answered the same.
 */
final readonly class Zones implements Contract\Zones
{
    public const TARGET_MYRA = 'Myra';
    public const TARGET_ORIGIN = 'Origin';

    /**
     * @internal built by {@see Client}
     */
    public function __construct(
        private Transport $transport,
        private DomainIdResolver $domainIds,
    ) {}

    /**
     * @param string $target TARGET_MYRA or TARGET_ORIGIN
     *
     * @throws InvalidArgumentException for another target
     */
    public function get(string $domain, string $target = self::TARGET_MYRA): Zone
    {
        return Zone::fromJson($this->transport->send('GET', $this->path($domain, 'bind', $target))->first('data'));
    }

    /**
     * @param string $target TARGET_MYRA or TARGET_ORIGIN
     *
     * @throws InvalidArgumentException for another target
     */
    public function file(string $domain, string $target = self::TARGET_MYRA): string
    {
        return $this->transport->fetchText($this->path($domain, 'bind-raw', $target));
    }

    private function path(string $domain, string $kind, string $target): string
    {
        if ($target !== self::TARGET_MYRA && $target !== self::TARGET_ORIGIN) {
            throw new InvalidArgumentException(sprintf('The ipTarget is "Myra" or "Origin", "%s" given.', $target));
        }

        return 'domains/' . $this->domainIds->resolve($domain) . '/' . $kind . '/' . $target;
    }
}
