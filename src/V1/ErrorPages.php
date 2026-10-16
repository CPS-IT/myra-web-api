<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V1;

use Myracloud\WebApi\Contract;
use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\JsonObject;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\DomainName;
use Myracloud\WebApi\Model\ErrorPage;
use Myracloud\WebApi\Model\ErrorPageSelection;
use Myracloud\WebApi\Model\Page;

/**
 * `/{lang}/rapi/errorpages/{domain}`: GET `/{page}` lists (grouped by subdomain), POST sets,
 * DELETE deletes (body: selection); `/{lang}/rapi/errorpage/{id}` reads one.
 */
final readonly class ErrorPages implements Contract\ErrorPages
{
    /**
     * @internal built by {@see Client}
     */
    public function __construct(private Transport $transport) {}

    public function list(string $domain): array
    {
        $pages = [];
        foreach (Page::collect(fn(int $page): Page => Page::fromJson($this->transport->send('GET', self::path($domain) . '/' . Page::number($page)), 'list', static fn(JsonObject $group): JsonObject => $group)) as $group) {
            array_push($pages, ...self::ungroup($group));
        }

        return $pages;
    }

    public function get(string $domain, int $id): ErrorPage
    {
        // captured: answered like a list — one group in `list`, with the content
        $groups = array_map(self::ungroup(...), $this->transport->send('GET', 'errorpage/' . $id)->objects('list'));

        return array_merge(...$groups)[0] ?? throw new TransportException('Unexpected response: list holds no error page.');
    }

    public function set(string $domain, string $html, array $codesBySubdomain): void
    {
        $this->transport->send('POST', self::path($domain), body: ['pageContent' => $html, 'selection' => ErrorPageSelection::toJson($codesBySubdomain)]);
    }

    public function delete(string $domain, array $codesBySubdomain): void
    {
        $this->transport->send('DELETE', self::path($domain), body: ['selection' => ErrorPageSelection::toJson($codesBySubdomain)]);
    }

    /**
     * {"subDomainName": "www…", "error": {"502": ErrorPageVO, …}} → pages.
     *
     * @return list<ErrorPage>
     */
    private static function ungroup(JsonObject $group): array
    {
        $subdomain = $group->string('subDomainName');
        $errors = $group->object('error');

        return array_map(
            static fn(int|string $code): ErrorPage => ErrorPage::fromJson($errors->object((string) $code), $subdomain),
            array_keys($errors->toArray()),
        );
    }

    private static function path(string $domain): string
    {
        return 'errorpages/' . rawurlencode(DomainName::normalize($domain));
    }
}
