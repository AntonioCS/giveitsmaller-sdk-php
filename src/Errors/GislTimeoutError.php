<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * Client-side timeout — the per-request deadline configured on
 * {@see \Gisl\Sdk\GislClientConfig::$timeoutMs} fired before the server
 * produced a complete response.
 *
 * Mirrors `packages/typescript/src/errors.ts:GislTimeoutError`. The actual
 * timeout enforcement happens through the PSR-18 client's own request
 * options (Guzzle: `timeout`/`connect_timeout`; Symfony HttpClient:
 * `timeout`); this exception is the SDK's normalised re-throw shape.
 */
final class GislTimeoutError extends GislError
{
}
