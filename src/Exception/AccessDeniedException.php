<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Exception;

use RuntimeException;

/**
 * Myra refused the request without saying why in an envelope: wrong or missing credentials,
 * a revoked token — or, in API v1, a domain that does not exist or is not accessible with
 * these credentials (v1 answers both with the same empty HTTP 403).
 */
final class AccessDeniedException extends RuntimeException implements MyraException {}
