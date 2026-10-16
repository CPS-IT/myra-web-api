<?php

declare(strict_types=1);

namespace Myracloud\WebApi\Exception;

use Throwable;

/**
 * Every exception this package throws implements this interface; consumers catch only this.
 */
interface MyraException extends Throwable {}
