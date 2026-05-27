<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

/**
 * Lighter return value from {@see OperationBuilder::submit()} — no
 * SSE/poll wait, no downloads request; the caller reconciles completion
 * via the webhook delivered to the URL passed in
 * {@see SubmitOptions::$webhook}.
 *
 * `webhookSecret` is the verifier seed for HMAC-signature checking of
 * the inbound webhook payload (per server contract). It is only present
 * when the server returned one — callers SHOULD treat its absence as a
 * non-fatal omission rather than an authentication error.
 *
 * Mirrors the TS `Handle` interface at
 * `packages/typescript/src/builder.ts:169-172`.
 */
final class Handle
{
    public function __construct(
        public readonly string $workflowId,
        public readonly ?string $webhookSecret = null,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $out = ['workflowId' => $this->workflowId];
        if ($this->webhookSecret !== null) {
            $out['webhookSecret'] = $this->webhookSecret;
        }
        return $out;
    }
}
