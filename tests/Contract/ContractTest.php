<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\Contract;

use Closure;
use Myracloud\WebApi\Auth\Authentication;
use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Auth\HmacSignature;
use Myracloud\WebApi\Contract\Client;
use Myracloud\WebApi\Exception\AccessDeniedException;
use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Exception\RequestRejectedException;
use Myracloud\WebApi\Model\CacheClear;
use Myracloud\WebApi\Model\DnsRecord;
use Myracloud\WebApi\Model\Domain;
use Myracloud\WebApi\Tests\Support\FakeHttpClient;
use Myracloud\WebApi\Tests\Support\FixedClock;
use Myracloud\WebApi\Tests\Support\Fixture;
use Myracloud\WebApi\V1;
use Myracloud\WebApi\V2;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The same expectations for V1\Client and V2\Client, each with HMAC and with a token:
 * proof that what Contract\ promises behaves the same in both versions. The answers are
 * the captures of one account through both versions.
 */
#[CoversClass(V1\Client::class)]
#[CoversClass(V1\Domains::class)]
#[CoversClass(V1\DnsRecords::class)]
#[CoversClass(V1\CacheClearing::class)]
#[CoversClass(V1\Redirects::class)]
#[CoversClass(V1\IpFilters::class)]
#[CoversClass(V1\CacheSettings::class)]
#[CoversClass(V1\SubdomainSettings::class)]
#[CoversClass(V1\Maintenances::class)]
#[CoversClass(V1\ErrorPages::class)]
#[CoversClass(V2\Client::class)]
#[CoversClass(V2\Domains::class)]
#[CoversClass(V2\DnsRecords::class)]
#[CoversClass(V2\CacheClearing::class)]
#[CoversClass(V2\Redirects::class)]
#[CoversClass(V2\IpFilters::class)]
#[CoversClass(V2\CacheSettings::class)]
#[CoversClass(V2\SubdomainSettings::class)]
#[CoversClass(V2\Maintenances::class)]
#[CoversClass(V2\ErrorPages::class)]
#[CoversClass(V2\MaintenanceTemplates::class)]
#[CoversClass(V2\DomainIdResolver::class)]
#[UsesClass(\Myracloud\WebApi\Auth\BearerToken::class)]
#[UsesClass(\Myracloud\WebApi\Auth\HmacSignature::class)]
#[UsesClass(\Myracloud\WebApi\Http\Transport::class)]
#[UsesClass(\Myracloud\WebApi\Http\Envelope::class)]
#[UsesClass(\Myracloud\WebApi\Http\JsonObject::class)]
#[UsesClass(\Myracloud\WebApi\Model\Page::class)]
#[UsesClass(\Myracloud\WebApi\Model\Domain::class)]
#[UsesClass(\Myracloud\WebApi\Model\DnsRecord::class)]
#[UsesClass(\Myracloud\WebApi\Model\UpstreamOptions::class)]
#[UsesClass(\Myracloud\WebApi\Model\CacheClear::class)]
#[UsesClass(\Myracloud\WebApi\Model\CacheClearBody::class)]
#[UsesClass(\Myracloud\WebApi\Model\DomainName::class)]
#[UsesClass(\Myracloud\WebApi\Model\Violation::class)]
#[UsesClass(\Myracloud\WebApi\Model\Redirect::class)]
#[UsesClass(\Myracloud\WebApi\Model\IpFilter::class)]
#[UsesClass(\Myracloud\WebApi\Model\CacheSetting::class)]
#[UsesClass(\Myracloud\WebApi\Model\Settings::class)]
#[UsesClass(\Myracloud\WebApi\Model\ErrorPage::class)]
#[UsesClass(\Myracloud\WebApi\Exception\RequestRejectedException::class)]
final class ContractTest extends TestCase
{
    /**
     * @return iterable<string, array{'v1'|'v2', Closure(): Authentication, non-empty-string}>
     */
    public static function clients(): iterable
    {
        $hmac = static fn(): Authentication => new HmacSignature('test-key', 'test-secret', new FixedClock('2026-10-16T12:00:00+02:00'));
        $token = static fn(): Authentication => new BearerToken('test-token');
        yield 'v1, HMAC' => ['v1', $hmac, 'MYRA test-key:'];
        yield 'v1, token' => ['v1', $token, 'Bearer test-token'];
        yield 'v2, HMAC' => ['v2', $hmac, 'MYRA test-key:'];
        yield 'v2, token' => ['v2', $token, 'Bearer test-token'];
    }

    /**
     * @param 'v1'|'v2' $version
     * @param Closure(): Authentication $auth
     * @param non-empty-string $authorization
     */
    #[DataProvider('clients')]
    public function testListsTheDnsRecordsOfADomain(string $version, Closure $auth, string $authorization): void
    {
        $http = self::answers($version, 'dns-records/list');

        $records = self::client($version, $auth(), $http)->dnsRecords()->list('example.com');

        self::assertSame(
            [
                [1021, 'example.com', 'A', '192.0.2.10', 300, true, true],
                [1042, 'api.example.com', 'A', '192.0.2.10', 300, true, true],
                [1063, 'www.example.com', 'A', '192.0.2.10', 300, true, true],
                [1084, 'staging.example.com', 'A', '192.0.2.12', 300, true, true],
                [1105, 'api.staging.example.com', 'A', '192.0.2.12', 300, true, true],
                [1126, 'map.example.com', 'A', '192.0.2.10', 300, true, false],
            ],
            array_map(static fn(DnsRecord $r): array => [$r->id(), $r->name(), $r->recordType(), $r->value(), $r->ttl(), $r->isActive(), $r->isEnabled()], $records),
        );
        self::assertAuthenticated($http, $authorization);
    }

    /**
     * v1 and v2 answer the same DomainVO objects with the same ids (captures 7 and 17).
     *
     * @param 'v1'|'v2' $version
     * @param Closure(): Authentication $auth
     * @param non-empty-string $authorization
     */
    #[DataProvider('clients')]
    public function testListsTheDomains(string $version, Closure $auth, string $authorization): void
    {
        $http = (new FakeHttpClient())->queue(Fixture::response($version . '/domains/list'));

        $domains = self::client($version, $auth(), $http)->domains()->list();

        self::assertSame([[1000, 'example.com'], [1140, 'example.org']], array_map(static fn(Domain $d): array => [$d->id(), $d->name()], $domains));
        self::assertAuthenticated($http, $authorization);
    }

    /**
     * @param 'v1'|'v2' $version
     * @param Closure(): Authentication $auth
     * @param non-empty-string $authorization
     */
    #[DataProvider('clients')]
    public function testClearsTheCacheOfAResource(string $version, Closure $auth, string $authorization): void
    {
        $http = self::answers($version, 'cache-clear/single');

        $clears = self::client($version, $auth(), $http)->cacheClear()->clear('example.com', 'example.com', '/__probe__/nothing.txt');

        self::assertSame([['example.com', '/__probe__/nothing.txt', false, []]], self::describe($clears));
        self::assertSame(Fixture::requestBody($version . '/cache-clear/single'), (string) $http->lastRequest()->getBody());
        self::assertAuthenticated($http, $authorization);
    }

    /**
     * @param 'v1'|'v2' $version
     * @param Closure(): Authentication $auth
     * @param non-empty-string $authorization
     */
    #[DataProvider('clients')]
    public function testClearsTheCacheOfSeveralResources(string $version, Closure $auth, string $authorization): void
    {
        $http = self::answers($version, 'cache-clear/bulk');

        $clears = self::client($version, $auth(), $http)->cacheClear()->clearBulk('example.com', 'example.com', ['/__probe__/a.txt', '/__probe__/b.txt']);

        self::assertSame([['example.com', '/__probe__/b.txt', false, ['/__probe__/a.txt', '/__probe__/b.txt']]], self::describe($clears));
        self::assertSame(Fixture::requestBody($version . '/cache-clear/bulk'), (string) $http->lastRequest()->getBody());
        self::assertAuthenticated($http, $authorization);
    }

    /**
     * @param 'v1'|'v2' $version
     * @param Closure(): Authentication $auth
     */
    #[DataProvider('clients')]
    public function testListsTheRedirectsOfAVhost(string $version, Closure $auth): void
    {
        $http = self::answers($version, 'redirects/list-apex');

        $redirects = self::client($version, $auth(), $http)->redirects()->list('example.com');

        self::assertSame(
            [[90392, 'example.com', '/path-5', 'https://example.com/path-6', 'redirect', 'exact', 1, true, '2018-11-01T14:41:24+0100']],
            array_map(static fn(\Myracloud\WebApi\Model\Redirect $r): array => [$r->id(), $r->subdomain(), $r->source(), $r->destination(), $r->type(), $r->matchingType(), $r->sort(), $r->isEnabled(), $r->modified()], $redirects),
        );
    }

    /**
     * @param 'v1'|'v2' $version
     * @param Closure(): Authentication $auth
     */
    #[DataProvider('clients')]
    public function testListsTheIpFiltersOfAVhost(string $version, Closure $auth): void
    {
        $http = self::answers($version, 'ipfilter/list-apex');

        $filters = self::client($version, $auth(), $http)->ipFilters()->list('example.com');

        self::assertCount(7, $filters);
        self::assertSame(
            [90393, 'example.com', 'WHITELIST', '192.0.2.123/32', true, '2018-07-11T13:57:24+0200'],
            [$filters[0]->id(), $filters[0]->subdomain(), $filters[0]->type(), $filters[0]->value(), $filters[0]->isEnabled(), $filters[0]->modified()],
        );
    }

    /**
     * @param 'v1'|'v2' $version
     * @param Closure(): Authentication $auth
     */
    #[DataProvider('clients')]
    public function testListsTheCacheSettingsOfAVhost(string $version, Closure $auth): void
    {
        $http = self::answers($version, 'cachesettings/list-apex');

        $settings = self::client($version, $auth(), $http)->cacheSettings()->list('example.com');

        self::assertCount(19, $settings);
        self::assertSame(
            [90373, 'example.com', '/path-1', 0, 'prefix', 5, false, 10, true],
            [$settings[0]->id(), $settings[0]->subdomain(), $settings[0]->path(), $settings[0]->ttl(), $settings[0]->type(), $settings[0]->notFoundTtl(), $settings[0]->isEnforced(), $settings[0]->sort(), $settings[0]->isEnabled()],
        );
    }

    /**
     * The same settings in both versions — names and booleans; numbers differ in type (v1
     * sends them as strings).
     *
     * @param 'v1'|'v2' $version
     * @param Closure(): Authentication $auth
     */
    #[DataProvider('clients')]
    public function testReadsTheSubdomainSettings(string $version, Closure $auth): void
    {
        $http = self::answers($version, 'settings/get-apex');

        $settings = self::client($version, $auth(), $http)->subdomainSettings()->get('example.com');

        self::assertCount(94, $settings->inherited());
        self::assertSame([93, 0, 12, 0, 19], array_map('count', $settings->layers()));
        self::assertSame(array_filter($settings->inherited(), 'is_bool'), array_filter(self::client('v1', $auth(), self::answers('v1', 'settings/get-apex'))->subdomainSettings()->get('example.com')->inherited(), 'is_bool'));
    }

    /**
     * v1 groups by subdomain, v2 lists flat (and unpaged) — the same four pages.
     *
     * @param 'v1'|'v2' $version
     * @param Closure(): Authentication $auth
     */
    #[DataProvider('clients')]
    public function testListsTheErrorPagesOfADomain(string $version, Closure $auth): void
    {
        $http = self::answers($version, 'errorpages/list');

        $pages = self::client($version, $auth(), $http)->errorPages()->list('example.com');

        self::assertSame(
            [['example.com', 502, 90400], ['example.com', 504, 90401], ['www.example.com', 502, 90402], ['www.example.com', 504, 90403]],
            array_map(static fn(\Myracloud\WebApi\Model\ErrorPage $p): array => [$p->subdomain(), $p->errorCode(), $p->id()], $pages),
        );
    }

    /**
     * @param 'v1'|'v2' $version
     * @param Closure(): Authentication $auth
     */
    #[DataProvider('clients')]
    public function testAHostOutsideTheDomainIsRejected(string $version, Closure $auth): void
    {
        $http = self::answers($version, 'errors/fqdn-not-in-the-domain');

        try {
            self::client($version, $auth(), $http)->cacheClear()->clear('example.com', 'no-such-host.invalid', '/__probe__/nothing.txt');
            self::fail('No exception thrown.');
        } catch (RequestRejectedException $exception) {
            self::assertSame('DOMAIN_DOES_NOT_EXIST', $exception->violations()[0]->code());
            self::assertSame('fqdn', $exception->violations()[0]->propertyPath());
        }
    }

    /**
     * @param 'v1'|'v2' $version
     * @param Closure(): Authentication $auth
     */
    #[DataProvider('clients')]
    public function testWrongCredentialsAreAccessDenied(string $version, Closure $auth): void
    {
        $http = (new FakeHttpClient())->queue(Fixture::response($version . '/errors/wrong-credentials'));

        $this->expectException(AccessDeniedException::class);

        self::client($version, $auth(), $http)->dnsRecords()->list('example.com');
    }

    /**
     * v1 cannot tell an unknown domain from wrong credentials (AccessDeniedException), v2
     * can (DomainNotFoundException) — both are a MyraException.
     *
     * @param 'v1'|'v2' $version
     * @param Closure(): Authentication $auth
     */
    #[DataProvider('clients')]
    public function testAnUnknownDomainFails(string $version, Closure $auth): void
    {
        $http = (new FakeHttpClient())->queue(Fixture::response($version === 'v1' ? 'v1/errors/unknown-domain' : 'v2/domains/list'));

        $this->expectException(MyraException::class);

        self::client($version, $auth(), $http)->dnsRecords()->list('does-not-exist.invalid');
    }

    /**
     * @param 'v1'|'v2' $version
     */
    private static function client(string $version, Authentication $auth, FakeHttpClient $http): Client
    {
        return $version === 'v1' ? new V1\Client($auth, httpClient: $http) : new V2\Client($auth, httpClient: $http);
    }

    /**
     * The captured answer of a domain operation; v2 first looks the domain id up.
     *
     * @param 'v1'|'v2' $version
     */
    private static function answers(string $version, string $fixture): FakeHttpClient
    {
        $http = new FakeHttpClient();
        if ($version === 'v2') {
            $http->queue(Fixture::response('v2/domains/list'));
        }

        return $http->queue(Fixture::response($version . '/' . $fixture));
    }

    /**
     * @param non-empty-string $authorization
     */
    private static function assertAuthenticated(FakeHttpClient $http, string $authorization): void
    {
        foreach ($http->requests() as $request) {
            self::assertStringStartsWith($authorization, $request->getHeaderLine('Authorization'));
        }
    }

    /**
     * @param list<CacheClear> $clears
     *
     * @return list<array{string, string|null, bool, list<string>}>
     */
    private static function describe(array $clears): array
    {
        return array_map(static fn(CacheClear $c): array => [$c->fqdn(), $c->resource(), $c->isRecursive(), $c->bulk()], $clears);
    }
}
