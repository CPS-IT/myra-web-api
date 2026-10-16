<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Exception;

use RuntimeException;

/**
 * API v2: the domain name is not in the account's domain list, so it has no id to address
 * it by. (API v1 cannot tell this apart from wrong credentials: {@see AccessDeniedException}.)
 */
final class DomainNotFoundException extends RuntimeException implements MyraException {}
