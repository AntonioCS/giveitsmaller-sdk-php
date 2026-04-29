<?php

declare(strict_types=1);

namespace Gisl\Sdk;

/**
 * One typed SSE frame yielded by {@see GislClient::streamEvents()}.
 *
 * Mirrors the TS reference type at `packages/typescript/src/types.ts`
 * (`GislSseEvent = { event: string; data: unknown }`) — deliberately NO
 * `id` and NO `retry` properties. Codex round 1 on B2.1 was explicit on
 * this: `id:` is ignored (the SDK does not implement Last-Event-ID
 * reconnection) and `retry:` is ignored (the SDK manages its own
 * reconnection cadence). Surfacing those fields would imply behaviour
 * the SDK does not provide.
 *
 * `$event` is the SSE `event:` field value, defaulting to `"message"`
 * per the SSE spec when the server omits it.
 *
 * `$data` is the JSON-decoded `data:` field as a plain associative
 * array (snake_case keys preserved exactly as the server emits them —
 * the SDK does not camelCase-convert SSE payloads). Frames whose
 * `data:` body fails to JSON-decode are SKIPPED inside the parser
 * rather than yielded with a string fallback — long-running consumers
 * should not break on garbled frames, and a plain-string `data` would
 * defeat the typed-event promise.
 */
final class GislSseEvent
{
    public function __construct(
        public readonly string $event,
        public readonly mixed $data,
    ) {
    }
}
