<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * Transport-level failure: PSR-18 client could not produce a response (DNS,
 * TCP, TLS, mid-stream EOF). Wraps the underlying PSR-18
 * `\Psr\Http\Client\NetworkExceptionInterface` (or any other transport
 * exception) as `$previous`.
 *
 * Mirrors `packages/typescript/src/errors.ts:GislNetworkError`.
 */
final class GislNetworkError extends GislError
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
