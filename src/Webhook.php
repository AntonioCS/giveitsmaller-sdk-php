<?php

declare(strict_types=1);

namespace Gisl\Sdk;

use Gisl\Sdk\Errors\GislError;

/**
 * Webhook signature verification for inbound GISL webhooks.
 *
 * Mirrors `packages/typescript/src/webhook.ts`. The SDK only exposes
 * verification — the server signs, the SDK verifies on the receiving side.
 */
final class Webhook
{
    private const SIGNATURE_PREFIX = 'sha256=';
    private const HEX_LENGTH = 64;

    /**
     * Verify an `X-GIS-Signature` header value against the raw request body.
     *
     * @param string $secret    The `webhook_secret` from the workflow creation
     *                          response. Treat as a credential.
     * @param string $signature The exact value of the `X-GIS-Signature` header
     *                          (e.g. `sha256=abc123…`).
     * @param string $body      The raw request body. MUST be the bytes the
     *                          server signed — middleware that re-encodes JSON
     *                          (e.g. with sorted keys) will break this.
     * @return true             Always `true` on success; throws on any
     *                          mismatch so callers cannot silently get a
     *                          falsy result through.
     * @throws GislError        On malformed prefix, wrong hex length, or
     *                          signature mismatch.
     */
    public static function verify(string $secret, string $signature, string $body): bool
    {
        if (\strncmp($signature, self::SIGNATURE_PREFIX, \strlen(self::SIGNATURE_PREFIX)) !== 0) {
            throw new GislError(
                'Invalid signature format: expected "' . self::SIGNATURE_PREFIX . '<hex>"',
            );
        }

        $receivedHex = \substr($signature, \strlen(self::SIGNATURE_PREFIX));

        if (\preg_match('/^[0-9a-f]{' . self::HEX_LENGTH . '}$/i', $receivedHex) !== 1) {
            throw new GislError('Webhook signature verification failed');
        }

        $expectedHex = \hash_hmac('sha256', $body, $secret);

        // hash_equals is the constant-time PHP equivalent of Node's
        // timingSafeEqual. Comparing hex (rather than raw bytes) is fine —
        // both inputs have identical length by construction at this point.
        if (!\hash_equals($expectedHex, \strtolower($receivedHex))) {
            throw new GislError('Webhook signature verification failed');
        }

        return true;
    }
}
