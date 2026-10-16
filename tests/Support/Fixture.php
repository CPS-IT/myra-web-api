<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\Support;

use GuzzleHttp\Psr7\Response;
use LogicException;

/**
 * Real Myra answers (tests/Fixtures, copied from docs/captures): `request` as sent and
 * `response` as received.
 */
final class Fixture
{
    /**
     * @param string $name e.g. "v1/errors/wrong-credentials"
     *
     * @return array{request: array{method: string, path: string, body: mixed}, response: array{status: int, contentType: string|null, body: mixed}}
     */
    public static function load(string $name): array
    {
        $file = __DIR__ . '/../Fixtures/' . $name . '.json';
        $json = file_get_contents($file);
        if ($json === false) {
            throw new LogicException('Missing fixture ' . $file);
        }
        /** @var array{request: array{method: string, path: string, body: mixed}, response: array{status: int, contentType: string|null, body: mixed}} */
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    public static function response(string $name): Response
    {
        $response = self::load($name)['response'];
        $body = is_string($response['body']) ? $response['body'] : json_encode($response['body'], JSON_THROW_ON_ERROR);
        $headers = $response['contentType'] === null ? [] : ['Content-Type' => $response['contentType']];

        return new Response($response['status'], $headers, $body);
    }
}
