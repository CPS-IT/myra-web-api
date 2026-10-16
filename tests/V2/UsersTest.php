<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\V2;

use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Model\Activity;
use Myracloud\WebApi\Model\ApiKey;
use Myracloud\WebApi\Model\ContactType;
use Myracloud\WebApi\Model\Group;
use Myracloud\WebApi\Model\OrganizationContact;
use Myracloud\WebApi\Model\Permission;
use Myracloud\WebApi\Model\Token;
use Myracloud\WebApi\Model\User;
use Myracloud\WebApi\Tests\Support\FakeHttpClient;
use Myracloud\WebApi\Tests\Support\Fixture;
use Myracloud\WebApi\V2;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Users and organisation as captured (names redacted, e-mails userN@example.com).
 */
#[CoversClass(V2\Users::class)]
#[CoversClass(V2\Organization::class)]
#[CoversClass(User::class)]
#[CoversClass(Group::class)]
#[CoversClass(ApiKey::class)]
#[CoversClass(Activity::class)]
#[CoversClass(Permission::class)]
#[CoversClass(Token::class)]
#[CoversClass(OrganizationContact::class)]
#[CoversClass(ContactType::class)]
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
#[UsesClass(V2\DomainIdResolver::class)]
#[UsesClass(\Myracloud\WebApi\Auth\BearerToken::class)]
#[UsesClass(\Myracloud\WebApi\Http\Transport::class)]
#[UsesClass(\Myracloud\WebApi\Http\Envelope::class)]
#[UsesClass(\Myracloud\WebApi\Http\JsonObject::class)]
#[UsesClass(\Myracloud\WebApi\Model\Page::class)]
final class UsersTest extends TestCase
{
    private const BASE = 'GET https://apiv2.myracloud.com/';

    private FakeHttpClient $http;
    private V2\Client $client;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->client = new V2\Client(new BearerToken('t'), httpClient: $this->http);
    }

    public function testReadsUsers(): void
    {
        $this->http->queue(Fixture::response('v2/users/me'), Fixture::response('v2/users/list'), Fixture::response('v2/users/get'));
        $users = $this->client->users();

        $me = $users->me();
        $all = $users->list();
        $one = $users->get(90003);

        self::assertSame([self::BASE . 'user/me', self::BASE . 'users', self::BASE . 'users/90003'], $this->sent());
        self::assertSame(
            [90003, 'user1@example.com', 'user1@example.com', 'redacted-1', 'redacted-1', true, false],
            [$me->id(), $me->login(), $me->email(), $me->firstname(), $me->lastname(), $me->active(), $me->locked()],
        );
        self::assertSame('UserExtendedVO', $me->raw()['objectType']);
        self::assertCount(6, $all);
        self::assertSame(90003, $one->id());
    }

    public function testReadsGroupsAndMembers(): void
    {
        $this->http->queue(Fixture::response('v2/users/groups'), Fixture::response('v2/users/group'), Fixture::response('v2/users/groups-of-user'), Fixture::response('v2/users/group-users'));
        $users = $this->client->users();

        $groups = $users->groups();
        $group = $users->group(90001);
        $ofUser = $users->groupsOf(90003);
        $members = $users->groupMembers(90001);

        self::assertSame([self::BASE . 'user/groups', self::BASE . 'user/groups/90001', self::BASE . 'user/90003/groups', self::BASE . 'user/group/90001/users'], $this->sent());
        self::assertSame([90001, 'redacted-4', 'USER', ['ADMINISTRATOR'], 5], [$groups[0]->id(), $groups[0]->name(), $groups[0]->type(), $groups[0]->roles(), $groups[0]->membersCount()]);
        self::assertSame([], $group->roles(), 'empty in the single group, captured');
        self::assertCount(1, $ofUser);
        self::assertCount(5, $members);
        self::assertSame('GroupVO', $group->raw()['objectType']);
    }

    public function testReadsPermissions(): void
    {
        $this->http->queue(Fixture::response('v2/users/permissions'), Fixture::response('v2/users/permissions-of-user'), Fixture::response('v2/users/group-permissions'));
        $users = $this->client->users();

        $own = $users->permissions();
        $ofUser = $users->permissions(90003);
        $ofGroup = $users->groupPermissions(90001);

        self::assertSame([self::BASE . 'user/permissions', self::BASE . 'user/90003/permissions', self::BASE . 'user/group/90001/permissions'], $this->sent(), 'unpaged: pageSize = count');
        self::assertCount(94, $own);
        self::assertSame([90086, 'Domain', 'GROUP', 'CREATE', null, null], [$own[0]->id(), $own[0]->objectType(), $own[0]->permissionType(), $own[0]->action(), $own[0]->name(), $own[0]->objectId()]);
        self::assertSame(['www.host1.de', 90062], [$ofUser[0]->name(), $ofUser[0]->objectId()]);
        self::assertCount(27, $ofGroup);
        self::assertSame([], $ofGroup[0]->raw()['scopes']);
    }

    public function testReadsApiKeysAndTokensWithoutSecrets(): void
    {
        $this->http->queue(Fixture::response('v2/users/api-keys'), Fixture::response('v2/users/tokens'));
        $users = $this->client->users();

        $keys = $users->apiKeys(90003);
        $tokens = $users->tokens();

        self::assertSame([self::BASE . 'user/90003/api-keys', self::BASE . 'user/tokens'], $this->sent());
        self::assertSame([90009, 'redacted-17', 'redacted-16'], [$keys[0]->id(), $keys[0]->name(), $keys[0]->key()]);
        self::assertSame(
            [90164, 'redacted-65', true, '2026-10-31T23:59:00+0100', '2026-10-16T18:06:15+0200'],
            [$tokens[0]->id(), $tokens[0]->name(), $tokens[0]->enabled(), $tokens[0]->expiryDate(), $tokens[0]->lastUsedDate()],
        );
        self::assertSame([], array_intersect(['secret', 'token', 'value'], array_keys($tokens[0]->raw() + $keys[0]->raw())));
    }

    public function testReadsOnePageOfActivities(): void
    {
        $this->http->queue(Fixture::response('v2/users/activities'), Fixture::response('v2/users/activities'));
        $users = $this->client->users();

        $page = $users->activities(90003);
        $users->activities(90003, 3);

        self::assertSame([self::BASE . 'user/90003/activities', self::BASE . 'user/90003/activities?page=3'], $this->sent());
        self::assertSame([176, true], [$page->total(), $page->hasNext()]);
        $first = $page->items()[0];
        self::assertSame(
            [90012, 'redacted-22', 'Login', 'Failed login attempt', '192.0.2.102', '2024-05-24T14:40:11+0200'],
            [$first->id(), $first->type(), $first->title(), $first->message(), $first->ip(), $first->created()],
        );
        self::assertIsArray($first->raw()['details']);
    }

    public function testReadsTheOrganisation(): void
    {
        $this->http->queue(Fixture::response('v2/organization/contacts'), Fixture::response('v2/organization/contact-types'), Fixture::response('v2/organization/notes'));
        $organization = $this->client->organization();

        $contacts = $organization->contacts();
        $types = $organization->contactTypes();
        $notes = $organization->notes();

        self::assertSame([self::BASE . 'organization/contacts', self::BASE . 'organization/contact-types', self::BASE . 'organization/notes'], $this->sent());
        self::assertSame([90165, 'redacted-66', 'user7@example.com', ['TECHNICAL']], [$contacts[0]->id(), $contacts[0]->name(), $contacts[0]->email(), $contacts[0]->types()]);
        self::assertTrue($contacts[0]->raw()['receiveSSLReminders']);
        self::assertSame(['TECHNICAL', 'redacted-69'], [$types[0]->key(), $types[0]->label()]);
        self::assertSame(['objectType' => 'OrganizationNoteVO'], $notes);
    }

    /**
     * @return list<string>
     */
    private function sent(): array
    {
        return array_map(static fn($r): string => $r->getMethod() . ' ' . $r->getUri(), $this->http->requests());
    }
}
