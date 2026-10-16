<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\V2;

use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Tests\Support\FakeHttpClient;
use Myracloud\WebApi\Tests\Support\Fixture;
use Myracloud\WebApi\V2;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(V2\Zones::class)]
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
#[UsesClass(V2\Waf::class)]
#[UsesClass(V2\Users::class)]
#[UsesClass(V2\Organization::class)]
#[UsesClass(V2\DomainIdResolver::class)]
#[UsesClass(\Myracloud\WebApi\Auth\BearerToken::class)]
#[UsesClass(\Myracloud\WebApi\Http\Transport::class)]
#[UsesClass(\Myracloud\WebApi\Http\Envelope::class)]
#[UsesClass(\Myracloud\WebApi\Http\JsonObject::class)]
#[UsesClass(\Myracloud\WebApi\Model\Page::class)]
#[UsesClass(\Myracloud\WebApi\Model\Domain::class)]
#[UsesClass(\Myracloud\WebApi\Model\DomainName::class)]
#[UsesClass(\Myracloud\WebApi\Model\Zone::class)]
#[UsesClass(\Myracloud\WebApi\Model\ZoneRecord::class)]
final class ZonesTest extends TestCase
{
    public function testExportsTheOriginsZone(): void
    {
        $http = (new FakeHttpClient())->queue(Fixture::response('v2/domains/list'), Fixture::response('v2/bind/zone-origin'), Fixture::response('v2/bind/file-origin'));
        $zones = (new V2\Client(new BearerToken('t'), httpClient: $http))->zones();

        $zones->get('example.org', V2\Zones::TARGET_ORIGIN);
        $zones->file('example.org', V2\Zones::TARGET_ORIGIN);

        self::assertSame(
            ['GET https://apiv2.myracloud.com/domains', 'GET https://apiv2.myracloud.com/domains/1140/bind/Origin', 'GET https://apiv2.myracloud.com/domains/1140/bind-raw/Origin'],
            array_map(static fn($r): string => $r->getMethod() . ' ' . $r->getUri(), $http->requests()),
        );
    }

    public function testKnowsOnlyTheTwoTargets(): void
    {
        $http = new FakeHttpClient();
        $zones = (new V2\Client(new BearerToken('t'), httpClient: $http))->zones();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The ipTarget is "Myra" or "Origin", "myra" given.');

        try {
            $zones->file('example.org', 'myra');
        } finally {
            self::assertSame([], $http->requests());
        }
    }
}
