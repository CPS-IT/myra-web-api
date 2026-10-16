<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V1;

use Myracloud\WebApi\Contract;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\DomainName;
use Myracloud\WebApi\Model\Page;
use Myracloud\WebApi\Model\Persisted;
use Myracloud\WebApi\Model\Redirect;

/**
 * `/{lang}/rapi/redirects/{subdomain}`: GET `/{page}` lists, PUT creates, POST updates,
 * DELETE deletes (body: id, modified). Writes unverified, see docs/api-surface.md.
 */
final readonly class Redirects implements Contract\Redirects
{
    /**
     * @internal built by {@see Client}
     */
    public function __construct(private Transport $transport) {}

    public function list(string $subdomain): array
    {
        return Page::collect(fn(int $page): Page => $this->page($subdomain, $page));
    }

    public function page(string $subdomain, int $page): Page
    {
        $body = $this->transport->send('GET', self::path($subdomain) . '/' . Page::number($page));

        return Page::fromJson($body, 'list', Redirect::fromJson(...));
    }

    public function create(Redirect $redirect): Redirect
    {
        Persisted::assertNew($redirect->id(), 'redirect');

        return Redirect::fromJson($this->transport->send('PUT', self::path($redirect->subdomain()), body: $redirect->toJson())->first('targetObject'));
    }

    public function update(Redirect $redirect): Redirect
    {
        Persisted::versionOf($redirect->id(), $redirect->modified(), 'redirect');

        return Redirect::fromJson($this->transport->send('POST', self::path($redirect->subdomain()), body: $redirect->toJson())->first('targetObject'));
    }

    public function delete(Redirect $redirect): void
    {
        [$id, $modified] = Persisted::versionOf($redirect->id(), $redirect->modified(), 'redirect');
        $this->transport->send('DELETE', self::path($redirect->subdomain()), body: ['id' => $id, 'modified' => $modified]);
    }

    private static function path(string $subdomain): string
    {
        return 'redirects/' . rawurlencode(DomainName::normalize($subdomain));
    }
}
