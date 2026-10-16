<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Auth;

use Myracloud\WebApi\Exception\InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use SensitiveParameter;
use SensitiveParameterValue;

/**
 * API token ("API access" → "API Tokens" in the Myra App), for API v1 and v2.
 */
final readonly class BearerToken implements Authentication
{
    /** Hidden from var_dump, var_export, print_r and stack traces; serialize() throws. */
    private SensitiveParameterValue $token;

    /**
     * @throws InvalidArgumentException if the token is empty
     */
    public function __construct(#[SensitiveParameter] string $token)
    {
        $token = trim($token);
        if ($token === '') {
            throw new InvalidArgumentException('The API token must not be empty.');
        }
        $this->token = new SensitiveParameterValue($token);
    }

    public function authenticate(RequestInterface $request): RequestInterface
    {
        $token = $this->token->getValue();

        return $request->withHeader('Authorization', 'Bearer ' . $token);
    }
}
