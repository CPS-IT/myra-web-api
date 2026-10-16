<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\V2;

use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Model\Country;
use Myracloud\WebApi\Model\WafAction;
use Myracloud\WebApi\Model\WafCondition;
use Myracloud\WebApi\Tests\Support\FakeHttpClient;
use Myracloud\WebApi\Tests\Support\Fixture;
use Myracloud\WebApi\V2;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The WAF catalogue as captured (labels redacted by the anonymiser).
 */
#[CoversClass(V2\Waf::class)]
#[CoversClass(WafCondition::class)]
#[CoversClass(WafAction::class)]
#[CoversClass(Country::class)]
#[UsesClass(V2\Client::class)]
#[UsesClass(V2\Domains::class)]
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
#[UsesClass(V2\Subdomains::class)]
#[UsesClass(V2\DomainVerifications::class)]
#[UsesClass(V2\DomainIdResolver::class)]
#[UsesClass(\Myracloud\WebApi\Auth\BearerToken::class)]
#[UsesClass(\Myracloud\WebApi\Http\Transport::class)]
#[UsesClass(\Myracloud\WebApi\Http\Envelope::class)]
#[UsesClass(\Myracloud\WebApi\Http\JsonObject::class)]
#[UsesClass(\Myracloud\WebApi\Model\Page::class)]
final class WafTest extends TestCase
{
    private FakeHttpClient $http;
    private V2\Waf $waf;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->waf = (new V2\Client(new BearerToken('t'), httpClient: $this->http))->waf();
    }

    public function testListsTheConditions(): void
    {
        $this->http->queue(Fixture::response('v2/waf/conditions'));

        $conditions = $this->waf->conditions();

        self::assertSame(['GET https://apiv2.myracloud.com/waf/conditions'], $this->sent());
        self::assertCount(23, $conditions);
        $host = $conditions[1];
        self::assertSame(['host', 'redacted-16', 'redacted-18', 'Host', 1], [$host->name(), $host->category(), $host->alias(), $host->key(), $host->availablePhases()]);
        self::assertNull($conditions[0]->key());
        self::assertTrue($conditions[0]->raw()['forceCustomValues']);
    }

    public function testListsTheActions(): void
    {
        $this->http->queue(Fixture::response('v2/waf/actions'));

        $actions = $this->waf->actions();

        self::assertSame(['GET https://apiv2.myracloud.com/waf/actions'], $this->sent());
        self::assertSame(['Block', 'block', 1], [$actions[1]->name(), $actions[1]->type(), $actions[1]->availablePhases()]);
        self::assertSame(0, $actions[1]->raw()['forceCustomValues'], 'an int here, a bool in conditions');
    }

    public function testListsAllCountriesInOneRequest(): void
    {
        $this->http->queue(Fixture::response('v2/waf/countries'));

        $countries = $this->waf->countries();

        self::assertSame(['GET https://apiv2.myracloud.com/waf/countries'], $this->sent(), 'count 250, pageSize 50, but all 250 in the answer');
        self::assertCount(250, $countries);
        self::assertSame(['AD', 'redacted-85', 'redacted-86', 'redacted-87'], [$countries[0]->code(), $countries[0]->name(), $countries[0]->continentCode(), $countries[0]->continent()]);
        self::assertSame('CountryVO', $countries[0]->raw()['objectType']);
    }

    public function testReadsTheMapsAsSent(): void
    {
        $this->http->queue(Fixture::response('v2/waf/matching-types'), Fixture::response('v2/waf/action-values'));

        $types = $this->waf->matchingTypes();
        $values = $this->waf->actionValues();

        self::assertSame(['GET https://apiv2.myracloud.com/waf/conditions/matching-types', 'GET https://apiv2.myracloud.com/waf/actions/values'], $this->sent());
        self::assertSame(self::data('v2/waf/matching-types'), $types);
        self::assertSame(self::data('v2/waf/action-values'), $values);
        self::assertArrayHasKey('REGEX', is_array($types['default'] ?? null) ? $types['default'] : []);
    }

    /**
     * @return array<mixed>
     */
    private static function data(string $fixture): array
    {
        $body = Fixture::load($fixture)['response']['body'];
        self::assertIsArray($body);
        self::assertIsArray($body['data']);

        return $body['data'];
    }

    /**
     * @return list<string>
     */
    private function sent(): array
    {
        return array_map(static fn($r): string => $r->getMethod() . ' ' . $r->getUri(), $this->http->requests());
    }
}
