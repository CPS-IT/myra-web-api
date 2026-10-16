<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\Integration;

use Closure;
use LogicException;
use Myracloud\WebApi\Auth\Authentication;
use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Auth\HmacSignature;
use Myracloud\WebApi\Contract\Client;
use Myracloud\WebApi\Exception\AccessDeniedException;
use Myracloud\WebApi\Exception\DomainNotFoundException;
use Myracloud\WebApi\Exception\RequestRejectedException;
use Myracloud\WebApi\Model\DnsRecord;
use Myracloud\WebApi\Model\Domain;
use Myracloud\WebApi\Model\IpFilter;
use Myracloud\WebApi\Model\Redirect;
use Myracloud\WebApi\V1;
use Myracloud\WebApi\V2;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Both clients over real HTTP — the default Guzzle client, real sockets, HMAC and token on
 * the wire — against the emulator (tests/Emulator), which answers with the captures and
 * checks authentication independently of this package. Pages of 2 make every list span
 * several requests.
 */
#[CoversNothing]
final class IntegrationTest extends TestCase
{
    private static ?EmulatorServer $server = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = new EmulatorServer(['MYRA_EMULATOR_PAGE_SIZE' => '2']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    protected function setUp(): void
    {
        self::server()->reset();
    }

    /**
     * @return iterable<string, array{'v1'|'v2', Closure(): Authentication}>
     */
    public static function clients(): iterable
    {
        $hmac = static fn(): Authentication => new HmacSignature('test-key', 'test-secret');
        $token = static fn(): Authentication => new BearerToken('test-token');
        yield 'v1, HMAC' => ['v1', $hmac];
        yield 'v1, token' => ['v1', $token];
        yield 'v2, HMAC' => ['v2', $hmac];
        yield 'v2, token' => ['v2', $token];
    }

    /**
     * @param 'v1'|'v2' $version
     * @param Closure(): Authentication $auth
     */
    #[DataProvider('clients')]
    public function testListsTheDnsRecordsOverAllPages(string $version, Closure $auth): void
    {
        $records = self::client($version, $auth())->dnsRecords()->list('example.com');

        self::assertSame(
            ['example.com', 'api.example.com', 'www.example.com', 'staging.example.com', 'api.staging.example.com', 'map.example.com'],
            array_map(static fn(DnsRecord $r): string => $r->name(), $records),
        );
        self::assertSame(
            $version === 'v1'
                ? ['GET /en/rapi/dnsRecords/example.com/1', 'GET /en/rapi/dnsRecords/example.com/2', 'GET /en/rapi/dnsRecords/example.com/3']
                : ['GET /domains', 'GET /domain/1000/dns-records', 'GET /domain/1000/dns-records?page=2', 'GET /domain/1000/dns-records?page=3'],
            self::requests(),
        );
        self::assertAllAuthenticated();
    }

    /**
     * @param 'v1'|'v2' $version
     * @param Closure(): Authentication $auth
     */
    #[DataProvider('clients')]
    public function testListsTheDomains(string $version, Closure $auth): void
    {
        $domains = self::client($version, $auth())->domains()->list();

        self::assertSame([[1000, 'example.com'], [1140, 'example.org']], array_map(static fn(Domain $d): array => [$d->id(), $d->name()], $domains));
        self::assertAllAuthenticated();
    }

    /**
     * @param 'v1'|'v2' $version
     * @param Closure(): Authentication $auth
     */
    #[DataProvider('clients')]
    public function testClearsTheCache(string $version, Closure $auth): void
    {
        $client = self::client($version, $auth());

        $single = $client->cacheClear()->clear('example.com', 'www.example.com', '/*', true);
        $bulk = $client->cacheClear()->clearBulk('example.com', 'www.example.com', ['/*.css', '/*.js']);

        self::assertSame(['www.example.com', '/*', true], [$single[0]->fqdn(), $single[0]->resource(), $single[0]->isRecursive()]);
        self::assertSame(['/*.css', '/*.js'], $bulk[0]->bulk());
        $bodies = array_column(array_filter(self::server()->calls(), static fn(array $c): bool => $c['method'] === 'PUT'), 'body');
        self::assertSame(['{"fqdn":"www.example.com","resource":"/*","recursive":true}', '{"fqdn":"www.example.com","bulk":["/*.css","/*.js"],"recursive":false}'], $bodies);
        self::assertAllAuthenticated();
    }

    /**
     * @param 'v1'|'v2' $version
     * @param Closure(): Authentication $auth
     */
    #[DataProvider('clients')]
    public function testListsTheRedirectsOfEachVhost(string $version, Closure $auth): void
    {
        $redirects = self::client($version, $auth())->redirects();

        self::assertSame([90392], array_map(static fn(Redirect $r): ?int => $r->id(), $redirects->list('example.com')));
        self::assertSame([90404], array_map(static fn(Redirect $r): ?int => $r->id(), $redirects->list('www.example.com')));
        self::assertAllAuthenticated();
    }

    /**
     * Writes: the emulator echoes them as the manual/spec describe (not captured yet).
     *
     * @param 'v1'|'v2' $version
     * @param Closure(): Authentication $auth
     */
    #[DataProvider('clients')]
    public function testCreatesUpdatesAndDeletesARedirect(string $version, Closure $auth): void
    {
        $redirects = self::client($version, $auth())->redirects();

        $created = $redirects->create(new Redirect('www.example.com', '/old', 'https://www.example.com/new', Redirect::TYPE_PERMANENT));
        $updated = $redirects->update($created->withDestination('https://www.example.com/newer'));
        $redirects->delete($updated);

        self::assertSame(4711, $created->id());
        self::assertSame('https://www.example.com/newer', $updated->destination());
        self::assertNotSame($created->modified(), $updated->modified());
        $writes = array_values(array_filter(self::requests(), static fn(string $r): bool => !str_starts_with($r, 'GET')));
        self::assertSame(
            $version === 'v1'
                ? ['PUT /en/rapi/redirects/www.example.com', 'POST /en/rapi/redirects/www.example.com', 'DELETE /en/rapi/redirects/www.example.com']
                : ['POST /domain/1000/redirects/www.example.com', 'PUT /domain/1000/redirects/www.example.com/4711', 'DELETE /domain/1000/redirects/www.example.com/4711'],
            $writes,
        );
        self::assertAllAuthenticated();
    }

    /**
     * @param 'v1'|'v2' $version
     * @param Closure(): Authentication $auth
     */
    #[DataProvider('clients')]
    public function testManagesIpFilters(string $version, Closure $auth): void
    {
        $client = self::client($version, $auth());
        $filters = $client->ipFilters();

        self::assertCount(7, $filters->list('example.com'));
        self::assertSame([], $filters->list('www.example.com'));
        $created = $filters->create(new IpFilter('www.example.com', IpFilter::TYPE_BLACKLIST, '192.0.2.1/32', enabled: false));
        $updated = $filters->update($created->withComment('c'));
        $filters->delete($updated);
        self::assertSame('c', $updated->comment());
        if ($client instanceof V2\Client) {
            self::assertStringStartsWith("IP,Expires on,Comments\n192.0.2.123/32,", $client->ipFilters()->export('example.com'));
            self::assertSame(['value: Please enter a valid IP-Range'], array_map('strval', $client->ipFilters()->import('www.example.com', IpFilter::TYPE_BLACKLIST, "192.0.2.2/32,,\nnot-an-ip,,\n")));
        }
        self::assertAllAuthenticated();
    }

    /**
     * @param 'v1'|'v2' $version
     * @param Closure(): Authentication $auth
     */
    #[DataProvider('clients')]
    public function testAHostOutsideTheDomainIsRejected(string $version, Closure $auth): void
    {
        try {
            self::client($version, $auth())->cacheClear()->clear('example.com', 'no-such-host.invalid', '/*');
            self::fail('No exception thrown.');
        } catch (RequestRejectedException $exception) {
            self::assertSame('DOMAIN_DOES_NOT_EXIST', $exception->violations()[0]->code());
        }
    }

    /**
     * @param 'v1'|'v2' $version
     * @param Closure(): Authentication $auth
     */
    #[DataProvider('clients')]
    public function testAnUnknownDomainFails(string $version, Closure $auth): void
    {
        $this->expectException($version === 'v1' ? AccessDeniedException::class : DomainNotFoundException::class);

        self::client($version, $auth())->dnsRecords()->list('does-not-exist.invalid');
    }

    /**
     * @return iterable<string, array{'v1'|'v2', Authentication, string}>
     */
    public static function wrongCredentials(): iterable
    {
        // Exact messages only where captured (v1 wrong secret: text/plain; v2 wrong token:
        // empty body); the other combinations answer the same way by assumption.
        yield 'v1, wrong secret (captured)' => ['v1', new HmacSignature('test-key', 'wrong-secret'), 'API key wrong or not provided.'];
        yield 'v1, unknown key' => ['v1', new HmacSignature('other-key', 'test-secret'), ''];
        yield 'v1, wrong token' => ['v1', new BearerToken('wrong-token'), ''];
        yield 'v2, wrong token (captured)' => ['v2', new BearerToken('wrong-token'), 'empty response'];
        yield 'v2, wrong secret' => ['v2', new HmacSignature('test-key', 'wrong-secret'), ''];
        yield 'v2, unknown key' => ['v2', new HmacSignature('other-key', 'test-secret'), ''];
    }

    /**
     * @param 'v1'|'v2' $version
     * @param string $message expected part of the message; '' = not captured, class only
     */
    #[DataProvider('wrongCredentials')]
    public function testWrongCredentialsAreAccessDenied(string $version, Authentication $auth, string $message): void
    {
        $this->expectException(AccessDeniedException::class);
        if ($message !== '') {
            $this->expectExceptionMessage($message);
        }

        self::client($version, $auth)->domains()->list();
    }

    /**
     * @param 'v1'|'v2' $version
     */
    private static function client(string $version, Authentication $auth): Client
    {
        // No HTTP client given: the built-in Guzzle default, as a consumer would use it.
        return $version === 'v1' ? new V1\Client($auth, baseUri: self::server()->baseUri()) : new V2\Client($auth, self::server()->baseUri());
    }

    private static function server(): EmulatorServer
    {
        return self::$server ?? throw new LogicException('The emulator is not running.');
    }

    /**
     * @return list<string>
     */
    private static function requests(): array
    {
        return array_map(static fn(array $c): string => $c['method'] . ' ' . $c['uri'], self::server()->calls());
    }

    private static function assertAllAuthenticated(): void
    {
        $calls = self::server()->calls();
        self::assertNotSame([], $calls);
        foreach ($calls as $call) {
            self::assertTrue($call['auth_ok'], $call['method'] . ' ' . $call['uri'] . ' was not authenticated.');
            self::assertSame($call['content_length'] ?? 0, $call['body_bytes'], 'Body bytes differ from Content-Length: stream not rewound?');
        }
    }
}
