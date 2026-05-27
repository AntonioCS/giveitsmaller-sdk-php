<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

/**
 * Call-time options for {@see OperationBuilder::run()}.
 *
 * `maxWait` is MANDATORY (no default). The underlying poll path in
 * {@see \Gisl\Sdk\GislClient::waitForWorkflow()} has a 300_000 ms
 * default that would otherwise leak silently — the ergonomic layer
 * makes the deadline a conscious caller choice. Accepts:
 *  - `int` (milliseconds)
 *  - `string` with suffix: `'500ms'`, `'120s'`, `'30m'`, `'2h'`
 *
 * `signal`-style cancellation is intentionally absent in this scaffold —
 * PHP has no AbortSignal analogue, and the existing
 * {@see \Gisl\Sdk\UploadOptions} docblock documents the same scoping:
 * a cancellation primitive lands in VOxtu0RZ-B2 / a follow-up. Until
 * then, the only abort signal is the wall-clock `maxWait` deadline.
 *
 * Mirrors the TS `RunOptions` interface at
 * `packages/typescript/src/builder.ts:218-240`. PHP-idiomatic
 * adjustment: `useSSE` defaults to `true` to preserve cross-language
 * parity even though the PHP SSE generator cannot be interrupted
 * mid-frame (the deadline check fires between frames + the poll
 * fallback is reachable).
 */
final class RunOptions
{
    /**
     * @param int|string                                              $maxWait        Mandatory deadline (see class docblock).
     * @param (callable(ProgressEvent $event): void)|null             $onProgress     Receives synthesised progress union.
     * @param bool                                                    $useSSE         Default `true`; set false to force poll fallback.
     * @param int|null                                                $pollIntervalMs Override the poll-fallback interval (ms).
     */
    public function __construct(
        public readonly int|string $maxWait,
        public readonly mixed $onProgress = null,
        public readonly bool $useSSE = true,
        public readonly ?int $pollIntervalMs = null,
    ) {
    }
}
