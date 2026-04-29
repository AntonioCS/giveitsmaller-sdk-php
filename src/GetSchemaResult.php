<?php

declare(strict_types=1);

namespace Gisl\Sdk;

/**
 * Sealed-shape return type for {@see GislClient::getSchema()}.
 *
 * This is the SDK's first sealed-shape value object — there is no precedent
 * elsewhere in `packages/php/src/`. PHP 8.1 has no `sealed` keyword, so the
 * pattern is encoded via an abstract base + two `final` subclasses
 * ({@see GetSchemaHitResult} and {@see GetSchemaNotModifiedResult}). Callers
 * are expected to narrow with `instanceof` rather than reading discriminator
 * fields:
 *
 * ```php
 * $result = $client->getSchema();
 * if ($result instanceof GetSchemaHitResult) {
 *     $schema = $result->schema;          // body present
 *     $cacheKey = $result->etag;          // capture for next request
 * } elseif ($result instanceof GetSchemaNotModifiedResult) {
 *     // 304 — keep using whatever copy you cached against $result->etag
 * }
 * ```
 *
 * Mirrors the TS discriminated union `GetSchemaResult` at
 * `packages/typescript/src/types.ts`. Construction is intentionally NOT
 * surfaced on the abstract — callers should never instantiate
 * `GetSchemaResult` directly; the SDK's `getSchema()` is the only producer.
 */
abstract class GetSchemaResult
{
}
