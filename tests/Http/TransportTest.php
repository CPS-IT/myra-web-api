<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\Http;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use JsonException;
use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Auth\HmacSignature;
use Myracloud\WebApi\Exception\AccessDeniedException;
use Myracloud\WebApi\Exception\InvalidArgumentException;
use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\Envelope;
use Myracloud\WebApi\Http\JsonObject;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\Tests\Support\FakeHttpClient;
use Myracloud\WebApi\Tests\Support\FakeNetworkException;
use Myracloud\WebApi\Tests\Support\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

#[CoversClass(Transport::class)]
#[UsesClass(BearerToken::class)]
#[UsesClass(HmacSignature::class)]
#[UsesClass(Envelope::class)]
#[UsesClass(JsonObject::class)]
#[UsesClass(TransportException::class)]
#[UsesClass(\Myracloud\WebApi\Exception\RequestRejectedException::class)]
#[UsesClass(\Myracloud\WebApi\Model\Violation::class)]
final class TransportTest extends TestCase
{
    private FakeHttpClient $http;

    protected function setUp(): void
    {
        $this->http = (new FakeHttpClient())->queue(new Response(200, [], '{"error":false,"data":[]}'));
    }

    #[TestWith(['https://apiv2.myracloud.com', 'domains'])]
    #[TestWith(['https://apiv2.myracloud.com/', '/domains'])]
    #[TestWith(['https://apiv2.myracloud.com//', 'domains'])]
    public function testJoinsBaseUriAndPathWithOneSlash(string $baseUri, string $path): void
    {
        $this->transport($baseUri)->send('GET', $path);

        self::assertSame('https://apiv2.myracloud.com/domains', (string) $this->http->lastRequest()->getUri());
    }

    public function testKeepsTheBasePath(): void
    {
        $this->transport('https://api.myracloud.com/en/rapi/')->send('GET', 'dnsRecords/example.com/1');

        self::assertSame('https://api.myracloud.com/en/rapi/dnsRecords/example.com/1', (string) $this->http->lastRequest()->getUri());
    }

    public function testEncodesTheQueryPerRfc3986(): void
    {
        $this->transport()->send('GET', 'domains', ['page' => 2, 'search' => 'a b']);

        self::assertSame('https://apiv2.myracloud.com/domains?page=2&search=a%20b', (string) $this->http->lastRequest()->getUri());
    }

    public function testSendsJsonHeadersAndNoBodyWithoutOne(): void
    {
        $this->transport()->send('GET', 'domains');
        $request = $this->http->lastRequest();

        self::assertSame('GET', $request->getMethod());
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('', (string) $request->getBody());
    }

    public function testSendsTheBodyAsJsonWithUnescapedSlashes(): void
    {
        $this->transport()->send('PUT', 'domain/1000/cache-clear', [], ['fqdn' => 'www.example.com', 'resource' => '/*', 'recursive' => true]);
        $request = $this->http->lastRequest();

        self::assertSame('PUT', $request->getMethod());
        self::assertSame('{"fqdn":"www.example.com","resource":"/*","recursive":true}', (string) $request->getBody());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function testSendsAnEmptyJsonArrayBody(): void
    {
        $this->transport()->send('PUT', 'x', [], []);

        self::assertSame('[]', (string) $this->http->lastRequest()->getBody());
    }

    public function testAuthenticatesTheFinalRequest(): void
    {
        $hmac = new HmacSignature('test-key', 'test-secret', new FixedClock('2026-10-16T12:00:00+02:00'));
        $transport = new Transport($hmac, 'https://api.myracloud.com/en/rapi', $this->http);

        $transport->send('PUT', 'cacheClear/example.com', [], ['fqdn' => 'www.example.com', 'resource' => '/*', 'recursive' => true]);
        $request = $this->http->lastRequest();

        // The spec vector from HmacSignatureTest: path and body were signed exactly as sent.
        self::assertSame(
            'MYRA test-key:9/YtIGJ9qUyVa/Rvru4k3BuL4BxKePejPhOI6sL2VvDZc91ZhlBGpr2/YJ71niGKMpIq17xhMvQxkY0AVVojTw==',
            $request->getHeaderLine('Authorization'),
        );
        self::assertSame('{"fqdn":"www.example.com","resource":"/*","recursive":true}', $request->getBody()->getContents());
    }

    public function testAppliesBearerTokens(): void
    {
        $this->transport()->send('GET', 'domains');

        self::assertSame('Bearer test-token', $this->http->lastRequest()->getHeaderLine('Authorization'));
    }

    public function testReturnsTheEnvelope(): void
    {
        self::assertSame(['error' => false, 'data' => []], $this->transport()->send('GET', 'domains')->toArray());
    }

    public function testPassesEnvelopeExceptionsOn(): void
    {
        $http = (new FakeHttpClient())->queue(new Response(403));

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Access denied for GET https://apiv2.myracloud.com/domains (HTTP 403, empty response)');

        (new Transport(new BearerToken('test-token'), 'https://apiv2.myracloud.com', $http))->send('GET', 'domains');
    }

    public function testTurnsNetworkFailuresIntoTransportExceptions(): void
    {
        $network = new FakeNetworkException('Connection refused', (new HttpFactory())->createRequest('GET', 'https://apiv2.myracloud.com/domains'));
        $http = (new FakeHttpClient())->queue($network);

        try {
            (new Transport(new BearerToken('test-token'), 'https://apiv2.myracloud.com', $http))->send('GET', 'domains', ['page' => 1]);
            self::fail('No exception thrown.');
        } catch (TransportException $exception) {
            self::assertSame('GET https://apiv2.myracloud.com/domains?page=1: Connection refused', $exception->getMessage());
            self::assertSame($network, $exception->getPrevious());
        }
    }

    public function testRejectsABodyThatIsNotJsonEncodable(): void
    {
        try {
            $this->transport()->send('PUT', 'x', [], ['fqdn' => "\xB1"]);
            self::fail('No exception thrown.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('The request body cannot be encoded as JSON: Malformed UTF-8 characters, possibly incorrectly encoded', $exception->getMessage());
            self::assertInstanceOf(JsonException::class, $exception->getPrevious());
            self::assertSame([], $this->http->requests());
        }
    }

    public function testUsesTheGivenFactories(): void
    {
        $requests = new class implements RequestFactoryInterface {
            public function createRequest(string $method, $uri): RequestInterface
            {
                return (new HttpFactory())->createRequest($method, $uri)->withHeader('X-Factory', 'request');
            }
        };
        $streams = new class implements StreamFactoryInterface {
            public function createStream(string $content = ''): StreamInterface
            {
                return (new HttpFactory())->createStream('stream:' . $content);
            }

            public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
            {
                return (new HttpFactory())->createStreamFromFile($filename, $mode);
            }

            public function createStreamFromResource($resource): StreamInterface
            {
                return (new HttpFactory())->createStreamFromResource($resource);
            }
        };

        (new Transport(new BearerToken('test-token'), 'https://apiv2.myracloud.com', $this->http, $requests, $streams))->send('PUT', 'x', [], ['a' => 1]);
        $request = $this->http->lastRequest();

        self::assertSame('request', $request->getHeaderLine('X-Factory'));
        self::assertSame('stream:{"a":1}', (string) $request->getBody());
    }

    public function testSendsARawBodyWithItsContentType(): void
    {
        $this->transport()->sendRaw('POST', 'domain/1/ip-filters/www.example.com/import', ['type' => 'BLACKLIST'], "192.0.2.1/32,,\n", 'text/csv');
        $request = $this->http->lastRequest();

        self::assertSame('POST https://apiv2.myracloud.com/domain/1/ip-filters/www.example.com/import?type=BLACKLIST', $request->getMethod() . ' ' . $request->getUri());
        self::assertSame('text/csv', $request->getHeaderLine('Content-Type'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame("192.0.2.1/32,,\n", (string) $request->getBody());
    }

    #[TestWith(['text/csv; charset=UTF-8', 200])]
    #[TestWith(['text/plain', 299])]
    #[TestWith(['', 200])]
    public function testFetchesText(string $type, int $status): void
    {
        $http = (new FakeHttpClient())->queue(new Response($status, $type === '' ? [] : ['Content-Type' => $type], "IP,Expires on,Comments\n"));

        $text = (new Transport(new BearerToken('t'), 'https://apiv2.myracloud.com', $http))->fetchText('x/export', ['type' => 'BLACKLIST']);

        self::assertSame("IP,Expires on,Comments\n", $text);
        self::assertSame('GET https://apiv2.myracloud.com/x/export?type=BLACKLIST', $http->lastRequest()->getMethod() . ' ' . $http->lastRequest()->getUri());
    }

    public function testFetchingTextStillReportsFailures(): void
    {
        $http = (new FakeHttpClient())->queue(new Response(403, ['Content-Type' => 'application/json'], '{"error":true,"violationList":[{"message":"The entity does not exist."}]}'));

        $this->expectException(\Myracloud\WebApi\Exception\RequestRejectedException::class);
        $this->expectExceptionMessage('The entity does not exist.');

        (new Transport(new BearerToken('t'), 'https://apiv2.myracloud.com', $http))->fetchText('x/export');
    }

    #[TestWith([403, 'text/html'])]
    #[TestWith([199, 'text/csv'])]
    #[TestWith([300, 'text/csv'])]
    public function testFetchingTextOutside2xxIsNoText(int $status, string $type): void
    {
        $http = (new FakeHttpClient())->queue(new Response($status, ['Content-Type' => $type], 'IP'));

        $this->expectException(\Myracloud\WebApi\Exception\MyraException::class);

        (new Transport(new BearerToken('t'), 'https://apiv2.myracloud.com', $http))->fetchText('x/export');
    }

    #[TestWith(['application/json'])]
    #[TestWith(['Application/JSON; charset=UTF-8'])]
    public function testJsonInsteadOfTextIsUnexpected(string $type): void
    {
        $http = (new FakeHttpClient())->queue(new Response(200, ['Content-Type' => $type], '{"error":false}'));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('GET https://apiv2.myracloud.com/x/export: expected text, got ' . $type . ' (HTTP 200).');

        (new Transport(new BearerToken('t'), 'https://apiv2.myracloud.com', $http))->fetchText('x/export');
    }

    public function testTheDefaultClientIsGuzzleWithTimeouts(): void
    {
        self::assertInstanceOf(GuzzleClient::class, Transport::defaultClient());
        self::assertSame(['connect_timeout' => 10, 'timeout' => 30], Transport::defaultOptions());
    }

    public function testCanBeBuiltWithoutAClient(): void
    {
        $this->expectNotToPerformAssertions();

        new Transport(new BearerToken('test-token'), 'https://apiv2.myracloud.com');
    }

    private function transport(string $baseUri = 'https://apiv2.myracloud.com'): Transport
    {
        return new Transport(new BearerToken('test-token'), $baseUri, $this->http);
    }
}
