<?php
declare(strict_types=1);

namespace Myracloud\WebApi\Exception;

use GuzzleHttp\Exception\GuzzleException;

/**
 * The API answered, but not with a 200 and a JSON body.
 *
 * Replaces the bare GuzzleHttp\Exception\TransferException thrown here before: Guzzle 8 requires
 * the failing request as its second constructor argument, and the response handler has none.
 * It implements GuzzleException, so `catch (GuzzleException)` keeps catching it under Guzzle 7 and 8.
 */
final class UnexpectedResponseException extends \RuntimeException implements GuzzleException
{
}
