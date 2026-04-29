<?php

declare(strict_types=1);

namespace Gisl\Sdk;

/**
 * Optional pagination knobs for {@see GislClient::getCreditsUsage}.
 *
 * Mirrors the TS `CreditsUsageOptions` interface at
 * packages/typescript/src/client.ts (around line 1413). Server defaults are
 * `limit=20`, `offset=0`; both fields are forwarded only when non-null so the
 * server can supply its own defaults rather than the SDK pinning them.
 *
 * The fields are deliberately a narrow subset of what the wire endpoint may
 * grow into — only `limit` and `offset` are contract-pinned today. The earlier
 * draft of this card mentioned `from`/`to`/`granularity`; codex round 1
 * corrected the drift before implementation began.
 */
final class CreditsUsageOptions
{
    public function __construct(
        public readonly ?int $limit = null,
        public readonly ?int $offset = null,
    ) {
    }
}
