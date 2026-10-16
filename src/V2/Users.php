<?php

declare(strict_types=1);

namespace Myracloud\WebApi\V2;

use Closure;
use Myracloud\WebApi\Exception\MyraException;
use Myracloud\WebApi\Http\JsonObject;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Model\Activity;
use Myracloud\WebApi\Model\ApiKey;
use Myracloud\WebApi\Model\Group;
use Myracloud\WebApi\Model\Page;
use Myracloud\WebApi\Model\Permission;
use Myracloud\WebApi\Model\Token;
use Myracloud\WebApi\Model\User;

/**
 * Users, groups, permissions, API keys and tokens of the organisation (v2 only):
 * `/users`, `/user/…`. Reads captured; lists are collected over all pages (the own
 * permissions come unpaged, captured).
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
