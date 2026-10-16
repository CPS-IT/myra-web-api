<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Tests\Smoke;

use RuntimeException;

/**
 * Every token of the public documentation (v1 manual, v2 spec): field names, enum values,
 * generic words. A captured string that appears verbatim here is public by definition and
 * may stay in an anonymised capture; everything else is replaced.
 */
final class Vocabulary
{
    /** @var array<string, true> */
    private array $tokens = [];
    private string $text;

    /**
     * @param list<string> $texts
     */
    public function __construct(array $texts)
    {
        $this->text = implode("\n", $texts);
        foreach ($texts as $text) {
            preg_match_all('#[A-Za-z0-9_][A-Za-z0-9_.\-/]*[A-Za-z0-9_]|[A-Za-z0-9_]#', $text, $matches);
            foreach ($matches[0] as $token) {
                $this->tokens[$token] = true;
                // path words ("/{language}/rapi/domains" documents "rapi") and labels of documented
                // hosts ("dns.example.de" documents "dns", "example", "de")
                $parts = preg_split('#[/.]#', $token);
                foreach ($parts === false ? [] : $parts as $part) {
                    $this->tokens[$part] = true;
                }
            }
        }
    }

    public static function fromDocs(string $root): self
    {
        $texts = [];
        foreach (['/docs/v1/Myra_Web_API_Documentation_EN.txt', '/docs/v2/openapi.json'] as $file) {
            $text = file_get_contents($root . $file);
            if ($text === false) {
                throw new RuntimeException('Missing ' . $file);
            }
            // the spec is JSON: decode escapes such as < so its words are plain text
            $texts[] = str_ends_with($file, '.json') ? (string) json_encode(json_decode($text), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $text;
        }

        return new self($texts);
    }

    /**
     * Appears anywhere in the documentation (at least 4 characters), e.g. a label of a
     * documented host such as Myra's name servers.
     */
    public function mentions(string $value): bool
    {
        return strlen($value) >= 4 && str_contains($this->text, $value);
    }

    /**
     * A documented token — or an upper-case phrase of documented tokens ("NOT REGEX"); prose
     * is never kept, even if every word of it is documented.
     */
    public function has(string $value): bool
    {
        if (isset($this->tokens[$value])) {
            return true;
        }
        if (preg_match('/^[A-Z0-9_]+( [A-Z0-9_]+)+$/', $value) !== 1) {
            return false;
        }
        foreach (explode(' ', $value) as $word) {
            if (!isset($this->tokens[$word])) {
                return false;
            }
        }

        return true;
    }
}
