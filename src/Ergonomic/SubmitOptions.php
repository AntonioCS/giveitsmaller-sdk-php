<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

/**
 * Call-time options for {@see OperationBuilder::submit()}.
 *
 * `webhook` wires to `WorkflowCreatePayload::$callbackUrl`. The server
 * delivers a single POST to that URL on terminal status (completed /
 * failed / partially_failed / cancelled / expired) with an HMAC-signed
 * payload — verify via {@see \Gisl\Sdk\Webhook::verify()}.
 *
 * Mirrors the TS `SubmitOptions` interface at
 * `packages/typescript/src/builder.ts:242-245`.
 */
final class SubmitOptions
{
    public function __construct(
        public readonly string $webhook,
    ) {
    }
}
