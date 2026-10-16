<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Exception;

/**
 * Invalid input before any request is sent, e.g. empty credentials from an unset configuration.
 */
final class InvalidArgumentException extends \InvalidArgumentException implements MyraException {}
