<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

use Gisl\Sdk\Cancellation;

/**
 * Call-time options for {@see OperationBuilder::submit()}.
 *
 * `webhook` wires to `WorkflowCreatePayload::$callbackUrl`. The server
 * delivers a single POST to that URL on terminal status (completed /
 * failed / partially_failed / cancelled / expired) with an HMAC-signed
 * payload — verify via {@see \Gisl\Sdk\Webhook::verify()}.
 *
 * `submit()` does not wait for a terminal status, but it does upload
 * before returning; pass a {@see Cancellation} token to abort between the
 * uploads of a multi-asset submit (e.g. a merge) with a
 * {@see \Gisl\Sdk\Errors\GislAbortError}. See {@see RunOptions} for the
 * cooperative + between-steps cancellation semantics.
 *
 * Mirrors the TS `SubmitOptions` interface at
 * `packages/typescript/src/builder.ts:242-245`.
 */
final class SubmitOptions
{
    public function __construct(
        public readonly string $webhook,
        public readonly ?Cancellation $cancellation = null,
    ) {
    }
}
