<?php

declare(strict_types=1);

namespace Gisl\Sdk;

/**
 * Options for {@see GislClient::waitForProbe()} — the bounded upload-probe
 * poll a caller runs between upload-complete and workflow-create so the
 * server sees a video's codec + duration and can admit the parallel split.
 *
 * Mirrors the TS `ProbeWaitOptions` at `packages/typescript/src/types.ts`.
 * PHP uses a cooperative {@see Cancellation} token instead of an
 * `AbortSignal` (the SDK has no `AbortSignal` analogue).
 */
final class ProbeWaitOptions
{
    /**
     * @param int|null         $timeoutMs    Overall wall-clock bound for the poll loop
     *                                       in ms (default 30000). On elapse the loop
     *                                       gives up with `reason: 'timeout'` — it never
     *                                       throws for a slow probe (caller creates anyway).
     * @param (callable(array{attempt:int, elapsedMs:int}): void)|null $onPoll
     *                                       Fires once per poll attempt (1-based `attempt`,
     *                                       wall-clock `elapsedMs` since the wait started) —
     *                                       drive an "analysing video…" UI in the gap. PHP
     *                                       cannot declare a typed `callable` property, so the
     *                                       field is `mixed` and the implementation guards with
     *                                       `is_callable` (mirrors {@see \Gisl\Sdk\WaitOptions}) —
     *                                       so `[$obj, 'method']` / invokable objects work too.
     * @param Cancellation|null $cancellation Cooperative token — cancel it to abort the
     *                                       wait (between polls) with a {@see \Gisl\Sdk\Errors\GislAbortError}.
     */
    public function __construct(
        public readonly ?int $timeoutMs = null,
        public readonly mixed $onPoll = null,
        public readonly ?Cancellation $cancellation = null,
    ) {
    }
}
