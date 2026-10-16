<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\Smoke;

use Psr\Http\Message\RequestInterface;

/**
 * Which writes a probe run may send: only to the probed area of one vhost of one domain.
 * {@see RecordingClient} asks before every non-GET request; anything else is never sent.
 */
final readonly class WriteGuard
{
    /**
     * @param list<string> $areas path names of the probed areas per vhost, e.g. ['redirects']
     * @param list<string> $actions allowed last path segments besides an id, e.g. ['import']
     * @param list<string> $domainAreas path names of probed areas per domain (zone), e.g. ['dnsRecords', 'dns-records']
     */
    public function __construct(
        private string $vhost,
        private int $domainId,
        private array $areas,
        private array $actions = [],
        private string $domain = '',
        private array $domainAreas = [],
    ) {}

    public function allows(RequestInterface $request): bool
    {
        $vhost = preg_quote(rawurlencode($this->vhost), '#');
        $path = $request->getUri()->getPath();
        if ($this->domain !== '') {
            $domain = preg_quote(rawurlencode($this->domain), '#');
            foreach ($this->domainAreas as $area) {
                $area = preg_quote($area, '#');
                // v1 /{lang}/rapi/{area}/{domain}; v2 /domain/{id}/{area}[/{id}[/load-balancing]]
                if (preg_match("#^/[a-z]{2}/rapi/$area/$domain$#", $path) === 1
                    || preg_match("#^/domain/{$this->domainId}/$area(/\\d+(/load-balancing)?)?$#", $path) === 1) {
                    return true;
                }
            }
        }
        foreach ($this->areas as $area) {
            $area = preg_quote($area, '#');
            $suffix = implode('|', ['\\d+', ...array_map(static fn(string $a): string => preg_quote($a, '#'), $this->actions)]);
            // v1 /{lang}/rapi/{area}/{vhost}; v2 /domain/{id}/{area}/{vhost} or /domain/{id}/{vhost}/{area}
            if (preg_match("#^/[a-z]{2}/rapi/$area/$vhost$#", $path) === 1
                || preg_match("#^/domain/{$this->domainId}/$area/$vhost(/($suffix))?$#", $path) === 1
                || preg_match("#^/domain/{$this->domainId}/$vhost/$area(/($suffix))?$#", $path) === 1) {
                return true;
            }
        }

        return false;
    }
}
