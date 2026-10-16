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

    /**
     * The JSON body the capture sent.
     */
    public static function requestBody(string $name): string
    {
        return json_encode(self::load($name)['request']['body'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * A request body the capture sent as text (e.g. CSV).
     */
    public static function requestText(string $name): string
    {
        $body = self::load($name)['request']['body'];

        return is_string($body) ? $body : throw new LogicException($name . ': the request body is no text');
    }

    /**
     * A synthetic list answer, for paging beyond what the captures contain.
     *
     * @param string $key v1 `list`, v2 `data`
     * @param list<array<string, mixed>> $items
     */
    public static function page(string $key, array $items, int $page, int $pageSize, int $count): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'error' => false, 'violationList' => [], 'warningList' => [],
            $key => $items, 'page' => $page, 'count' => $count, 'pageSize' => $pageSize,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    public static function domain(int $id, string $name): array
    {
        return ['objectType' => 'DomainVO', 'id' => $id, 'name' => $name];
    }

    public static function response(string $name): Response
    {
        $response = self::load($name)['response'];
        $body = is_string($response['body']) ? $response['body'] : json_encode($response['body'], JSON_THROW_ON_ERROR);
        $headers = $response['contentType'] === null ? [] : ['Content-Type' => $response['contentType']];

        return new Response($response['status'], $headers, $body);
    }
}
