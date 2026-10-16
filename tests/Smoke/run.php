<?php

declare(strict_types=1);

/**
 * Smoke test against the REAL Myra API — read-only, run by hand before a release:
 *
 *     cp tests/Smoke/smoke.env.dist .smoke.env   # fill in, never commit (.gitignore)
 *     php tests/Smoke/run.php
 *
 * Every version × auth combination through this package and the default Guzzle client.
 * It confirms what no local test can: HMAC against v2 (incl. a query string, `?page=2`),
 * Bearer against v1, and it records the raw answers to .cache/smoke/ — real data
 * (origin IPs!): anonymise before anything goes into docs/captures or tests/Fixtures.
 */

use GuzzleHttp\Client as Guzzle;
use Myracloud\WebApi\Auth\Authentication;
use Myracloud\WebApi\Auth\BearerToken;
use Myracloud\WebApi\Auth\HmacSignature;
use Myracloud\WebApi\Contract\Client;
use Myracloud\WebApi\Http\Transport;
use Myracloud\WebApi\V1;
use Myracloud\WebApi\V2;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

require __DIR__ . '/../../vendor/autoload.php';

$root = dirname(__DIR__, 2);
// NAME=value per line, split at the first "=" (secrets may contain "=" and other INI
// specials); optional surrounding quotes; lines starting with ";" or "#" are comments.
$ini = [];
foreach (is_file($root . '/.smoke.env') ? (array) file($root . '/.smoke.env', FILE_IGNORE_NEW_LINES) : [] as $line) {
    $line = trim((string) $line);
    if ($line === '' || $line[0] === ';' || $line[0] === '#' || !str_contains($line, '=')) {
        continue;
    }
    [$name, $value] = explode('=', $line, 2);
    $value = trim($value);
    if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
        $value = substr($value, 1, -1);
    }
    $ini[trim($name)] = $value;
}
$env = static fn(string $name): string => $ini[$name] ?? '';
$domain = $env('MYRA_DOMAIN');
if ($domain === '') {
    fwrite(STDERR, "Missing .smoke.env (copy tests/Smoke/smoke.env.dist and fill it in).\n");
    exit(2);
}
$out = $root . '/.cache/smoke';
is_dir($out) || mkdir($out, 0o700, true);

/** Records every answer unchanged (status, content type, body) next to the request line. */
$recorder = new class ($out) implements ClientInterface {
    private int $count = 0;
    private Guzzle $guzzle;

    public function __construct(private readonly string $dir)
    {
        $this->guzzle = new Guzzle(Transport::defaultOptions());
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $response = $this->guzzle->sendRequest($request);
        $name = sprintf('%s/%02d-%s.json', $this->dir, ++$this->count, preg_replace('/[^a-z0-9]+/i', '-', $request->getMethod() . $request->getUri()->getPath() . '-' . $request->getUri()->getQuery()));
        file_put_contents($name, json_encode([
            'request' => ['method' => $request->getMethod(), 'path' => $request->getRequestTarget(), 'auth' => strtok($request->getHeaderLine('Authorization'), ' ')],
            'response' => ['status' => $response->getStatusCode(), 'contentType' => $response->getHeaderLine('Content-Type') === '' ? null : $response->getHeaderLine('Content-Type'), 'body' => (string) $response->getBody()],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $response->getBody()->rewind();

        return $response;
    }
};

$auths = [];
if ($env('MYRA_KEY') !== '' && $env('MYRA_SECRET') !== '') {
    $auths['HMAC'] = static fn(): Authentication => new HmacSignature($env('MYRA_KEY'), $env('MYRA_SECRET'));
}
if ($env('MYRA_TOKEN') !== '') {
    $auths['token'] = static fn(): Authentication => new BearerToken($env('MYRA_TOKEN'));
}

$failed = 0;
$check = static function (string $label, Closure $call) use (&$failed): void {
    try {
        $result = $call();
        printf("  ok    %-48s %s\n", $label, is_string($result) ? $result : '');
    } catch (Throwable $e) {
        $failed++;
        printf("  FAIL  %-48s %s: %s\n", $label, $e::class, $e->getMessage());
    }
};

foreach ($auths as $authName => $auth) {
    foreach (['v1' => static fn(): Client => new V1\Client($auth(), httpClient: $recorder), 'v2' => static fn(): Client => new V2\Client($auth(), httpClient: $recorder)] as $version => $client) {
        echo "$version, $authName\n";
        $c = $client();
        $check('domains, page 1', static fn(): string => count($c->domains()->page(1)->items()) . ' domains');
        $check('domains, page 2 (v2: query string)', static fn(): string => count($c->domains()->page(2)->items()) . ' domains');
        $check("dns records of $domain", static fn(): string => count($c->dnsRecords()->list($domain)) . ' records');
    }
}

echo $failed === 0 ? "All passed. Raw answers: .cache/smoke/\n" : "$failed failed. Raw answers: .cache/smoke/\n";
exit($failed === 0 ? 0 : 1);
