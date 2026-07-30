<?php
declare(strict_types=1);

namespace Glue\Pay;

use RuntimeException;

/**
 * A SmallPay call that reached SmallPay and came back wrong, carrying the HTTP
 * status so the caller can tell the difference between "this path doesn't
 * exist" and "this request was refused".
 *
 * The only caller that acts on it is SmallPay::post(), which retries a 404 on
 * the alternate path prefix; everything else sees a plain RuntimeException.
 */
final class SmallPayHttpError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}
