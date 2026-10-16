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
 * body decides, not the status (see docs/captures/README.md):
 *
 * - envelope with `error: true`        → RequestRejectedException (violations)
 * - `error` is a string                → AccessDeniedException (v2 without token, HTTP 401)
 * - no envelope, HTTP 401/403          → AccessDeniedException (wrong credentials; v1 also
 *                                         for an unknown domain — empty or text/plain body)
 * - 5xx, other status, unreadable body → TransportException
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
        if ($status >= 500) {
            throw new TransportException(sprintf('%s: server error (HTTP %d).', $target, $status));
        }

        $content = (string) $response->getBody();
        $body = self::decodeObject($content);
        if ($body !== null) {
            $error = $body['error'] ?? false;
            if ($error === true) {
                throw self::rejected($target, $status, new JsonObject($body));
            }
            if (is_string($error)) {
                throw new AccessDeniedException(sprintf('Access denied for %s (HTTP %d): %s', $target, $status, $error));
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
