<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\V2;

use DateTimeImmutable;
use GuzzleHttp\Psr7\Response;
use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Exception\AccessDeniedException;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Model\ApiKey;
use Myracloud\WebApi\Model\Token;
use Myracloud\WebApi\Tests\Support\FakeHttpClient;
use Myracloud\WebApi\Tests\Support\Fixture;
use Myracloud\WebApi\V2;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tokens, API keys and passwords as the spec describes them (unverified, never probed).
 * Secret values are fake and must never show in dumps, raw() or traces.
 */
#[CoversClass(V2\Users::class)]
#[CoversClass(Token::class)]
#[CoversClass(ApiKey::class)]
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
#[UsesClass(\Myracloud\WebApi\Model\Page::class)]
#[UsesClass(\Myracloud\WebApi\Model\Persisted::class)]
#[UsesClass(AccessDeniedException::class)]
#[UsesClass(InvalidArgumentException::class)]
final class CredentialWritesTest extends TestCase
{
    private const BASE = 'https://apiv2.myracloud.com/';
    private const FAKE_TOKEN = 'FAKE-TOKEN-VALUE-0000';
    private const FAKE_SECRET = 'FAKE-SECRET-VALUE-0000';

    private FakeHttpClient $http;
    private V2\Users $users;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->users = (new V2\Client(new BearerToken('t'), httpClient: $this->http))->users();
    }

    public function testCreatesATokenWhoseValueIsOnlyInTheAnswer(): void
    {
        $this->http->queue(self::answer(201, ['objectType' => 'TokenVO', 'id' => 90400, 'name' => 'ci', 'enabled' => true, 'expiryDate' => '2027-01-01T00:00:00+00:00', 'modified' => 'm', 'token' => self::FAKE_TOKEN]), self::answer(201, ['id' => 90401, 'name' => 'x', 'enabled' => false]));

        $token = $this->users->createToken('ci', new DateTimeImmutable('2027-01-01T00:00:00+00:00'));
        $this->users->createToken('x', enabled: false);

        self::assertSame([
            ['POST ' . self::BASE . 'user/tokens', '{"name":"ci","enabled":true,"expiryDate":"2027-01-01T00:00:00+00:00"}'],
            ['POST ' . self::BASE . 'user/tokens', '{"name":"x","enabled":false}'],
        ], $this->sent());
        self::assertSame(self::FAKE_TOKEN, $token->value());
        self::assertArrayNotHasKey('token', $token->raw());
        self::assertSame('m', $token->modified());
        self::assertNotDumped(self::FAKE_TOKEN, $token);
    }

    public function testATokenReadLaterHasNoValue(): void
    {
        $this->http->queue(Fixture::response('v2/users/tokens'));

        $token = $this->users->tokens()[0];

        self::assertNull($token->value());
        self::assertSame('2026-10-16T18:06:15+0200', $token->modified());
    }

    public function testUpdatesAndDeletesATokenReadBefore(): void
    {
        $this->http->queue(Fixture::response('v2/users/tokens'), self::answer(200, ['id' => 90164, 'name' => 'renamed', 'enabled' => false, 'modified' => 'm2']), new Response(204));
        $token = $this->users->tokens()[0];

        $updated = $this->users->updateToken($token->withName('renamed')->withEnabled(false)->withExpiryDate(null));
        $this->users->deleteToken($updated);

        self::assertSame([
            ['PUT ' . self::BASE . 'user/tokens/90164', '{"id":90164,"modified":"2026-10-16T18:06:15+0200","name":"renamed","enabled":false,"expiryDate":null}'],
            ['DELETE ' . self::BASE . 'user/tokens/90164', ''],
        ], array_slice($this->sent(), 1));
        self::assertSame(['redacted-65', true, '2026-10-31T23:59:00+0100'], [$token->name(), $token->enabled(), $token->expiryDate()], 'immutable');
    }

    public function testATokenUpdateNeedsTheVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->users->updateToken(new Token(1, 'n', true, null, null));
    }

    public function testCreatesAnApiKeyWhoseSecretIsOnlyInTheAnswer(): void
    {
        $this->http->queue(self::answer(201, ['objectType' => 'ApiKeyVO', 'id' => 90500, 'name' => 'deploy', 'key' => 'PUBLIC-KEY', 'secret' => self::FAKE_SECRET]), new Response(204));

        $key = $this->users->createApiKey(90003, 'deploy');
        $this->users->deleteApiKey(90003, $key);

        self::assertSame([
            ['POST ' . self::BASE . 'user/90003/api-keys', '{"name":"deploy"}'],
            ['DELETE ' . self::BASE . 'user/90003/api-keys/90500', ''],
        ], $this->sent());
        self::assertSame(['PUBLIC-KEY', self::FAKE_SECRET], [$key->key(), $key->secret()]);
        self::assertArrayNotHasKey('secret', $key->raw());
        self::assertNull((new ApiKey(1, 'n', 'k'))->secret());
        self::assertNotDumped(self::FAKE_SECRET, $key);
    }

    public function testSendsThePasswordRequestsOfTheSpec(): void
    {
        $this->http->queue(new Response(200), new Response(201), new Response(201), new Response(201));

        $this->users->reauthenticate('fake-current');
        $this->users->requestPasswordReset('user1@example.com');
        $this->users->setNewPassword('fake-reset-token', 'fake-new');
        $this->users->changePassword(90003, 'fake-old', 'fake-new');

        self::assertSame([
            ['POST ' . self::BASE . 'user/reauthenticate', '{"credentials":"fake-current"}'],
            ['POST ' . self::BASE . 'user/password/reset', '{"email":"user1@example.com"}'],
            ['POST ' . self::BASE . 'user/password/new', '{"newPassword":"fake-new","confirmPassword":"fake-new","token":"fake-reset-token"}'],
            ['POST ' . self::BASE . 'user/90003/password', '{"oldPassword":"fake-old","newPassword":"fake-new","confirmPassword":"fake-new"}'],
        ], $this->sent());
    }

    public function testPasswordsDoNotShowInTraces(): void
    {
        $this->http->queue(new Response(403));

        try {
            $this->users->changePassword(90003, 'FAKE-OLD-PASSWORD', 'FAKE-NEW-PASSWORD');
            self::fail('No exception thrown.');
        } catch (AccessDeniedException $exception) {
            self::assertStringNotContainsString('FAKE-', $exception->getMessage());
            self::assertStringNotContainsString('FAKE-', $exception->getTraceAsString());
        }
    }

    private static function assertNotDumped(string $secret, object $object): void
    {
        ob_start();
        var_dump($object);
        $dumps = (string) ob_get_clean() . print_r($object, true) . var_export($object, true);

        self::assertStringNotContainsString($secret, $dumps);
    }

    /**
     * @param array<string, mixed> $object
     */
    private static function answer(int $status, array $object): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode(['error' => false, 'violationList' => [], 'data' => [$object]]));
    }

    /**
     * @return list<array{string, string}>
     */
    private function sent(): array
    {
        return array_map(static fn($r): array => [$r->getMethod() . ' ' . $r->getUri(), (string) $r->getBody()], $this->http->requests());
    }
}
