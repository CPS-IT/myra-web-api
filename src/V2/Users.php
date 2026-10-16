<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V2;

use Closure;
use DateTimeInterface;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Exception\RequestRejectedException;
use Myracloud\WebApi\Http\JsonObject;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\Activity;
use Myracloud\WebApi\Model\ApiKey;
use Myracloud\WebApi\Model\Group;
use Myracloud\WebApi\Model\Page;
use Myracloud\WebApi\Model\Permission;
use Myracloud\WebApi\Model\Persisted;
use Myracloud\WebApi\Model\Token;
use Myracloud\WebApi\Model\User;
use SensitiveParameter;

/**
 * Users, groups, permissions, API keys and tokens of the organisation (v2 only):
 * `/users`, `/user/…`. Reads captured; lists are collected over all pages (the own
 * permissions come unpaged, captured).
 *
 * Every write here is *unverified* (built from the spec; users, groups and permissions are
 * never probed against a live account). Creates take their fields as arguments, updates the
 * object read before, changed with with…().
 */
final readonly class Users
{
    /**
     * @internal built by {@see Client}
     */
    public function __construct(private Transport $transport) {}

    /**
     * The user the credentials belong to (`UserExtendedVO`: sessions etc. in raw()).
     *
     * @throws MyraException
     */
    public function me(): User
    {
        return User::fromJson($this->transport->send('GET', 'user/me')->first('data'));
    }

    /**
     * @return list<User>
     *
     * @throws MyraException
     */
    public function list(): array
    {
        return $this->collect('users', User::fromJson(...));
    }

    /**
     * @throws MyraException
     */
    public function get(int $id): User
    {
        return User::fromJson($this->transport->send('GET', 'users/' . $id)->first('data'));
    }

    /**
     * @return list<Group>
     *
     * @throws MyraException
     */
    public function groupsOf(int $userId): array
    {
        return $this->collect('user/' . $userId . '/groups', Group::fromJson(...));
    }

    /**
     * @return list<ApiKey>
     *
     * @throws MyraException
     */
    public function apiKeys(int $userId): array
    {
        return $this->collect('user/' . $userId . '/api-keys', ApiKey::fromJson(...));
    }

    /**
     * A user's activity log, newest first — one page (it grows long; captured: 176 entries).
     *
     * @param int $page 1-based
     *
     * @return Page<Activity>
     *
     * @throws MyraException
     */
    public function activities(int $userId, int $page = 1): Page
    {
        return Page::fromJson($this->transport->send('GET', 'user/' . $userId . '/activities', Domains::query(Page::number($page))), 'data', Activity::fromJson(...));
    }

    /**
     * The permissions of a user — or, without an id, of the user the credentials belong to.
     *
     * @return list<Permission>
     *
     * @throws MyraException
     */
    public function permissions(?int $userId = null): array
    {
        return $this->collect($userId === null ? 'user/permissions' : 'user/' . $userId . '/permissions', Permission::fromJson(...));
    }

    /**
     * The API tokens (metadata, never the token).
     *
     * @return list<Token>
     *
     * @throws MyraException
     */
    public function tokens(): array
    {
        return $this->collect('user/tokens', Token::fromJson(...));
    }

    /**
     * @return list<Group>
     *
     * @throws MyraException
     */
    public function groups(): array
    {
        return $this->collect('user/groups', Group::fromJson(...));
    }

    /**
     * @throws MyraException
     */
    public function group(int $id): Group
    {
        return Group::fromJson($this->transport->send('GET', 'user/groups/' . $id)->first('data'));
    }

    /**
     * @return list<User>
     *
     * @throws MyraException
     */
    public function groupMembers(int $groupId): array
    {
        return $this->collect('user/group/' . $groupId . '/users', User::fromJson(...));
    }

    /**
     * @return list<Permission>
     *
     * @throws MyraException
     */
    public function groupPermissions(int $groupId): array
    {
        return $this->collect('user/group/' . $groupId . '/permissions', Permission::fromJson(...));
    }

    /**
     * Creates a user account (`login` = `email`, as the spec asks).
     *
     * @param list<string> $roles "ADMINISTRATOR" or "USER"; none = Myra's default
     *
     * @throws MyraException
     */
    public function create(string $email, string $firstname = '', string $lastname = '', array $roles = []): User
    {
        $body = ['email' => $email, 'login' => $email, 'firstname' => $firstname, 'lastname' => $lastname] + ($roles === [] ? [] : ['roles' => $roles]);

        return User::fromJson($this->transport->send('POST', 'users', body: $body)->first('data'));
    }

    /**
     * Changes e-mail/login and names of a user read before. The role fields are sent exactly
     * as read: whether Myra keeps one that is left out is not verified, and losing one could
     * take away administrator rights — including your own.
     *
     * @throws InvalidArgumentException if id or modified is missing
     * @throws MyraException
     */
    public function update(User $user): User
    {
        [$id] = Persisted::versionOf($user->id(), $user->modified(), 'user');

        return User::fromJson($this->transport->send('PUT', 'users/' . $id, body: $user->toJson())->first('data'));
    }

    /**
     * @param bool $force also when the user is still in groups (then removed from them first);
     *                    without it, Myra refuses with the violation code "user_assigned_to_groups" (spec: 409)
     *
     * @throws RequestRejectedException
     * @throws MyraException
     */
    public function delete(User $user, bool $force = false): void
    {
        $this->transport->send('DELETE', 'users/' . $user->id(), $force ? ['force' => 'true'] : []);
    }

    /**
     * Ends a session of a user (the ids are in raw()['sessions'] of me()/get()).
     *
     * @throws MyraException
     */
    public function deleteSession(int $userId, int $sessionId): void
    {
        $this->transport->send('DELETE', 'user/' . $userId . '/sessions/' . $sessionId);
    }

    /**
     * @param int $parent the id of the parent group (required by the spec)
     *
     * @throws MyraException
     */
    public function createGroup(string $name, int $parent): Group
    {
        return Group::fromJson($this->transport->send('POST', 'user/groups', body: ['name' => $name, 'parent' => $parent])->first('data'));
    }

    /**
     * @param int $parent the id of the parent group — required by the spec, not part of the
     *                    group as read, so given here
     *
     * @throws InvalidArgumentException if id or modified is missing
     * @throws MyraException
     */
    public function updateGroup(Group $group, int $parent): Group
    {
        Persisted::versionOf($group->id(), $group->modified(), 'group');

        return Group::fromJson($this->transport->send('PUT', 'user/groups/' . $group->id(), body: $group->toJson($parent))->first('data'));
    }

    /**
     * @throws MyraException
     */
    public function deleteGroup(Group $group): void
    {
        $this->transport->send('DELETE', 'user/groups/' . $group->id());
    }

    /**
     * Adds a user to a group with a role (`GroupRoleVO`, answered as Myra sends it).
     *
     * @param string $role "ADMINISTRATOR" or "USER"
     *
     * @return array<mixed>
     *
     * @throws MyraException
     */
    public function addGroupMember(int $groupId, User $user, string $role): array
    {
        $body = ['email' => $user->email(), 'login' => $user->login(), 'role' => $role, 'userId' => $user->id()];

        return $this->transport->send('POST', 'user/group/' . $groupId . '/users', body: $body)->first('data')->toArray();
    }

    /**
     * @throws MyraException
     */
    public function removeGroupMember(int $groupId, int $userId): void
    {
        $this->transport->send('DELETE', 'user/group/' . $groupId . '/users/' . $userId);
    }

    /**
     * Gives a user a permission: an action on a kind of object, or on one object.
     *
     * @param string $objectType e.g. "Domain", "Tag", "Organization"
     * @param string $action "READ", "EDIT", "CREATE" or "CACHE_CLEAR"
     * @param int|null $objectId one object (`objectInstance`); null = the kind of object
     *
     * @throws MyraException
     */
    public function grantToUser(int $userId, string $objectType, string $action, ?int $objectId = null): Permission
    {
        return $this->grant('user/' . $userId . '/permissions', 'USER', $objectType, $action, $objectId);
    }

    /**
     * @param string $objectType e.g. "Domain", "Tag", "Organization"
     * @param string $action "READ", "EDIT", "CREATE" or "CACHE_CLEAR"
     * @param int|null $objectId one object (`objectInstance`); null = the kind of object
     *
     * @throws MyraException
     */
    public function grantToGroup(int $groupId, string $objectType, string $action, ?int $objectId = null): Permission
    {
        return $this->grant('user/group/' . $groupId . '/permissions', 'GROUP', $objectType, $action, $objectId);
    }

    /**
     * @throws MyraException
     */
    public function revokeFromUser(int $userId, Permission $permission): void
    {
        $this->transport->send('DELETE', 'user/' . $userId . '/permissions/' . $permission->id());
    }

    /**
     * @throws MyraException
     */
    public function revokeFromGroup(int $groupId, Permission $permission): void
    {
        $this->transport->send('DELETE', 'user/group/' . $groupId . '/permissions/' . $permission->id());
    }

    /**
     * Whether the user the credentials belong to may do $action (a read sent as POST).
     *
     * @param string $objectType e.g. "Domain"
     * @param string $action "READ", "EDIT", "CREATE" or "CACHE_CLEAR"
     * @param int|null $objectId one object; null = the kind of object
     *
     * @throws MyraException
     */
    public function isAuthorized(string $objectType, string $action, ?int $objectId = null): bool
    {
        $body = ['objectType' => $objectType, 'action' => $action] + ($objectId === null ? [] : ['objectInstance' => $objectId]);

        return $this->transport->send('POST', 'user/permissions/check', body: $body)->first('data')->bool('isAuthorized');
    }

    /**
     * Creates an API token for the user the credentials belong to. Its value is only in this
     * answer: {@see Token::value()} — store it, Myra never shows it again.
     *
     * @param DateTimeInterface|null $expires not in the past; null = never expires
     *
     * @throws MyraException
     */
    public function createToken(string $name, ?DateTimeInterface $expires = null, bool $enabled = true): Token
    {
        $body = ['name' => $name, 'enabled' => $enabled] + ($expires === null ? [] : ['expiryDate' => $expires->format(DATE_ATOM)]);

        return Token::fromJson($this->transport->send('POST', 'user/tokens', body: $body)->first('data'));
    }

    /**
     * Changes name, enabled or expiry date of a token read before. Disabling or expiring the
     * token these credentials use locks this client out.
     *
     * @throws InvalidArgumentException if modified is missing
     * @throws MyraException
     */
    public function updateToken(Token $token): Token
    {
        Persisted::versionOf($token->id(), $token->modified(), 'token');

        return Token::fromJson($this->transport->send('PUT', 'user/tokens/' . $token->id(), body: $token->toJson())->first('data'));
    }

    /**
     * Deleting the token these credentials use locks this client out.
     *
     * @throws MyraException
     */
    public function deleteToken(Token $token): void
    {
        $this->transport->send('DELETE', 'user/tokens/' . $token->id());
    }

    /**
     * Creates an API key for a user. Its secret is only in this answer: {@see ApiKey::secret()}.
     *
     * @throws MyraException
     */
    public function createApiKey(int $userId, string $name): ApiKey
    {
        return ApiKey::fromJson($this->transport->send('POST', 'user/' . $userId . '/api-keys', body: ['name' => $name])->first('data'));
    }

    /**
     * Deleting the key these credentials use locks this client out.
     *
     * @throws MyraException
     */
    public function deleteApiKey(int $userId, ApiKey $key): void
    {
        $this->transport->send('DELETE', 'user/' . $userId . '/api-keys/' . $key->id());
    }

    /**
     * Confirms the own password (`ReauthenticationVO`), e.g. before sensitive changes.
     *
     * @throws MyraException
     */
    public function reauthenticate(#[SensitiveParameter] string $password): void
    {
        $this->transport->send('POST', 'user/reauthenticate', body: ['credentials' => $password]);
    }

    /**
     * Sends the owner of the address an e-mail with a link to set a new password.
     *
     * @throws MyraException
     */
    public function requestPasswordReset(string $email): void
    {
        $this->transport->send('POST', 'user/password/reset', body: ['email' => $email]);
    }

    /**
     * Sets a new password with the token from the reset e-mail (valid one hour; with
     * two-factor authentication its step must be done first — spec).
     *
     * @param string $resetToken the part of the e-mail's link after "/new-password/"
     *
     * @throws MyraException an unknown, used or expired token answers 403 without body (spec)
     */
    public function setNewPassword(#[SensitiveParameter] string $resetToken, #[SensitiveParameter] string $password): void
    {
        $this->transport->send('POST', 'user/password/new', body: ['newPassword' => $password, 'confirmPassword' => $password, 'token' => $resetToken]);
    }

    /**
     * @throws MyraException
     */
    public function changePassword(int $userId, #[SensitiveParameter] string $oldPassword, #[SensitiveParameter] string $newPassword): void
    {
        $this->transport->send('POST', 'user/' . $userId . '/password', body: ['oldPassword' => $oldPassword, 'newPassword' => $newPassword, 'confirmPassword' => $newPassword]);
    }

    /**
     * @throws MyraException
     */
    private function grant(string $path, string $permissionType, string $objectType, string $action, ?int $objectId): Permission
    {
        $body = ['objectType' => $objectType, 'objectPermissionType' => $permissionType, 'action' => $action] + ($objectId === null ? [] : ['objectInstance' => $objectId]);

        return Permission::fromJson($this->transport->send('POST', $path, body: $body)->first('data'));
    }

    /**
     * @template T
     *
     * @param Closure(JsonObject): T $item
     *
     * @return list<T>
     */
    private function collect(string $path, Closure $item): array
    {
        return Page::collect(fn(int $page): Page => Page::fromJson($this->transport->send('GET', $path, Domains::query($page)), 'data', $item));
    }
}
