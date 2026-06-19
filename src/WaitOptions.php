<?php

declare(strict_types=1);

namespace Gisl\Sdk;

/**
 * Options for {@see GislClient::waitForWorkflow()}.
 *
 * Mirrors the TS `WaitOptions` shape at `packages/typescript/src/client.ts`.
 * All fields are optional — when null, the SDK falls back to
 * {@see WorkflowConstants::DEFAULT_POLL_INTERVAL_MS} and
 * {@see WorkflowConstants::DEFAULT_POLL_TIMEOUT_MS}.
 *
 *   - $intervalMs: pause between polls. PHP `usleep` is real-time, so very
 *     small values (e.g. 0) make sense in tests but never in production.
 *   - $timeoutMs:  overall deadline for the polling loop. When the next
 *     interval would push the total elapsed past this value, the SDK throws
 *     {@see \Gisl\Sdk\Errors\GislTimeoutError}.
 *   - $onPoll:     fired once per polling cycle (including the very first
 *     status fetch) with the current status string. Useful for surfacing
 *     "still pending..." UI without re-implementing the poll loop.
 *   - $capability: anonymous-workflow capability token (the `cap` from the
 *     anonymous workflow-create response), forwarded to each underlying
 *     status poll as the `X-Workflow-Capability` header. Required to poll a
 *     null-owner workflow without a session; null for authenticated polling.
 *
 * The callable signature is `function(string $status): void`. PHP can't
 * declare a typed `callable(string): void` property, so the field is
 * `mixed` and the implementation guards with `is_callable`.
 */
final class WaitOptions
{
    public function __construct(
        public readonly ?int $intervalMs = null,
        public readonly ?int $timeoutMs = null,
        public readonly mixed $onPoll = null,
        public readonly ?string $capability = null,
    ) {
    }
}
