<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\Smoke;

use Closure;
use RuntimeException;

/**
 * Shared by the scripts against the real API: credentials and the seeded anonymiser.
 */
final class SmokeSession
{
    /**
     * .smoke.env in the project root (git-ignored): NAME="value" per line.
     *
     * @return Closure(string): string
     */
    public static function env(string $root): Closure
    {
        $ini = is_file($root . '/.smoke.env') ? parse_ini_file($root . '/.smoke.env') : false;

        return static function (string $name) use ($ini): string {
            $value = is_array($ini) ? ($ini[$name] ?? '') : '';

            return is_string($value) ? trim($value) : '';
        };
    }

    /**
     * An anonymiser that maps to the same fake values as the existing fixtures: the raw
     * answers of the last smoke run (.cache/smoke) are paired with them field by field.
     */
    public static function anonymizer(string $root): Anonymizer
    {
        $anonymizer = new Anonymizer(Vocabulary::fromDocs($root));
        $seeds = [['01-GET-en-rapi-domains-1-', 'v1/domains/list'], ['04-GET-domains-', 'v2/domains/list']];
        foreach (['*-GET-en-rapi-dnsRecords-*-1-.json' => 'v1/dns-records/list', '*-GET-domain-*-dns-records-.json' => 'v2/dns-records/list'] as $pattern => $fixture) {
            $files = glob($root . '/.cache/smoke/' . $pattern);
            if ($files !== false && $files !== []) {
                $seeds[] = [basename($files[0], '.json'), $fixture];
            }
        }
        foreach ($seeds as [$smoke, $fixture]) {
            $file = $root . '/.cache/smoke/' . $smoke . '.json';
            if (!is_file($file)) {
                throw new RuntimeException("Missing $file — run tests/Smoke/run.php first (it records the seed answers).");
            }
            $real = self::body(self::json($file));
            $fake = self::body(self::json(\CPSIT\MyraApiEmulator\Paths::fixtures() . '/' . $fixture . '.json'));
            if (str_ends_with($fixture, 'domains/list') && is_array($real) && is_array($fake)) {
                $realItems = $real['list'] ?? $real['data'] ?? [];
                $fakeItems = $fake['list'] ?? $fake['data'] ?? [];
                foreach (is_array($realItems) ? $realItems : [] as $i => $domain) {
                    $fakeDomain = is_array($fakeItems) ? ($fakeItems[$i] ?? null) : null;
                    if (is_array($domain) && is_array($fakeDomain) && is_string($domain['name'] ?? null) && is_string($fakeDomain['name'] ?? null)) {
                        $anonymizer->zone($domain['name'], $fakeDomain['name']);
                    }
                }
            }
            $anonymizer->seed($real, $fake);
        }

        return $anonymizer;
    }

    /**
     * @return array<mixed>
     */
    private static function json(string $file): array
    {
        $content = file_get_contents($file);
        $data = json_decode($content === false ? '' : $content, true);

        return is_array($data) ? $data : throw new RuntimeException('Not JSON: ' . $file);
    }

    /**
     * @param array<mixed> $capture
     */
    private static function body(array $capture): mixed
    {
        $response = $capture['response'] ?? null;
        $body = is_array($response) ? ($response['body'] ?? null) : null;

        return is_string($body) ? json_decode($body, true) : $body;
    }
}
