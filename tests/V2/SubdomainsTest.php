<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\V2;

use GuzzleHttp\Psr7\Response;
use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Exception\RequestRejectedException;
use Myracloud\WebApi\Model\DomainVerification;
use Myracloud\WebApi\Model\Subdomain;
use Myracloud\WebApi\Tests\Support\FakeHttpClient;
use Myracloud\WebApi\Tests\Support\Fixture;
use Myracloud\WebApi\V2;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Subdomains, a domain by id and its verification status as captured; the verification
 * check from the spec (write unverified).
 */
#[CoversClass(V2\Subdomains::class)]
#[CoversClass(V2\DomainVerifications::class)]
#[UsesClass(V2\Waf::class)]
#[CoversClass(V2\Domains::class)]
#[CoversClass(Subdomain::class)]
#[CoversClass(DomainVerification::class)]
#[UsesClass(V2\Client::class)]
#[UsesClass(V2\DnsRecords::class)]
#[UsesClass(V2\CacheClearing::class)]
#[UsesClass(V2\Redirects::class)]
#[UsesClass(V2\IpFilters::class)]
#[UsesClass(V2\CacheSettings::class)]
#[UsesClass(V2\SubdomainSettings::class)]
#[UsesClass(V2\Maintenances::class)]
#[UsesClass(V2\ErrorPages::class)]
#[UsesClass(V2\IpRanges::class)]
#[UsesClass(V2\Statistics::class)]
#[UsesClass(V2\SslCertificates::class)]
#[UsesClass(V2\MaintenanceTemplates::class)]
#[UsesClass(V2\DomainIdResolver::class)]
#[UsesClass(\Myracloud\WebApi\Auth\BearerToken::class)]
#[UsesClass(\Myracloud\WebApi\Http\Transport::class)]
#[UsesClass(\Myracloud\WebApi\Http\Envelope::class)]
#[UsesClass(\Myracloud\WebApi\Http\JsonObject::class)]
#[UsesClass(\Myracloud\WebApi\Model\Page::class)]
#[UsesClass(\Myracloud\WebApi\Model\Domain::class)]
#[UsesClass(\Myracloud\WebApi\Model\DomainName::class)]
#[UsesClass(\Myracloud\WebApi\Model\Violation::class)]
#[UsesClass(RequestRejectedException::class)]
final class SubdomainsTest extends TestCase
{
    private const BASE = 'https://apiv2.myracloud.com/';

    private FakeHttpClient $http;
    private V2\Client $client;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->client = new V2\Client(new BearerToken('t'), httpClient: $this->http);
    }

    public function testListsTheSubdomainsOfTheAccountInOneRequest(): void
    {
        $this->http->queue(Fixture::response('v2/subdomains/list'));

        $subdomains = $this->client->subdomains()->list();

        self::assertSame(['GET ' . self::BASE . 'subdomains'], $this->sent());
        self::assertSame(['example.com', 'api.example.com', 'www.example.com', 'staging.example.com', 'api.staging.example.com', 'map.example.com', 'map.example.org'], self::names($subdomains));
        $last = $subdomains[6];
        self::assertSame([90266, 'map.example.org', 'example.org', 1140, true, false], [$last->id(), $last->name(), $last->domain(), $last->domainId(), $last->active(), $last->paused()]);
        self::assertSame(['edit' => true], $last->raw()['permissions']);
    }

    /**
     * The account list ignores paging: page 2 answers all entries again — collecting stops
     * because everything has arrived, not by asking for page 2.
     */
    public function testTheAccountListIsNotPagedFurther(): void
    {
        $this->http->queue(Fixture::response('v2/subdomains/page-ignored'));

        self::assertCount(7, $this->client->subdomains()->list());
        self::assertCount(1, $this->http->requests());
    }

    public function testListsTheSubdomainsOfADomainOverAllPages(): void
    {
        $this->http->queue(Fixture::response('v2/domains/list'), self::page([1, 2], 1), self::page([3], 2));

        $subdomains = $this->client->subdomains()->list('example.com');

        self::assertSame(['GET ' . self::BASE . 'domains', 'GET ' . self::BASE . 'domain/1000/subdomains', 'GET ' . self::BASE . 'domain/1000/subdomains?page=2'], $this->sent());
        self::assertSame(['h1.example.com', 'h2.example.com', 'h3.example.com'], self::names($subdomains));
    }

    public function testReadsAPageOfADomain(): void
    {
        $this->http->queue(Fixture::response('v2/domains/list'), Fixture::response('v2/subdomains/list-domain-page-2'));

        $page = $this->client->subdomains()->page('example.com', 2);

        self::assertSame('GET ' . self::BASE . 'domain/1000/subdomains?page=2', $this->sent()[1]);
        self::assertSame(['www.example.com', 'staging.example.com'], self::names($page->items()));
    }

    public function testSearches(): void
    {
        $this->http->queue(Fixture::response('v2/subdomains/search'), Fixture::response('v2/subdomains/search-exact'));

        self::assertSame(['www.example.com'], self::names($this->client->subdomains()->search('www')));
        self::assertSame(['example.com'], self::names($this->client->subdomains()->search('example.com', true)));
        self::assertSame(['GET ' . self::BASE . 'subdomains?search=www', 'GET ' . self::BASE . 'subdomains?search=example.com&filterType=exact'], $this->sent());
    }

    public function testGetsADomainById(): void
    {
        $this->http->queue(Fixture::response('v2/domains/get'));

        $domain = $this->client->domains()->get(1000);

        self::assertSame(['GET ' . self::BASE . 'domains/1000'], $this->sent());
        self::assertSame([1000, 'example.com', true], [$domain->id(), $domain->name(), $domain->raw()['verified']]);
    }

    public function testReadsTheVerificationStatus(): void
    {
        $this->http->queue(Fixture::response('v2/domains/list'), Fixture::response('v2/domains/verification'));

        $status = $this->client->domainVerifications()->status('example.com');

        self::assertSame('GET ' . self::BASE . 'domains/1000/verification', $this->sent()[1]);
        self::assertSame([true, null, [], null, []], [$status->verified(), $status->blocker(), $status->recordNames(), $status->recordValue(), $status->conflictingNames()]);
        self::assertFalse($status->raw()['foreignDescendantsExist']);
    }

    public function testReadsAPendingVerificationAsTheSpecDescribesIt(): void
    {
        $this->http->queue(Fixture::response('v2/domains/list'), new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['error' => false, 'data' => [[
            'verified' => false, 'blocker' => DomainVerification::BLOCKER_RECORD_CONFLICT, 'recordNames' => ['_myra-verify.example.com'],
            'recordValue' => 'consent-token', 'conflictingNames' => ['www.example.com'],
        ]]])));

        $status = $this->client->domainVerifications()->status('example.com');

        self::assertSame([false, 'record_conflict', ['_myra-verify.example.com'], 'consent-token', ['www.example.com']], [$status->verified(), $status->blocker(), $status->recordNames(), $status->recordValue(), $status->conflictingNames()]);
    }

    public function testRequestsAVerificationCheck(): void
    {
        $this->http->queue(Fixture::response('v2/domains/list'), new Response(202));

        $this->client->domainVerifications()->check('example.com');

        self::assertSame('POST ' . self::BASE . 'domains/1000/verification/check', $this->sent()[1]);
        self::assertSame('', (string) $this->http->lastRequest()->getBody(), 'no body (spec)');
    }

    public function testACheckOfAVerifiedDomainIsRejected(): void
    {
        $this->http->queue(Fixture::response('v2/domains/list'), new Response(409, ['Content-Type' => 'application/json'], '{"error":true,"violationList":[{"propertyPath":"","message":"The domain is verified.","code":"DOMAIN_VERIFICATION_ALREADY_VERIFIED"}]}'));

        try {
            $this->client->domainVerifications()->check('example.com');
            self::fail('No exception thrown.');
        } catch (RequestRejectedException $exception) {
            self::assertSame('DOMAIN_VERIFICATION_ALREADY_VERIFIED', $exception->violations()[0]->code());
        }
    }

    /**
     * A synthetic page of 2 of 3 subdomains.
     *
     * @param list<int> $ids
     */
    private static function page(array $ids, int $number): Response
    {
        $items = [];
        foreach ($ids as $id) {
            $items[] = ['id' => $id, 'label' => "h$id.example.com", 'domainName' => 'example.com', 'domainId' => 1000, 'isActive' => true, 'paused' => false];
        }

        return Fixture::page('data', $items, $number, 2, 3);
    }

    /**
     * @param list<Subdomain> $subdomains
     *
     * @return list<string>
     */
    private static function names(array $subdomains): array
    {
        return array_map(static fn(Subdomain $s): string => $s->name(), $subdomains);
    }

    /**
     * @return list<string>
     */
    private function sent(): array
    {
        return array_map(static fn($r): string => $r->getMethod() . ' ' . $r->getUri(), $this->http->requests());
    }
}
