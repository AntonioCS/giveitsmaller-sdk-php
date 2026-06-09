<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * 4xx / 5xx response carrying a typed error envelope (`{ success: false,
 * error: "...", details: [...] }`).
 *
 * Mirrors `packages/typescript/src/errors.ts:GislApiError`. The localisation
 * triple (`messageKey` + `locale` + `messageParams`) implements ticket I26 —
 * surfaced on every typed error so consumers can drive client-side i18n
 * catalogs without unwrapping the typed payload. Field names are camelCase
 * here (PHP convention); the on-wire envelope carries snake_case
 * (`message_key`, `locale`, `message_params`).
 */
class GislApiError extends GislError
{
    /**
     * @param int                          $statusCode       HTTP status code.
     * @param string                       $errorCode        Wire-stable machine code from
     *                                                       `error` field. Never localised.
     * @param array<string, mixed>         $payload          Full decoded envelope body for
     *                                                       caller-side narrowing (`details`,
     *                                                       `message_key`, `locale`,
     *                                                       `message_params` when present).
     * @param string|null                  $messageKey       Stable, never-localised i18n key.
     *                                                       Carried through from the wire
     *                                                       `message_key` field per I26.
     * @param string|null                  $locale           Locale tag (e.g. `en-GB`) the
     *                                                       server resolved for the
     *                                                       `message` and `message_key`
     *                                                       on this response.
     * @param array<string, mixed>|null    $messageParams    Substitution params for
     *                                                       client-side i18n catalog
     *                                                       rendering of `messageKey`.
     * @param array<string, string>        $responseHeaders  HTTP response headers, keys
     *                                                       LOWERCASED (RFC 9110
     *                                                       case-insensitive). Multi-value
     *                                                       headers (e.g. `set-cookie`) are
     *                                                       collapsed to a single
     *                                                       comma-joined string — do NOT
     *                                                       rely on this map for cookies.
     *                                                       Mirrors
     *                                                       `packages/typescript/src/errors.ts:34-41`.
     * @param string|null                  $contentLanguage  The `Content-Language` response
     *                                                       header value — the language the
     *                                                       server actually resolved for
     *                                                       content negotiation. DISTINCT
     *                                                       from `$locale`, which is the
     *                                                       body-envelope I26 localisation
     *                                                       tag (`ErrorEnvelope.locale`).
     */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly string $errorCode,
        public readonly array $payload = [],
        public readonly ?string $messageKey = null,
        public readonly ?string $locale = null,
        public readonly ?array $messageParams = null,
        public readonly array $responseHeaders = [],
        public readonly ?string $contentLanguage = null,
    ) {
        parent::__construct($message);
    }
}
