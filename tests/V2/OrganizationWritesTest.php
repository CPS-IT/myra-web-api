<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\V2;

use GuzzleHttp\Psr7\Response;
use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Model\OrganizationContact;
use Myracloud\WebApi\Tests\Support\FakeHttpClient;
use Myracloud\WebApi\Tests\Support\Fixture;
use Myracloud\WebApi\V2;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Organisation writes as the spec describes them (unverified, never probed).
 */
#[CoversClass(V2\Organization::class)]
#[CoversClass(OrganizationContact::class)]
#[UsesClass(V2\Users::class)]
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
#[UsesClass(V2\Zones::class)]
#[UsesClass(V2\MaintenanceTemplates::class)]
#[UsesClass(V2\Subdomains::class)]
#[UsesClass(V2\DomainVerifications::class)]
#[UsesClass(V2\Waf::class)]
#[UsesClass(V2\DomainIdResolver::class)]
#[UsesClass(\Myracloud\WebApi\Auth\BearerToken::class)]
#[UsesClass(\Myracloud\WebApi\Http\Transport::class)]
#[UsesClass(\Myracloud\WebApi\Http\Envelope::class)]
#[UsesClass(\Myracloud\WebApi\Http\JsonObject::class)]
#[UsesClass(\Myracloud\WebApi\Model\Page::class)]
#[UsesClass(\Myracloud\WebApi\Model\Persisted::class)]
#[UsesClass(InvalidArgumentException::class)]
final class OrganizationWritesTest extends TestCase
{
    private const BASE = 'https://apiv2.myracloud.com/';

    private FakeHttpClient $http;
    private V2\Organization $organization;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->organization = (new V2\Client(new BearerToken('t'), httpClient: $this->http))->organization();
    }

    public function testAddsContactsBothWays(): void
    {
        $this->http->queue(self::answer(201), self::answer(201), self::answer(201));

        $contact = $this->organization->addContact('Ops', 'ops@example.com', '+49 30 1234567', sslReminders: true);
        $this->organization->addContact('Min', 'min@example.com');
        $this->organization->addUserAsContact(90003, trafficAlerts: true);

        self::assertSame(90600, $contact->id());
        self::assertSame([
            ['POST ' . self::BASE . 'organization/contacts', '{"name":"Ops","email":"ops@example.com","phone":"+49 30 1234567","receiveSSLReminders":true,"receiveTrafficAlerts":false}'],
            ['POST ' . self::BASE . 'organization/contacts', '{"name":"Min","email":"min@example.com","receiveSSLReminders":false,"receiveTrafficAlerts":false}'],
            ['POST ' . self::BASE . 'organization/contacts', '{"userId":90003,"receiveSSLReminders":false,"receiveTrafficAlerts":true}'],
        ], $this->sent());
    }

    public function testUpdatesAndDeletesAContactReadBefore(): void
    {
        $this->http->queue(Fixture::response('v2/organization/contacts'), self::answer(200), new Response(204));
        $contact = $this->organization->contacts()[0];

        $this->organization->updateContact($contact->withName('Renamed')->withEmail('new@example.com')->withPhone('030 123')->withNotifications(false, true));
        $this->organization->deleteContact($contact);

        [, [$line, $body], $delete] = $this->sent();
        self::assertSame('PUT ' . self::BASE . 'organization/contacts/90165', $line);
        self::assertSame(['DELETE ' . self::BASE . 'organization/contacts/90165', ''], $delete);
        $sent = json_decode($body, true);
        self::assertIsArray($sent);
        self::assertSame(['id', 'modified', 'name', 'email', 'phone', 'receiveSSLReminders', 'receiveTrafficAlerts', 'technicalPriority'], array_keys($sent));
        self::assertSame([90165, '2026-01-23T13:37:55+0100', 'Renamed', 'new@example.com', '030 123', false, true, 1], array_values($sent));
        self::assertSame(['redacted-66', 'user7@example.com', '2026-01-23T13:37:55+0100'], [$contact->name(), $contact->email(), $contact->modified()], 'immutable');
    }

    public function testAContactUpdateNeedsTheVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            $this->organization->updateContact(new OrganizationContact(1, 'n', 'e@example.com', []));
        } finally {
            self::assertSame([], $this->http->requests());
        }
    }

    public function testAContactWithoutOptionalFieldsSendsNone(): void
    {
        self::assertSame(['id' => 1, 'modified' => null, 'name' => 'n', 'email' => 'e'], (new OrganizationContact(1, 'n', 'e', []))->toJson());
    }

    public function testReplacesTheNote(): void
    {
        $this->http->queue(self::note(), self::note());

        $note = $this->organization->updateNotes('Maintenance window: Sundays', '2026-10-16T12:00:00+0200');
        $this->organization->updateNotes(null);

        self::assertSame(['objectType' => 'OrganizationNoteVO', 'notes' => 'x'], $note);
        self::assertSame([
            ['PUT ' . self::BASE . 'organization/notes', '{"notes":"Maintenance window: Sundays","modified":"2026-10-16T12:00:00+0200"}'],
            ['PUT ' . self::BASE . 'organization/notes', '{"notes":null}'],
        ], $this->sent());
    }

    private static function answer(int $status): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], '{"error":false,"data":[{"objectType":"OrganizationContactVO","id":90600,"name":"Ops","email":"ops@example.com","types":[],"modified":"m"}]}');
    }

    private static function note(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], '{"error":false,"data":[{"objectType":"OrganizationNoteVO","notes":"x"}]}');
    }

    /**
     * @return list<array{string, string}>
     */
    private function sent(): array
    {
        return array_map(static fn($r): array => [$r->getMethod() . ' ' . $r->getUri(), (string) $r->getBody()], $this->http->requests());
    }
}
