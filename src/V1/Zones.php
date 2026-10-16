<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V1;

use Myracloud\WebApi\Contract;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\DomainName;
use Myracloud\WebApi\Model\Zone;

/**
 * `GET /{lang}/rapi/bind/{domain}` (data), `GET /{lang}/rapi/bindRaw/{domain}` (zone file).
 */
final readonly class Zones implements Contract\Zones
{
    /**
     * @internal built by {@see Client}
     */
    public function __construct(private Transport $transport) {}

    public function get(string $domain): Zone
    {
        return Zone::fromJson($this->transport->send('GET', 'bind/' . rawurlencode(DomainName::normalize($domain)))->first('list'));
    }

    public function file(string $domain): string
    {
        return $this->transport->fetchText('bindRaw/' . rawurlencode(DomainName::normalize($domain)));
    }
}
