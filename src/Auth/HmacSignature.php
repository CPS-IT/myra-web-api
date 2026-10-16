<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Auth;

use Myracloud\WebApi\Exception\InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;
use SensitiveParameter;
use SensitiveParameterValue;

/**
 * API key + secret ("API access" → "API Keys" in the Myra App), for API v1 and v2.
 *
 * The algorithm is the one in the v2 spec (docs/v2/openapi.json, info.description §2.1.2)
 * and in 1.x, byte for byte:
 *
 *     signing string = md5(body) # METHOD # path?query # application/json # date
 *     date key       = HMAC-SHA256(date, "MYRA" . secret)               (hex)
 *     signing key    = HMAC-SHA256("myra-api-request", date key)       (hex)
 *     signature      = base64(HMAC-SHA512(signing string, signing key)) (binary)
 *     Authorization: MYRA {key}:{signature}
 *
 * The date is taken per request: a signature is valid for that request only, so there is
 * nothing to cache or to renew.
 */
final readonly class HmacSignature implements Authentication
{
    private const CONTENT_TYPE = 'application/json';

    private string $apiKey;
    /** Hidden from var_dump, var_export, print_r and stack traces; serialize() throws. */
    private SensitiveParameterValue $secret;

    /**
     * Surrounding whitespace is removed from key and secret (values from env files or
     * configuration often carry a newline), as in 1.x.
     *
     * @throws InvalidArgumentException if key or secret is empty
     */
    public function __construct(
        string $apiKey,
        #[SensitiveParameter]
        string $secret,
        private ClockInterface $clock = new SystemClock(),
    ) {
        $apiKey = trim($apiKey);
        $secret = trim($secret);
        if ($apiKey === '' || $secret === '') {
            throw new InvalidArgumentException('API key and secret must not be empty.');
        }
        $this->apiKey = $apiKey;
        $this->secret = new SensitiveParameterValue($secret);
    }

    /**
     * @throws InvalidArgumentException if the body cannot be read without consuming it
     */
    public function authenticate(RequestInterface $request): RequestInterface
    {
        $date = $this->clock->now()->format(DATE_RFC3339);
        $signingString = implode('#', [
            md5($this->readBody($request)),
            strtoupper($request->getMethod()),
            self::signedPath($request->getUri()),
            self::CONTENT_TYPE,
            $date,
        ]);

        $secret = $this->secret->getValue();
        // The intermediate keys are hex strings, not raw bytes (CryptoJS semantics, see the spec).
        $dateKey = hash_hmac('sha256', $date, 'MYRA' . $secret);
        $signingKey = hash_hmac('sha256', 'myra-api-request', $dateKey);
        $signature = base64_encode(hash_hmac('sha512', $signingString, $signingKey, true));

        return $request
            ->withHeader('Content-Type', self::CONTENT_TYPE)
            ->withHeader('Date', $date)
            ->withHeader('Authorization', 'MYRA ' . $this->apiKey . ':' . $signature);
    }

    /**
     * The exact bytes that are sent; the stream is rewound so the client sends all of them.
     */
    private function readBody(RequestInterface $request): string
    {
        $body = $request->getBody();
        if (!$body->isSeekable()) {
            throw new InvalidArgumentException('The request body must be seekable to be signed.');
        }
        $body->rewind();
        $content = $body->getContents();
        $body->rewind();

        return $content;
    }

    private static function signedPath(UriInterface $uri): string
    {
        $query = $uri->getQuery();

        return $uri->getPath() . ($query === '' ? '' : '?' . $query);
    }
}
