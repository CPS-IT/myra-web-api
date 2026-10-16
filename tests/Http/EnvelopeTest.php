<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\Http;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Myracloud\WebApi\Exception\AccessDeniedException;
use Myracloud\WebApi\Exception\RequestRejectedException;
use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Http\Envelope;
use Myracloud\WebApi\Http\JsonObject;
use Myracloud\WebApi\Model\Violation;
use Myracloud\WebApi\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Envelope::class)]
#[UsesClass(JsonObject::class)]
#[UsesClass(Violation::class)]
#[UsesClass(RequestRejectedException::class)]
#[UsesClass(TransportException::class)]
final class EnvelopeTest extends TestCase
{
    private const URI = 'https://apiv2.myracloud.com/domain/1000/cache-clear';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function successes(): iterable
    {
        yield 'v1 list' => ['v1/dns-records/list', 'list'];
        yield 'v1 write' => ['v1/cache-clear/single', 'targetObject'];
        yield 'v1 bulk' => ['v1/cache-clear/bulk', 'targetObject'];
        yield 'v2 domains' => ['v2/domains/list', 'data'];
        yield 'v2 list' => ['v2/dns-records/list', 'data'];
        yield 'v2 write' => ['v2/cache-clear/single', 'data'];
        yield 'v2 bulk' => ['v2/cache-clear/bulk', 'data'];
    }

    #[DataProvider('successes')]
    public function testReturnsTheEnvelopeOfASuccessfulResponse(string $fixture, string $contentKey): void
    {
        $body = Envelope::parse(self::request(), Fixture::response($fixture));

        self::assertFalse($body->bool('error'));
        self::assertNotSame([], $body->objects($contentKey));
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function rejections(): iterable
    {
        yield 'v1 malformed body' => ['v1/errors/malformed-body', 'Myra rejected PUT ' . self::URI . ' (HTTP 403): Invalid data format.', ['Invalid data format.']];
        yield 'v1 fqdn not in the domain' => [
            'v1/errors/fqdn-not-in-the-domain',
            'Myra rejected PUT ' . self::URI . ' (HTTP 403): fqdn: The given domain does not exist. (DOMAIN_DOES_NOT_EXIST)',
            ['fqdn: The given domain does not exist. (DOMAIN_DOES_NOT_EXIST)'],
        ];
        yield 'v2 malformed body' => ['v2/errors/malformed-body', 'Myra rejected PUT ' . self::URI . ' (HTTP 403): Invalid data format.', ['Invalid data format.']];
        yield 'v2 fqdn not in the domain' => [
            'v2/errors/fqdn-not-in-the-domain',
            'Myra rejected PUT ' . self::URI . ' (HTTP 403): fqdn: The given domain does not exist. (DOMAIN_DOES_NOT_EXIST)',
            ['fqdn: The given domain does not exist. (DOMAIN_DOES_NOT_EXIST)'],
        ];
        yield 'v2 wrong type' => [
            'v2/errors/wrong-type',
            'Myra rejected PUT ' . self::URI . ' (HTTP 403): recursive: This value should be of type boolean.',
            ['recursive: This value should be of type boolean.'],
        ];
        yield 'v2 unknown domain id' => ['v2/errors/unknown-domain-id', 'Myra rejected PUT ' . self::URI . ' (HTTP 403): The entity does not exist.', ['The entity does not exist.']];
        yield 'v2 domain name instead of id' => ['v2/errors/domain-name-instead-of-id', 'Myra rejected PUT ' . self::URI . ' (HTTP 403): The entity does not exist.', ['The entity does not exist.']];
    }

    /**
     * @param list<string> $violations
     */
    #[DataProvider('rejections')]
    public function testRejectedRequestsCarryTheirViolations(string $fixture, string $message, array $violations): void
    {
        try {
            Envelope::parse(self::request(), Fixture::response($fixture));
            self::fail('No exception thrown.');
        } catch (RequestRejectedException $exception) {
            self::assertSame($message, $exception->getMessage());
            self::assertSame($violations, array_map(strval(...), $exception->violations()));
        }
    }

    public function testSeparatesSeveralViolations(): void
    {
        $response = new Response(403, [], '{"error":true,"violationList":[{"propertyPath":"fqdn","message":"A"},{"propertyPath":"","message":"B"}]}');

        $this->expectException(RequestRejectedException::class);
        $this->expectExceptionMessage('Myra rejected PUT ' . self::URI . ' (HTTP 403): fqdn: A; B');

        Envelope::parse(self::request(), $response);
    }

    #[TestWith(['{"error":true,"violationList":[]}'])]
    #[TestWith(['{"error":true}'])]
    public function testARejectionWithoutViolationsSaysSo(string $body): void
    {
        try {
            Envelope::parse(self::request(), new Response(403, [], $body));
            self::fail('No exception thrown.');
        } catch (RequestRejectedException $exception) {
            self::assertSame('Myra rejected PUT ' . self::URI . ' (HTTP 403) without details.', $exception->getMessage());
            self::assertSame([], $exception->violations());
        }
    }

    public function testTheBodyDecidesEvenWithASuccessStatus(): void
    {
        $this->expectException(RequestRejectedException::class);

        Envelope::parse(self::request(), new Response(200, [], '{"error":true,"violationList":[{"message":"A"}]}'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function denials(): iterable
    {
        $empty = ' (HTTP 403, empty response): the credentials are wrong, or the domain does not exist or is not accessible with them (API v1 answers both the same way).';
        yield 'v1 wrong credentials, text/plain' => ['v1/errors/wrong-credentials', 'Access denied for PUT ' . self::URI . ' (HTTP 403): API key wrong or not provided.'];
        yield 'v1 unknown domain, empty body' => ['v1/errors/unknown-domain', 'Access denied for PUT ' . self::URI . $empty];
        yield 'v2 wrong token, empty body' => ['v2/errors/wrong-credentials', 'Access denied for PUT ' . self::URI . $empty];
        yield 'v2 without token, error string' => ['v2/errors/no-token', 'Access denied for PUT ' . self::URI . ' (HTTP 401): Session has been revoked'];
    }

    #[DataProvider('denials')]
    public function testAnswersWithoutEnvelopeAreDenials(string $fixture, string $message): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage($message);

        Envelope::parse(self::request(), Fixture::response($fixture));
    }

    #[TestWith([401])]
    #[TestWith([403])]
    public function testDeniesOn401And403(int $status): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage(sprintf('Access denied for PUT %s (HTTP %d): nope', self::URI, $status));

        Envelope::parse(self::request(), new Response($status, [], " nope\n"));
    }

    public function testQuotesAtMost200CharactersOfANonJsonBody(): void
    {
        try {
            Envelope::parse(self::request(), new Response(403, [], str_repeat('x', 201)));
            self::fail('No exception thrown.');
        } catch (AccessDeniedException $exception) {
            self::assertSame('Access denied for PUT ' . self::URI . ' (HTTP 403): ' . str_repeat('x', 200), $exception->getMessage());
        }
    }

    public function testAJsonObjectWithoutErrorOnA403IsADenial(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Access denied for PUT ' . self::URI . ' (HTTP 403): {"error":false}');

        Envelope::parse(self::request(), new Response(403, [], '{"error":false}'));
    }

    #[TestWith([200])]
    #[TestWith([299])]
    public function testAcceptsEvery2xxStatus(int $status): void
    {
        self::assertSame(['error' => false], Envelope::parse(self::request(), new Response($status, [], '{"error":false}'))->toArray());
    }

    public function testAcceptsAnEnvelopeWithoutErrorField(): void
    {
        self::assertSame(['data' => []], Envelope::parse(self::request(), new Response(200, [], '{"data":[]}'))->toArray());
    }

    public function testAServerErrorKeepsMyrasMessage(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('PUT ' . self::URI . ': server error (HTTP 500). Myra says: An internal error occurred. Reference: 0123abcd; second');

        Envelope::parse(self::request(), new Response(500, [], '{"error":true,"violationList":[{"propertyPath":"","message":"An internal error occurred. Reference: 0123abcd"},{"message":"second"},{"code":"x"},"junk"]}'));
    }

    #[TestWith(['{"error":true}'])]
    #[TestWith(['{"error":true,"violationList":"x"}'])]
    #[TestWith(['<html>Bad Gateway</html>'])]
    public function testAServerErrorWithoutViolationsSaysNoMore(string $body): void
    {
        try {
            Envelope::parse(self::request(), new Response(502, [], $body));
            self::fail('No exception thrown.');
        } catch (TransportException $exception) {
            self::assertSame('PUT ' . self::URI . ': server error (HTTP 502).', $exception->getMessage());
        }
    }

    #[TestWith([200])]
    #[TestWith([204])]
    #[TestWith([299])]
    public function testAnEmptySuccessHasNoContent(int $status): void
    {
        self::assertSame([], Envelope::parse(self::request(), new Response($status))->toArray());
    }

    #[TestWith([199])]
    #[TestWith([300])]
    public function testAnEmptyAnswerOutside2xxIsNoSuccess(int $status): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage(sprintf('unexpected response (HTTP %d)', $status));

        Envelope::parse(self::request(), new Response($status));
    }

    public function testAcceptsAnEmptyJsonObject(): void
    {
        self::assertSame([], Envelope::parse(self::request(), new Response(200, [], '{}'))->toArray());
    }

    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function transportFailures(): iterable
    {
        yield '500 with an envelope' => [500, '{"error":true,"violationList":[]}', 'PUT ' . self::URI . ': server error (HTTP 500).'];
        yield '503' => [503, '', 'PUT ' . self::URI . ': server error (HTTP 503).'];
        yield '200 invalid JSON' => [200, '{"error":', 'PUT ' . self::URI . ': the response is not a JSON object (HTTP 200).'];
        yield '200 JSON list' => [200, '[1,2]', 'PUT ' . self::URI . ': the response is not a JSON object (HTTP 200).'];
        yield '200 JSON scalar' => [200, '"ok"', 'PUT ' . self::URI . ': the response is not a JSON object (HTTP 200).'];
        yield '299 not JSON' => [299, 'ok', 'PUT ' . self::URI . ': the response is not a JSON object (HTTP 299).'];
        yield '199' => [199, '{"error":false}', 'PUT ' . self::URI . ': unexpected response (HTTP 199).'];
        yield '300' => [300, '{"error":false}', 'PUT ' . self::URI . ': unexpected response (HTTP 300).'];
        yield '400' => [400, '<html>', 'PUT ' . self::URI . ': unexpected response (HTTP 400).'];
        yield '402' => [402, '', 'PUT ' . self::URI . ': unexpected response (HTTP 402).'];
        yield '404' => [404, '{"error":false}', 'PUT ' . self::URI . ': unexpected response (HTTP 404).'];
        yield '499' => [499, '', 'PUT ' . self::URI . ': unexpected response (HTTP 499).'];
    }

    #[DataProvider('transportFailures')]
    public function testEverythingElseIsATransportFailure(int $status, string $body, string $message): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage($message);

        Envelope::parse(self::request(), new Response($status, [], $body));
    }

    private static function request(): Request
    {
        return new Request('PUT', self::URI);
    }
}
