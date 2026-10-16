<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\Smoke;

/**
 * Anonymises real Myra answers in memory before anything is written (public repository!).
 *
 * Allowlist, not denylist: a string stays only if it is an ISO date or appears verbatim in
 * the public documentation ({@see Vocabulary}); every other string is replaced — IPs by
 * documentation ranges, hosts of the account's zones by example.com / example.org,
 * e-mails, URLs, paths, and the rest by "redacted-N". Ids are replaced by made-up numbers.
 * The mapping is deterministic within a run and can be seeded from earlier captures, so
 * relations survive (domain 1000 is domain 1000 in every file).
 *
 * {@see leaks()} checks a result before it is written: no replaced real value may survive,
 * and nothing may look like an e-mail, a key or a non-documentation IP. It reports kinds,
 * never values.
 */
final class Anonymizer
{
    /** @var array<array-key, string> real → fake (numeric strings become int keys) */
    private array $strings = [];
    /** @var array<int, int> real → fake */
    private array $ids = [];
    /** @var array<string, string> real zone → fake zone */
    private array $zones = [];
    /** @var array<array-key, string> every replaced real string → the key it came from (numeric strings become int keys) */
    private array $replaced = [];
    /** @var array<string, true> values this anonymiser produced: never anonymised again */
    private array $fakes = [];
    /** @var array<string, true> host labels of seeded (already public) fake values */
    private array $publicLabels = [];
    /** @var array<string, int> */
    private array $counters = [];

    public function __construct(private readonly Vocabulary $vocabulary) {}

    public function zone(string $real, string $fake): void
    {
        $real = strtolower(rtrim($real, '.'));
        $this->zones[$real] = $fake;
        $this->remember($real, $fake, 'zone');
    }

    /**
     * Pairs a real answer with its already anonymised version (same shape), so that later
     * captures reuse the same fake values.
     */
    public function seed(mixed $real, mixed $fake, string $key = ''): void
    {
        if (is_array($real) && is_array($fake)) {
            foreach ($real as $k => $value) {
                if (array_key_exists($k, $fake)) {
                    $this->seed($value, $fake[$k], is_string($k) ? $k : $key);
                }
            }

            return;
        }
        if (is_int($real) && is_int($fake) && self::isIdKey($key)) {
            $this->ids[$real] = $fake;
        } elseif (is_string($real) && is_string($fake)) {
            // labels of the published fixtures (e.g. "staging" in staging.example.com) are public
            if (preg_match('/^([\w-]+\.)+[a-z]{2,}\.?$/i', $fake) === 1) {
                foreach (explode('.', strtolower(rtrim($fake, '.'))) as $label) {
                    $this->publicLabels[$label] = true;
                }
            }
            if ($real !== $fake) {
                $this->remember($real, $fake, $key);
            }
        }
    }

    public function value(mixed $value, string $key = ''): mixed
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $k => $item) {
                $result[is_string($k) ? $this->key($k) : $k] = $this->value($item, is_string($k) ? $k : $key);
            }

            return $result;
        }
        if (is_int($value) && self::isIdKey($key)) {
            return $this->id($value);
        }
        if ($key === 'objectType') {
            return $value; // class names (RedirectVO, …): structure, not data
        }
        if (is_string($value) && in_array($key, ['fingerprint', 'serialNumber'], true) && preg_match('/^[0-9A-F]{2}(:[0-9A-F]{2})+$/i', $value) === 1) {
            return $this->hexPairs($value); // certificate hashes: fake bytes, not an IPv6 address
        }
        if (is_string($value) && ($key === 'message' || preg_match('/^[a-z]{2}$/', $key) === 1)) {
            return $this->text($value); // Myra's messages (violations, translations): prose stays, data goes
        }

        return is_string($value) ? $this->string($value, $key) : $value;
    }

    /**
     * A request path: numeric segments that are known ids, hosts and names; page numbers
     * and words of the documentation stay.
     */
    public function path(string $path): string
    {
        $route = explode('?', $path, 2)[0];
        $query = str_contains($path, '?') ? explode('?', $path, 2)[1] : null;
        // numbers below 100 are page numbers (v1 has them in the path), not ids
        $segments = array_map(
            fn(string $segment): string => preg_match('/^\d+$/', $segment) === 1
                ? ((int) $segment >= 100 && isset($this->ids[(int) $segment]) ? (string) $this->ids[(int) $segment] : $segment)
                : $this->string(rawurldecode($segment), 'path'),
            explode('/', $route),
        );
        $result = implode('/', $segments);
        if ($query === null) {
            return $result;
        }
        $pairs = [];
        foreach (explode('&', $query) as $pair) {
            if (!str_contains($pair, '=')) {
                $pairs[] = $pair; // a flag such as "flat"
                continue;
            }
            [$name, $value] = explode('=', $pair, 2);
            $values = array_map(fn(string $item): string => preg_match('/^\d+$/', $item) === 1 ? $item : $this->string(rawurldecode($item), $name), explode(',', $value));
            $pairs[] = $name . '=' . implode(',', $values);
        }

        return $result . '?' . implode('&', $pairs);
    }

    /**
     * Free text (CSV, BIND zone files): every known real value, then IPs, e-mails and hosts.
     */
    public function text(string $text): string
    {
        // only values of 4+ characters: short ones ("de", "/") would shred any text
        $known = array_filter($this->strings, static fn(string $fake, int|string $real): bool => strlen((string) $real) >= 4, ARRAY_FILTER_USE_BOTH);
        uksort($known, static fn(int|string $a, int|string $b): int => strlen((string) $b) <=> strlen((string) $a));
        $text = strtr($text, array_combine(array_map('strval', array_keys($known)), $known));
        // known ids as whole numbers, e.g. in a URL inside a message
        $text = (string) preg_replace_callback('/(?<![\w.])\d{3,}(?![\w.])/', fn(array $m): string => isset($this->ids[(int) $m[0]]) ? (string) $this->ids[(int) $m[0]] : $m[0], $text);
        $patterns = [
            '/[\w.+-]+@[\w-]+(\.[\w-]+)+/',
            '#\b(\d{1,3}\.){3}\d{1,3}(/\d{1,2})?\b#',
            '#(?<![\w:])([0-9a-f]{1,4}:){2,7}[0-9a-f:]{0,4}(/\d{1,3})?(?![\w:])#i',
            '/\b([a-z0-9-]+\.)+[a-z]{2,}\.?/i',
        ];
        foreach ($patterns as $pattern) {
            $text = (string) preg_replace_callback($pattern, fn(array $m): string => $this->string($m[0], 'text'), $text);
        }

        return $text;
    }

    /**
     * What looks leaked in an anonymised result — kinds, keys and where, never values.
     *
     * @param mixed $structure the result before encoding, to tell where a value survived
     *
     * @return list<string>
     */
    public function leaks(string $result, mixed $structure = null): array
    {
        $leaks = [];
        $number = 0;
        $strings = $structure === null ? [$result] : self::strings($structure);
        foreach ($this->replaced as $real => $key) {
            $number++;
            $real = (string) $real; // numeric strings are int array keys
            if (strlen($real) < 4 || isset($this->publicLabels[strtolower($real)])) {
                continue;
            }
            // only where it stands alone: "ADMIN" inside "ADMINISTRATOR" is no leak, "dns.ADMIN.de" is
            $pattern = '/(?<![A-Za-z0-9])' . preg_quote($real, '/') . '(?![A-Za-z0-9])/';
            foreach ($strings as $string) {
                if (preg_match($pattern, $string) === 1) {
                    $where = $structure === null ? null : self::where($structure, $real, '');
                    $leaks[] = sprintf('replaced value #%d (from "%s") still present%s', $number, $key, $where === null ? '' : ' at ' . $where);
                    break;
                }
            }
        }
        $checks = [
            'e-mail address' => '/[\w.+-]+@(?!example\.(com|org|net)\b)[\w-]+(\.[\w-]+)+/',
            'PEM block' => '/-----BEGIN /',
            // with an upper-case letter or "+": a URL path of lower-case words and numbers is no base64
            'long base64' => '#(?<![A-Za-z0-9+/])(?=[A-Za-z0-9+/]*[A-Z+])[A-Za-z0-9+/]{40,}={0,2}#',
            'long hex' => '/\b[0-9a-f]{32,}\b/i',
            'IPv4 outside documentation ranges' => '#\b(?!192\.0\.2\.|198\.51\.100\.|203\.0\.113\.)(\d{1,3}\.){3}\d{1,3}\b#',
            // hex groups with "::" or at least four colons (times like 12:00:00 have neither)
            'IPv6 outside 2001:db8::/32' => '#(?<![\w:])(?!2001:db8)(?=[0-9a-f:]*::|(?:[0-9a-f]{1,4}:){4})[0-9a-f]{0,4}(:[0-9a-f]{0,4}){2,7}(?![\w:])#i',
        ];
        foreach ($checks as $kind => $pattern) {
            if (preg_match($pattern, $result) === 1) {
                $leaks[] = $kind;
            }
        }

        return $leaks;
    }

    /**
     * Field names are structure and stay — unless they are data, as in a map keyed by host.
     */
    private function key(string $key): string
    {
        // "ALL-{domain id}": a domain with all its subdomains (statistics)
        if (preg_match('/^ALL-(\d+)$/', $key, $m) === 1) {
            return 'ALL-' . $this->id((int) $m[1]);
        }

        return preg_match('/^([\w-]+\.)+[a-z]{2,}\.?$|^[\d.:\/]+$|@/i', $key) === 1 ? $this->string($key, 'key') : $key;
    }

    /**
     * Every key and string value, except class names (objectType): numbers are not data here.
     *
     * @return list<string>
     */
    private static function strings(mixed $value, string $key = ''): array
    {
        if (is_string($value)) {
            return $key === 'objectType' ? [] : [$value];
        }
        if (!is_array($value)) {
            return [];
        }
        $strings = [];
        foreach ($value as $k => $item) {
            if (is_string($k)) {
                $strings[] = $k;
            }
            array_push($strings, ...self::strings($item, is_string($k) ? $k : $key));
        }

        return $strings;
    }

    /**
     * The path of the first key or string containing $needle.
     */
    private static function where(mixed $value, string $needle, string $path): ?string
    {
        if (is_string($value)) {
            // the anonymised context with the leaked value masked: shows where, never what
            return str_contains($value, $needle) ? sprintf('%s: "%s"', $path, substr(str_replace($needle, '«LEAK»', $value), 0, 90)) : null;
        }
        if (!is_array($value)) {
            return null;
        }
        foreach ($value as $key => $item) {
            $child = is_int($key) ? $path . '[' . $key . ']' : ($path === '' ? $key : $path . '.' . $key);
            if (is_string($key) && str_contains($key, $needle)) {
                return $child . ' (the key: "' . str_replace($needle, '«LEAK»', $key) . '")';
            }
            $found = self::where($item, $needle, $child);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function string(string $value, string $key): string
    {
        if ($value === '' || isset($this->fakes[$value]) || isset($this->strings[$value])) {
            return $this->strings[$value] ?? $value;
        }
        // generic or ours: dates, documented words, the root path, single characters and the
        // probes' own values (/__probe__/…) carry no customer data
        // Myra's violation codes (INTERNAL_ERROR, DOMAIN_DOES_NOT_EXIST, …): consumers read them
        if ($key === 'code' && preg_match('/^[A-Z][A-Z0-9]*(_[A-Z0-9]+)+$/', $value) === 1) {
            return $value;
        }
        if (self::isDate($value) || $this->vocabulary->has($value) || strlen($value) <= 1 || str_starts_with($value, '/__probe__/') || (str_starts_with($value, '__probe__') && !str_contains($value, '.')) || self::isDocumentationIp($value)) {
            return $value;
        }
        $fake = match (true) {
            // "ALL:{domain}": the domain with all its subdomains (statistics)
            preg_match('/^ALL:(([a-z0-9_-]+\.)+[a-z]{2,})$/i', $value, $m) === 1 => 'ALL:' . $this->host($m[1]),
            preg_match('#^(\d{1,3}\.){3}\d{1,3}(/\d{1,2})?$#', $value) === 1 => $this->ipv4($value),
            preg_match('#^[0-9a-f:]+(/\d{1,3})?$#i', $value) === 1 && substr_count($value, ':') >= 2 => $this->ipv6($value),
            preg_match('/^[\w.+-]+@[\w-]+(\.[\w-]+)+$/', $value) === 1 => 'user' . $this->next('email') . '@example.com',
            preg_match('#^https?://#i', $value) === 1 => $this->url($value),
            preg_match('/^([a-z0-9_-]+\.)+[a-z]{2,}\.?$/i', $value) === 1 => $this->host($value),
            str_starts_with($value, '/') => '/path-' . $this->next('path'),
            default => 'redacted-' . $this->next('string'),
        };
        $this->remember($value, $fake, $key);

        return $fake;
    }

    private function host(string $host): string
    {
        $dot = str_ends_with($host, '.') ? '.' : '';
        $name = strtolower(rtrim($host, '.'));
        foreach ($this->zones as $real => $fake) {
            if ($name === $fake || str_ends_with($name, '.' . $fake)) {
                return $host; // already anonymised
            }
            if ($name === $real) {
                return $fake . $dot;
            }
            if (str_ends_with($name, '.' . $real)) {
                return $this->labels(substr($name, 0, -strlen($real) - 1)) . '.' . $fake . $dot;
            }
        }
        if ($this->vocabulary->has($name)) {
            return $host;
        }
        $labels = explode('.', $name);
        // documented words joined by dots are no host: e.g. the path upstreamOptions.weight in a message
        if (array_filter(explode('.', rtrim($host, '.')), fn(string $label): bool => !$this->vocabulary->has($label)) === []) {
            return $host;
        }
        // a documented suffix stays, e.g. Myra's CDN zone ax4z.com in alternative CNAMEs
        for ($i = 1; $i < count($labels) - 1; $i++) {
            $suffix = implode('.', array_slice($labels, $i));
            if ($this->vocabulary->has($suffix)) {
                return $this->labels(implode('.', array_slice($labels, 0, $i))) . '.' . $suffix . $dot;
            }
        }
        $tld = array_pop($labels);

        return $this->labels(implode('.', $labels)) . '.' . ($this->vocabulary->has($tld) ? $tld : 'example') . $dot;
    }

    private function labels(string $labels): string
    {
        return implode('.', array_map(function (string $label): string {
            // documented, published, or the probes' own labels
            if ($this->vocabulary->has($label) || $this->vocabulary->mentions($label) || isset($this->publicLabels[$label]) || in_array($label, ['__probe__', 'myra-web-api-probe'], true)) {
                return $label;
            }
            if (!isset($this->strings[$label])) {
                $this->remember($label, 'host' . $this->next('label'), 'label');
            }

            return $this->strings[$label];
        }, explode('.', $labels)));
    }

    private function url(string $url): string
    {
        $parts = parse_url($url);
        $host = is_array($parts) && isset($parts['host']) ? $this->host($parts['host']) : 'example.com';
        $path = is_array($parts) && isset($parts['path']) ? $parts['path'] : '/';
        if ($path !== '/' && !str_starts_with($path, '/__probe__/')) {
            $path = '/path-' . $this->next('path'); // a probe's own path stays readable, the host never
        }

        return (is_array($parts) && isset($parts['scheme']) ? $parts['scheme'] : 'https') . '://' . $host . $path;
    }

    private function ipv4(string $ip): string
    {
        [, $prefix] = array_pad(explode('/', $ip, 2), 2, null);
        $n = $this->next('ipv4');

        return ($n < 150 ? '192.0.2.' . (100 + $n) : '198.51.100.' . ($n - 150)) . ($prefix === null ? '' : '/' . $prefix);
    }

    private function ipv6(string $ip): string
    {
        [, $prefix] = array_pad(explode('/', $ip, 2), 2, null);

        return '2001:db8::' . dechex(256 + $this->next('ipv6')) . ($prefix === null ? '' : '/' . $prefix);
    }

    /**
     * Same number of colon-separated bytes, counted up: …:00:01, …:00:02
     */
    private function hexPairs(string $value): string
    {
        if (!isset($this->strings[$value])) {
            $bytes = substr_count($value, ':') + 1;
            $fake = str_pad(strtoupper(dechex($this->next('hex'))), 2 * $bytes, '0', STR_PAD_LEFT);
            $this->remember($value, implode(':', str_split($fake, 2)), 'hex');
        }

        return $this->strings[$value];
    }

    private function id(int $id): int
    {
        return $this->ids[$id] ??= 90000 + $this->next('id');
    }

    private function next(string $kind): int
    {
        return $this->counters[$kind] = ($this->counters[$kind] ?? 0) + 1;
    }

    private function remember(string $real, string $fake, string $key): void
    {
        $this->strings[$real] = $fake;
        $this->replaced[$real] = $key;
        $this->fakes[$fake] = true;
    }

    private static function isIdKey(string $key): bool
    {
        return preg_match('/(^id$|Id$|ID$|Ids$)/', $key) === 1;
    }

    /**
     * 192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24, 2001:db8::/32 — never real (RFC 5737, 3849).
     */
    private static function isDocumentationIp(string $value): bool
    {
        return preg_match('#^(192\.0\.2|198\.51\.100|203\.0\.113)\.\d{1,3}(/\d{1,2})?$|^2001:db8:[0-9a-f:]*(/\d{1,3})?$#i', $value) === 1;
    }

    private static function isDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?)?$/', $value) === 1;
    }
}
