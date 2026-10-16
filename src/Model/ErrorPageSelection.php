<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Model;

use Myracloud\WebApi\Exception\InvalidArgumentException;

/**
 * `selection` of an error page write: {"www.example.com": {"500": true}}.
 *
 * @internal
 */
final class ErrorPageSelection
{
    /**
     * @param array<string, list<int>> $codesBySubdomain e.g. ['www.example.com' => [500, 503]]
     *
     * @return array<string, array<int, true>> codes as keys (JSON: {"500": true})
     *
     * @throws InvalidArgumentException for an empty selection or an unsupported code
     */
    public static function toJson(array $codesBySubdomain): array
    {
        $selection = [];
        foreach ($codesBySubdomain as $subdomain => $codes) {
            foreach ($codes as $code) {
                if (!in_array($code, ErrorPage::CODES, true)) {
                    throw new InvalidArgumentException(sprintf('Myra has no error page for code %d; supported: %s.', $code, implode(', ', ErrorPage::CODES)));
                }
                $selection[DomainName::normalize($subdomain)][$code] = true;
            }
        }
        if ($selection === []) {
            throw new InvalidArgumentException('Select at least one subdomain and error code.');
        }

        return $selection;
    }
}
