<?php

declare(strict_types=1);

namespace Gisl\Sdk;

/**
 * Cooperative cancellation token for the ergonomic builders' blocking calls
 * ({@see \Gisl\Sdk\Ergonomic\OperationBuilder::run()} / `submit()`,
 * {@see \Gisl\Sdk\Ergonomic\MergeBuilder}, {@see \Gisl\Sdk\FileFirst\FilesRecipe::run()},
 * {@see \Gisl\Sdk\Ergonomic\Handle::wait()}). Pass an instance via
 * {@see \Gisl\Sdk\Ergonomic\RunOptions::$cancellation} /
 * {@see \Gisl\Sdk\Ergonomic\SubmitOptions::$cancellation}, then call
 * {@see cancel()} from elsewhere (e.g. a `pcntl` signal handler or surrounding
 * control flow) to abort the call early — the SDK throws
 * {@see \Gisl\Sdk\Errors\GislAbortError} instead of running out the `maxWait`
 * deadline.
 *
 * This is the PHP analogue of the TS SDK's `AbortSignal` (threaded through
 * `run`/`submit`/`wait` in `packages/typescript/src/builder.ts`). PHP has no
 * `AbortSignal`/`AbortController` pair and no event loop, so the controller and
 * signal roles collapse into this single object and cancellation is
 * **cooperative + between-steps**: the SDK polls {@see isCancelled()} at the
 * same boundaries it checks the wall-clock `maxWait` deadline (before/after each
 * upload, before workflow creation, and between each SSE frame / poll
 * iteration). An in-flight HTTP transfer is NOT interrupted mid-request — that
 * would require curl progress callbacks and is tracked separately (VOxtu0RZ-B4).
 *
 * The mutable `cancel()` flag is the one intentional exception to the SDK's
 * "readonly DTO" house style: a cancellation token is inherently a one-way
 * state transition, and a bare `\Closure(): bool` predicate (the alternative)
 * is less discoverable and untyped at the option boundary.
 */
final class Cancellation
{
    private bool $cancelled = false;

    /**
     * Request cancellation. Idempotent and one-way — once cancelled, a token
     * never reverts. Safe to call from a signal handler.
     */
    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}
