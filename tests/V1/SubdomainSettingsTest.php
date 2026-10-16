<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\V1;

use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Tests\Support\FakeHttpClient;
use Myracloud\WebApi\Tests\Support\Fixture;
use Myracloud\WebApi\V1;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(V1\SubdomainSettings::class)]
#[UsesClass(V1\Maintenances::class)]
#[UsesClass(V1\ErrorPages::class)]
#[UsesClass(V1\IpRanges::class)]
#[UsesClass(V1\Statistics::class)]
#[UsesClass(V1\Client::class)]
#[UsesClass(V1\Domains::class)]
#[UsesClass(V1\DnsRecords::class)]
#[UsesClass(V1\CacheClearing::class)]
#[UsesClass(V1\Redirects::class)]
#[UsesClass(V1\IpFilters::class)]
#[UsesClass(V1\CacheSettings::class)]
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
    private V1\SubdomainSettings $settings;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->settings = (new V1\Client(new BearerToken('t'), httpClient: $this->http))->subdomainSettings();
    }

    public function testReadsTheSettingsOfAVhost(): void
    {
        $this->http->queue(Fixture::response('v1/settings/get-apex'));

        $settings = $this->settings->get('Example.COM.');

        self::assertSame('GET https://api.myracloud.com/en/rapi/subdomainSetting/example.com', $this->sent()[0]);
        self::assertSame('example.com', $settings->subdomain());
        self::assertCount(94, $settings->inherited());
    }

    public function testReadsTheEffectiveSettings(): void
    {
        $this->http->queue(Fixture::response('v1/settings/flat'));

        $effective = $this->settings->effective('map.example.org');

        self::assertSame('GET https://api.myracloud.com/en/rapi/subdomainSetting/map.example.org?flat', $this->sent()[0]);
        self::assertCount(94, $effective);
        self::assertTrue($effective['access_log']);
    }

    /**
     * Captured: the unchanged (empty) own settings posted back as {}, then read again.
     */
    public function testUpdatesAndReadsAgain(): void
    {
        $this->http->queue(Fixture::response('v1/settings/update'), Fixture::response('v1/settings/get-empty'));

        $settings = $this->settings->update('map.example.org', []);

        self::assertSame(['POST https://api.myracloud.com/en/rapi/subdomainSetting/map.example.org', 'GET https://api.myracloud.com/en/rapi/subdomainSetting/map.example.org'], array_slice($this->sent(), 0));
        self::assertSame('{}', (string) $this->http->requests()[0]->getBody());
        self::assertSame('application/json', $this->http->requests()[0]->getHeaderLine('Content-Type'));
        self::assertSame([], $settings->own());
    }

    public function testUpdatesWithValues(): void
    {
        $this->http->queue(Fixture::response('v1/settings/update'), Fixture::response('v1/settings/get-empty'));

        $this->settings->update('map.example.org', ['hsts' => true, 'client_max_body_size' => '20']);

        self::assertSame('{"hsts":true,"client_max_body_size":"20"}', (string) $this->http->requests()[0]->getBody());
    }

    public function testASubdomainThatIsNoVhostIsAServerError(): void
    {
        $this->http->queue(Fixture::response('v1/settings/unknown-subdomain'));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('server error (HTTP 500)');

        $this->settings->get('www.example.org');
    }

    public function testReadsTheEffectiveSettingsOfTheApex(): void
    {
        $this->http->queue(Fixture::response('v1/settings/flat-apex'));

        $effective = $this->settings->effective('example.com');

        self::assertSame(['GET https://api.myracloud.com/en/rapi/subdomainSetting/example.com?flat'], $this->sent());
        self::assertCount(94, $effective);
        self::assertSame('540', $effective['antibot_post_flood_threshold']); // v1: numbers as strings
    }

    /**
     * @return list<string>
     */
    private function sent(): array
    {
        return array_map(static fn($r): string => $r->getMethod() . ' ' . $r->getUri(), $this->http->requests());
    }
}
