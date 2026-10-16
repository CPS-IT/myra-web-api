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
     * @param list<string> $areas path names of the probed areas, e.g. ['redirects']
     * @param list<string> $actions allowed last path segments besides an id, e.g. ['import']
     */
    public function __construct(
        private string $vhost,
        private int $domainId,
        private array $areas,
        private array $actions = [],
    ) {}

    public function allows(RequestInterface $request): bool
    {
        $vhost = preg_quote(rawurlencode($this->vhost), '#');
        $path = $request->getUri()->getPath();
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
