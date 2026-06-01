<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * Thrown by the file-first {@see \Gisl\Sdk\Ergonomic\Handle::result()} (FF5a)
 * when the workflow has not yet reached a terminal state. `result()` is the
 * NON-blocking accessor: it fetches the current status once and, if the
 * workflow is still `pending`/`in_progress`, throws this rather than waiting.
 * Use {@see \Gisl\Sdk\Ergonomic\Handle::wait()} to block until terminal
 * instead.
 *
 * Carries the `$workflowId` and the current (non-terminal) `$state`.
 *
 * Mirrors the TS `GislResultNotReadyError` in
 * `packages/typescript/src/errors.ts`.
 */
final class GislResultNotReadyError extends GislError
{
    public function __construct(
        public readonly string $workflowId,
        public readonly string $state,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            "Workflow {$workflowId} is not ready (state '{$state}'); its result is not available yet. "
                . 'Call wait() to block until it reaches a terminal state, or poll result() again later.',
            0,
            $previous,
        );
    }

    public function getWorkflowId(): string
    {
        return $this->workflowId;
    }

    public function getState(): string
    {
        return $this->state;
    }
}
