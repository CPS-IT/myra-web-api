<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Http;

use JsonException;
use Myracloud\WebApi\Exception\AccessDeniedException;
use Myracloud\WebApi\Exception\RequestRejectedException;
use Myracloud\WebApi\Exception\TransportException;
use Myracloud\WebApi\Model\Violation;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Turns a Myra response into its envelope, or into the exception it stands for.
 *
 * v1 and v2 share one envelope: `{error, violationList, warningList, …}` plus the content
 * (v1 `list` / `targetObject`, v2 `data`). Every failure is HTTP 403 (rarely 401), so the
 * body decides, not the status (see docs/captures in cpsit/myra-api-emulator):
 *
 * - envelope with `error: true`        → RequestRejectedException (violations)
 * - `error` is a string, HTTP 401/403 → AccessDeniedException (v2 without token, HTTP 401)
 * - `error` is a string, other status  → RequestRejectedException, the string as violation code
 *                                         (spec: 409 "user_assigned_to_groups" on a user delete)
 * - no envelope, HTTP 401/403          → AccessDeniedException (wrong credentials; v1 also
 *                                         for an unknown domain — empty or text/plain body)
 * - 5xx, other status, unreadable body → TransportException
 * - 2xx with an empty body             → success without content (v2 deletes)
 *
 * @internal
 */
final class Envelope
{
    /** Longest part of a non-JSON body quoted in an exception message (proxies send HTML pages). */
    private const QUOTE_LIMIT = 200;

    /**
     * @throws RequestRejectedException
     * @throws AccessDeniedException
     * @throws TransportException
     */
    public static function parse(RequestInterface $request, ResponseInterface $response): JsonObject
    {
        $target = $request->getMethod() . ' ' . $request->getUri();
        $status = $response->getStatusCode();
        $content = (string) $response->getBody();
        if ($status >= 500) {
            // Myra's message (e.g. "An internal error occurred. Reference: …") helps its support
            throw new TransportException(sprintf('%s: server error (HTTP %d).%s', $target, $status, self::reasons(self::decodeObject($content))));
        }

        if ($content === '' && self::isSuccess($status)) {
            return new JsonObject([]); // e.g. v2 deletes: "only a 200 HTTP code"
        }
        $body = self::decodeObject($content);
        if ($body !== null) {
            $error = $body['error'] ?? false;
            if ($error === true) {
                throw self::rejected($target, $status, new JsonObject($body));
            }
            if (is_string($error)) {
                if ($status === 401 || $status === 403) {
                    throw new AccessDeniedException(sprintf('Access denied for %s (HTTP %d): %s', $target, $status, $error));
                }
                // e.g. 409 {"error": "user_assigned_to_groups"} (spec): a reason, not a denial
                throw new RequestRejectedException(sprintf('Myra rejected %s (HTTP %d): %s', $target, $status, $error), [new Violation('', $error, $error)]);
            }
            if (self::isSuccess($status)) {
                return new JsonObject($body);
            }
        }

        if ($status === 401 || $status === 403) {
            throw self::accessDenied($target, $status, $content);
        }
        if (self::isSuccess($status)) {
            throw new TransportException(sprintf('%s: the response is not a JSON object (HTTP %d).', $target, $status));
        }

        throw new TransportException(sprintf('%s: unexpected response (HTTP %d).', $target, $status));
    }

    /**
     * " Myra says: …" from the violations of an envelope, or nothing.
     *
     * @param array<mixed>|null $body
     */
    private static function reasons(?array $body): string
    {
        $violations = is_array($body) && is_array($body['violationList'] ?? null) ? $body['violationList'] : [];
        $messages = [];
        foreach ($violations as $violation) {
            if (is_array($violation) && is_string($violation['message'] ?? null)) {
                $messages[] = $violation['message'];
            }
        }

        return $messages === [] ? '' : ' Myra says: ' . implode('; ', $messages);
    }

    private static function isSuccess(int $status): bool
    {
        return $status >= 200 && $status < 300;
    }

    /**
     * @return array<mixed>|null null unless the content is a JSON object
     */
    private static function decodeObject(string $content): ?array
    {
        try {
            $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) && ($decoded === [] || !array_is_list($decoded)) ? $decoded : null;
    }

    private static function rejected(string $target, int $status, JsonObject $body): RequestRejectedException
    {
        $violations = array_map(
            Violation::fromJson(...),
            $body->has('violationList') ? $body->objects('violationList') : [],
        );
        $reasons = $violations === [] ? ' without details.' : ': ' . implode('; ', $violations);

        return new RequestRejectedException(sprintf('Myra rejected %s (HTTP %d)%s', $target, $status, $reasons), $violations);
    }

    private static function accessDenied(string $target, int $status, string $content): AccessDeniedException
    {
        $text = trim(substr($content, 0, self::QUOTE_LIMIT));
        if ($text === '') {
            return new AccessDeniedException(sprintf(
                'Access denied for %s (HTTP %d, empty response): the credentials are wrong, or the domain does not exist '
                . 'or is not accessible with them (API v1 answers both the same way).',
                $target,
                $status,
            ));
        }

        return new AccessDeniedException(sprintf('Access denied for %s (HTTP %d): %s', $target, $status, $text));
    }
}
