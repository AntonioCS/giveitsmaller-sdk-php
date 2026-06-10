<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * A blocking ergonomic call ({@see \Gisl\Sdk\Ergonomic\OperationBuilder::run()},
 * `submit()`, {@see \Gisl\Sdk\Ergonomic\Handle::wait()}, etc.) was cancelled via
 * a {@see \Gisl\Sdk\Cancellation} token before it reached a terminal state.
 * Distinct from {@see GislTimeoutError} (the `maxWait` wall-clock deadline) —
 * this is an explicit caller-requested abort.
 *
 * Mirrors `packages/typescript/src/errors.ts:GislAbortError` (the SDK's
 * normalised shape for the TS `AbortError` raised off an `AbortSignal`).
 */
final class GislAbortError extends GislError
{
}
