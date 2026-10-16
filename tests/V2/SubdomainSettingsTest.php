<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\V2;

use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Exception\RequestRejectedException;
use Myracloud\WebApi\Tests\Support\FakeHttpClient;
use Myracloud\WebApi\Tests\Support\Fixture;
use Myracloud\WebApi\V2;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(V2\SubdomainSettings::class)]
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
#[UsesClass(V2\Client::class)]
#[UsesClass(V2\Domains::class)]
#[UsesClass(V2\DnsRecords::class)]
#[UsesClass(V2\CacheClearing::class)]
#[UsesClass(V2\Redirects::class)]
#[UsesClass(V2\IpFilters::class)]
#[UsesClass(V2\CacheSettings::class)]
#[UsesClass(V2\DomainIdResolver::class)]
#[UsesClass(\Myracloud\WebApi\Auth\BearerToken::class)]
#[UsesClass(\Myracloud\WebApi\Http\Transport::class)]
#[UsesClass(\Myracloud\WebApi\Http\Envelope::class)]
#[UsesClass(\Myracloud\WebApi\Http\JsonObject::class)]
#[UsesClass(\Myracloud\WebApi\Model\Settings::class)]
#[UsesClass(\Myracloud\WebApi\Model\DomainName::class)]
#[UsesClass(\Myracloud\WebApi\Model\Domain::class)]
#[UsesClass(\Myracloud\WebApi\Model\Page::class)]
#[UsesClass(\Myracloud\WebApi\Model\Violation::class)]
#[UsesClass(\Myracloud\WebApi\Exception\RequestRejectedException::class)]
#[UsesClass(\Myracloud\WebApi\Exception\TransportException::class)]
final class SubdomainSettingsTest extends TestCase
{
    private FakeHttpClient $http;
    private V2\SubdomainSettings $settings;

    protected function setUp(): void
    {
        $this->http = (new FakeHttpClient())->queue(Fixture::response('v2/domains/list'));
        $this->settings = (new V2\Client(new BearerToken('t'), httpClient: $this->http))->subdomainSettings();
    }

    public function testReadsTheSettingsOfAVhost(): void
    {
        $this->http->queue(Fixture::response('v2/settings/get-apex'));

        $settings = $this->settings->get('Example.COM.');

        self::assertSame('GET https://apiv2.myracloud.com/domain/1000/example.com/settings', $this->sent()[1]);
        self::assertSame('example.com', $settings->subdomain());
        self::assertCount(94, $settings->inherited());
    }

    public function testReadsTheEffectiveSettings(): void
    {
        $this->http->queue(Fixture::response('v2/settings/flat'));

        $effective = $this->settings->effective('map.example.org');

        self::assertSame('GET https://apiv2.myracloud.com/domain/1140/map.example.org/settings?flat', $this->sent()[1]);
        self::assertCount(94, $effective);
        self::assertTrue($effective['access_log']);
    }

    /**
     * Captured: the unchanged (empty) own settings posted back as {}, then read again.
     */
    public function testUpdatesAndReadsAgain(): void
    {
        $this->http->queue(Fixture::response('v2/settings/update'), Fixture::response('v2/settings/get-empty'));

        $settings = $this->settings->update('map.example.org', []);

        self::assertSame(['POST https://apiv2.myracloud.com/domain/1140/map.example.org/settings', 'GET https://apiv2.myracloud.com/domain/1140/map.example.org/settings'], array_slice($this->sent(), 1));
        self::assertSame('{}', (string) $this->http->requests()[1]->getBody());
        self::assertSame('application/json', $this->http->requests()[1]->getHeaderLine('Content-Type'));
        self::assertSame([], $settings->own());
    }

    public function testUpdatesWithValues(): void
    {
        $this->http->queue(Fixture::response('v2/settings/update'), Fixture::response('v2/settings/get-empty'));

        $this->settings->update('map.example.org', ['hsts' => true, 'client_max_body_size' => '20']);

        self::assertSame('{"hsts":true,"client_max_body_size":"20"}', (string) $this->http->requests()[1]->getBody());
    }

    public function testAVhostThatDoesNotExistIsRejected(): void
    {
        $this->http->queue(Fixture::response('v2/settings/unknown-vhost'));

        $this->expectException(RequestRejectedException::class);
        $this->expectExceptionMessage('The entity does not exist.');

        $this->settings->get('example.org');
    }

    /**
     * @return list<string>
     */
    private function sent(): array
    {
        return array_map(static fn($r): string => $r->getMethod() . ' ' . $r->getUri(), $this->http->requests());
    }
}
