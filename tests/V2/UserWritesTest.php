<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\V2;

use GuzzleHttp\Psr7\Response;
use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Exception\RequestRejectedException;
use Myracloud\WebApi\Model\Group;
use Myracloud\WebApi\Model\Permission;
use Myracloud\WebApi\Model\User;
use Myracloud\WebApi\Tests\Support\FakeHttpClient;
use Myracloud\WebApi\Tests\Support\Fixture;
use Myracloud\WebApi\V2;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Writes of users, groups and permissions as the spec describes them (unverified: never
 * probed against a live account).
 */
#[CoversClass(V2\Users::class)]
#[CoversClass(User::class)]
#[CoversClass(Group::class)]
#[UsesClass(Permission::class)]
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
#[UsesClass(V2\Organization::class)]
#[UsesClass(V2\DomainIdResolver::class)]
#[UsesClass(\Myracloud\WebApi\Auth\BearerToken::class)]
#[UsesClass(\Myracloud\WebApi\Http\Transport::class)]
#[UsesClass(\Myracloud\WebApi\Http\Envelope::class)]
#[UsesClass(\Myracloud\WebApi\Http\JsonObject::class)]
#[UsesClass(\Myracloud\WebApi\Model\Persisted::class)]
#[UsesClass(\Myracloud\WebApi\Model\Violation::class)]
#[UsesClass(RequestRejectedException::class)]
#[UsesClass(InvalidArgumentException::class)]
final class UserWritesTest extends TestCase
{
    private const BASE = 'https://apiv2.myracloud.com/';

    private FakeHttpClient $http;
    private V2\Users $users;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->users = (new V2\Client(new BearerToken('t'), httpClient: $this->http))->users();
    }

    public function testCreatesAUserWithLoginEqualToEmail(): void
    {
        $this->http->queue(self::answer(201, self::user()), self::answer(201, self::user()));

        $created = $this->users->create('new@example.com', 'Ada', 'Lovelace', ['USER']);
        $this->users->create('min@example.com');

        self::assertSame(90100, $created->id());
        self::assertSame([
            ['POST ' . self::BASE . 'users', '{"email":"new@example.com","login":"new@example.com","firstname":"Ada","lastname":"Lovelace","roles":["USER"]}'],
            ['POST ' . self::BASE . 'users', '{"email":"min@example.com","login":"min@example.com","firstname":"","lastname":""}'],
        ], $this->sent());
    }

    public function testUpdatesWithTheRoleFieldsAsRead(): void
    {
        $user = $this->captured('v2/users/me');
        $this->http->queue(self::answer(200, self::user()));

        $this->users->update($user->withEmail('changed@example.com')->withName('A', 'B'));

        [[$line, $body]] = $this->sent();
        self::assertSame('PUT ' . self::BASE . 'users/90003', $line);
        $sent = json_decode($body, true);
        self::assertIsArray($sent);
        self::assertSame(['id', 'modified', 'email', 'login', 'firstname', 'lastname', 'roles', 'rootGroupRoles', 'isIndirectCustomer'], array_keys($sent));
        self::assertSame(['changed@example.com', 'changed@example.com', 'A', 'B'], [$sent['email'], $sent['login'], $sent['firstname'], $sent['lastname']]);
        self::assertSame([$user->raw()['roles'], $user->raw()['rootGroupRoles'], $user->raw()['modified']], [$sent['roles'], $sent['rootGroupRoles'], $sent['modified']]);
        self::assertArrayNotHasKey('sessions', $sent);
        self::assertSame('user1@example.com', $user->email(), 'immutable');
    }

    public function testAUserWithoutRoleFieldsSendsNone(): void
    {
        $user = new User(1, 'a@example.com', 'a@example.com', '', '', true, false, ['modified' => 'm']);

        self::assertSame(['id' => 1, 'modified' => 'm', 'email' => 'a@example.com', 'login' => 'a@example.com', 'firstname' => '', 'lastname' => ''], $user->toJson());
        self::assertNull((new User(1, 'a', 'a', '', '', true, false))->modified());
    }

    public function testAnUpdateNeedsTheVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            $this->users->update(new User(1, 'a', 'a', '', '', true, false));
        } finally {
            self::assertSame([], $this->http->requests());
        }
    }

    public function testDeletesAUserOnlyWhenForcedOutOfItsGroups(): void
    {
        $user = $this->captured('v2/users/get');
        $this->http->queue(new Response(409, ['Content-Type' => 'application/json'], '{"error":"user_assigned_to_groups"}'), new Response(204));

        try {
            $this->users->delete($user);
            self::fail('No exception thrown.');
        } catch (RequestRejectedException $exception) {
            self::assertSame('user_assigned_to_groups', $exception->violations()[0]->code());
        }
        $this->users->delete($user, force: true);

        self::assertSame([['DELETE ' . self::BASE . 'users/90003', ''], ['DELETE ' . self::BASE . 'users/90003?force=true', '']], $this->sent());
    }

    public function testEndsASession(): void
    {
        $this->http->queue(new Response(204));

        $this->users->deleteSession(90003, 90002);

        self::assertSame([['DELETE ' . self::BASE . 'user/90003/sessions/90002', '']], $this->sent());
    }

    public function testManagesGroupsWithAnExplicitParent(): void
    {
        $group = self::groupFrom('v2/users/group');
        $answer = ['objectType' => 'GroupVO', 'id' => 90200, 'name' => 'ops', 'type' => 'USER', 'roles' => [], 'membersCount' => 0, 'modified' => 'm2'];
        $this->http->queue(self::answer(201, $answer), self::answer(200, $answer), new Response(204));

        $created = $this->users->createGroup('ops', 90001);
        $this->users->updateGroup($group->withName('renamed'), 90001);
        $this->users->deleteGroup($created);

        self::assertSame([
            ['POST ' . self::BASE . 'user/groups', '{"name":"ops","parent":90001}'],
            ['PUT ' . self::BASE . 'user/groups/90001', '{"id":90001,"modified":"2021-08-26T10:14:52+0200","name":"renamed","parent":90001}'],
            ['DELETE ' . self::BASE . 'user/groups/90200', ''],
        ], $this->sent());
        self::assertSame(['m2', 'redacted-4'], [$created->modified(), $group->name()]);
    }

    public function testAGroupUpdateNeedsTheVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->users->updateGroup(new Group(1, 'g', 'USER', [], 0), 2);
    }

    public function testManagesMembers(): void
    {
        $user = $this->captured('v2/users/get');
        $this->http->queue(self::answer(201, ['objectType' => 'GroupRoleVO', 'role' => 'USER', 'userId' => 90003]), new Response(204));

        $role = $this->users->addGroupMember(90001, $user, 'USER');
        $this->users->removeGroupMember(90001, 90003);

        self::assertSame(['objectType' => 'GroupRoleVO', 'role' => 'USER', 'userId' => 90003], $role);
        self::assertSame([
            ['POST ' . self::BASE . 'user/group/90001/users', '{"email":"user1@example.com","login":"user1@example.com","role":"USER","userId":90003}'],
            ['DELETE ' . self::BASE . 'user/group/90001/users/90003', ''],
        ], $this->sent());
    }

    public function testGrantsRevokesAndChecksPermissions(): void
    {
        $permission = ['objectType' => 'Domain', 'objectPermissionType' => 'USER', 'action' => 'READ', 'id' => 90300];
        $this->http->queue(self::answer(201, $permission), self::answer(201, $permission), new Response(204), new Response(204), self::answer(201, ['isAuthorized' => true]), self::answer(201, ['isAuthorized' => false]));

        $granted = $this->users->grantToUser(90003, 'Domain', 'READ', 1000);
        $this->users->grantToGroup(90001, 'Tag', 'EDIT');
        $this->users->revokeFromUser(90003, $granted);
        $this->users->revokeFromGroup(90001, $granted);

        self::assertTrue($this->users->isAuthorized('Domain', 'CACHE_CLEAR', 1000));
        self::assertFalse($this->users->isAuthorized('Organization', 'EDIT'));
        self::assertSame([
            ['POST ' . self::BASE . 'user/90003/permissions', '{"objectType":"Domain","objectPermissionType":"USER","action":"READ","objectInstance":1000}'],
            ['POST ' . self::BASE . 'user/group/90001/permissions', '{"objectType":"Tag","objectPermissionType":"GROUP","action":"EDIT"}'],
            ['DELETE ' . self::BASE . 'user/90003/permissions/90300', ''],
            ['DELETE ' . self::BASE . 'user/group/90001/permissions/90300', ''],
            ['POST ' . self::BASE . 'user/permissions/check', '{"objectType":"Domain","action":"CACHE_CLEAR","objectInstance":1000}'],
            ['POST ' . self::BASE . 'user/permissions/check', '{"objectType":"Organization","action":"EDIT"}'],
        ], $this->sent());
    }

    /**
     * @return array<string, mixed>
     */
    private static function user(): array
    {
        return ['objectType' => 'UserVO', 'id' => 90100, 'login' => 'new@example.com', 'email' => 'new@example.com', 'firstname' => 'Ada', 'lastname' => 'Lovelace', 'active' => true, 'locked' => false, 'modified' => 'm'];
    }

    /**
     * @param array<string, mixed> $object
     */
    private static function answer(int $status, array $object): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode(['error' => false, 'violationList' => [], 'data' => [$object]]));
    }

    private function captured(string $fixture): User
    {
        $http = (new FakeHttpClient())->queue(Fixture::response($fixture));
        $users = (new V2\Client(new BearerToken('t'), httpClient: $http))->users();

        return $fixture === 'v2/users/me' ? $users->me() : $users->get(90003);
    }

    private static function groupFrom(string $fixture): Group
    {
        $http = (new FakeHttpClient())->queue(Fixture::response($fixture));

        return (new V2\Client(new BearerToken('t'), httpClient: $http))->users()->group(90001);
    }

    /**
     * @return list<array{string, string}>
     */
    private function sent(): array
    {
        return array_map(static fn($r): array => [$r->getMethod() . ' ' . $r->getUri(), (string) $r->getBody()], $this->http->requests());
    }
}
