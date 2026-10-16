<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Auth;

use Psr\Http\Message\RequestInterface;

/**
 * Authenticates a request; works with API v1 and v2.
 *
 * The transport calls it as the last step, on the final request (method, URI, body), so
 * implementations may sign everything they see.
 */
interface Authentication
{
    public function authenticate(RequestInterface $request): RequestInterface;
}
