<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Parity;

use Symfony\Component\Yaml\Yaml;

/**
 * Load + validate every fixture under `tests/parity/fixtures/`.
 *
 * Mirrors `packages/typescript/tests/parity/fixtures.ts:loadFixtures`. The
 * runtime validation (method enum, name-vs-filename, body type whitelist,
 * webhook hex-length) catches the same fixture-author typos the TS runner
 * catches, so a fixture that loads cleanly here loads cleanly there.
 */
final class FixtureLoader
{
    /**
     * Known SDK methods. Mirrors the TS allowlist verbatim — divergence here
     * means a fixture references a method that exists in one runner but not
     * another, and that's a parity bug.
     *
     * Ergonomic-facade verbs `compress` / `thumbnail` / `convert` were
     * added in PHP P2 (7QXkzoIi) alongside the real ergonomic-dispatch
     * wiring in {@see Invoke}. PHP P3 (dxIeLVbP) added `merge` via the
     * new multi-input dispatch path. The TS allowlist at
     * `packages/typescript/tests/parity/fixtures.ts` carries the symmetric
     * additions + the matching dispatch shims in
     * `packages/typescript/tests/parity/invoke.ts`. `watermark` / `archive`
     * / `mapEach` / `bundle` are deliberately STILL OMITTED:
     *
     *   - `watermark`: v2 `OperationType` has no bare `watermark` value
     *     (split into `image_watermark` / `text_watermark`). Needs a
     *     preset-style mapping → tracked alongside the preset matrix.
     *   - `archive`: contract-modeled as MULTI-INPUT (`inputs[]`), not
     *     compatible with the single-input `OperationBuilder` → lands
     *     alongside P4's `.bundle()` archive sugar.
     *   - `mapEach` / `bundle` stay on the P0 seam (Bljva8nj) until P4 ships.
     *
     * @var list<string>
     */
    private const KNOWN_SDK_METHODS = [
        'uploadFile',
        'cancelWorkflow',
        'createExternalImport',
        'createWorkflow',
        'decodeAudioWatermark',
        'getWorkflowStatus',
        'resumeWorkflow',
        'waitForWorkflow',
        'getWorkflowDownloads',
        'streamEvents',
        'getCreditsBalance',
        'getCreditsUsage',
        'getMetadata',
        'getSchema',
        'login',
        'logout',
        'preflightClips',
        'probeUpload',
        'retryOperation',
        'submitContact',
        // SDK-3 (Wb6ebOMM) resume-support endpoints.
        'getUploadStatus',
        'presignParts',
        'keepaliveUpload',
        // Webhook mode invokes verifyWebhook directly; not a GislClient method.
        'verifyWebhook',
        // Ergonomic-facade verbs (PHP P2 / 7QXkzoIi). See class docblock
        // for why `watermark` + `archive` are NOT included here yet.
        'compress',
        'thumbnail',
        'convert',
        // Multi-input ergonomic verbs (PHP P3 / dxIeLVbP).
        'merge',
    ];

    private const ALLOWED_REQUEST_METHODS = [
        'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS',
    ];

    private const ALLOWED_REQUEST_BODY_TYPES = ['json', 'multipart', 'raw', 'empty'];
    private const ALLOWED_RESPONSE_BODY_TYPES = ['json', 'text', 'sse_stream', 'raw', 'empty'];
    private const ALLOWED_MODES = [
        Fixture::MODE_REQUEST_RESPONSE,
        Fixture::MODE_SSE,
        Fixture::MODE_WEBHOOK,
        Fixture::MODE_LOCAL_VALIDATION_ERROR,
    ];

    private const ALLOWED_SCHEMA_VERSIONS = [
        Fixture::SCHEMA_VERSION_V1,
        Fixture::SCHEMA_VERSION_V2,
    ];

    /**
     * @return list<Fixture>
     */
    public static function loadAll(): array
    {
        $dir = FixturePaths::fixturesDir();
        $files = [];
        foreach (\scandir($dir) ?: [] as $entry) {
            if (\str_ends_with($entry, '.yaml') || \str_ends_with($entry, '.yml')) {
                $files[] = $entry;
            }
        }
        \sort($files);

        $out = [];
        foreach ($files as $file) {
            $full = $dir . '/' . $file;
            $raw = Yaml::parseFile($full);
            $out[] = self::validate($raw, $full);
        }
        return $out;
    }

    /**
     * @param mixed  $raw
     */
    private static function validate(mixed $raw, string $file): Fixture
    {
        $base = \basename($file);
        if (!\is_array($raw) || \array_is_list($raw)) {
            throw new \RuntimeException("[{$base}] root must be a mapping");
        }

        $name = self::requireString($raw, 'name', $base);
        if (\preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw new \RuntimeException("[{$base}] name must be lowercase snake_case, got \"{$name}\"");
        }
        $expectedName = \preg_replace('/\.ya?ml$/', '', $base) ?? $base;
        if ($name !== $expectedName) {
            throw new \RuntimeException("[{$base}] name \"{$name}\" must match filename \"{$expectedName}\"");
        }

        $mode = isset($raw['mode']) ? self::requireString($raw, 'mode', $base) : Fixture::MODE_REQUEST_RESPONSE;
        if (!\in_array($mode, self::ALLOWED_MODES, true)) {
            throw new \RuntimeException("[{$base}] invalid mode \"{$mode}\"");
        }

        if (!isset($raw['sdk']) || !\is_array($raw['sdk'])) {
            throw new \RuntimeException("[{$base}] sdk must be a mapping");
        }
        /** @var array<string, mixed> $sdkRaw */
        $sdkRaw = $raw['sdk'];
        $method = self::requireString($sdkRaw, 'method', $base . ' sdk');
        if (!\in_array($method, self::KNOWN_SDK_METHODS, true)) {
            throw new \RuntimeException(
                "[{$base}] unknown sdk.method \"{$method}\". Known: " . \implode(', ', self::KNOWN_SDK_METHODS),
            );
        }
        /** @var list<mixed> $args */
        $args = [];
        if (isset($sdkRaw['args'])) {
            if (!\is_array($sdkRaw['args']) || !\array_is_list($sdkRaw['args'])) {
                throw new \RuntimeException("[{$base}] sdk.args must be a sequence");
            }
            $args = $sdkRaw['args'];
        }

        /** @var list<array<string, mixed>> $requests */
        $requests = [];
        if (isset($raw['requests'])) {
            if (!\is_array($raw['requests']) || !\array_is_list($raw['requests'])) {
                throw new \RuntimeException("[{$base}] requests must be a sequence");
            }
            foreach ($raw['requests'] as $i => $req) {
                if (!\is_array($req) || \array_is_list($req)) {
                    throw new \RuntimeException("[{$base}] requests[{$i}] must be a mapping");
                }
                self::validateRequest($req, "{$base} requests[{$i}]");
                /** @var array<string, mixed> $req */
                $requests[] = $req;
            }
        }

        /** @var list<array<string, mixed>> $responses */
        $responses = [];
        if (isset($raw['responses'])) {
            if (!\is_array($raw['responses']) || !\array_is_list($raw['responses'])) {
                throw new \RuntimeException("[{$base}] responses must be a sequence");
            }
            foreach ($raw['responses'] as $i => $res) {
                if (!\is_array($res) || \array_is_list($res)) {
                    throw new \RuntimeException("[{$base}] responses[{$i}] must be a mapping");
                }
                self::validateResponse($res, "{$base} responses[{$i}]");
                /** @var array<string, mixed> $res */
                $responses[] = $res;
            }
        }

        if ($mode === Fixture::MODE_REQUEST_RESPONSE || $mode === Fixture::MODE_SSE) {
            if (\count($requests) === 0) {
                throw new \RuntimeException("[{$base}] mode={$mode} requires at least one request");
            }
            if (\count($requests) !== \count($responses)) {
                throw new \RuntimeException(
                    "[{$base}] requests.length (" . \count($requests) . ") must equal responses.length (" . \count($responses) . ')',
                );
            }
        }
        if ($mode === Fixture::MODE_LOCAL_VALIDATION_ERROR) {
            if (\count($requests) !== 0 || \count($responses) !== 0) {
                throw new \RuntimeException(
                    "[{$base}] mode=local_validation_error must declare zero requests + zero responses",
                );
            }
            if (!\array_key_exists('localValidationError', $raw)) {
                throw new \RuntimeException(
                    "[{$base}] mode=local_validation_error requires a localValidationError block",
                );
            }
        }

        $webhook = null;
        if ($mode === Fixture::MODE_WEBHOOK) {
            if ($method !== 'verifyWebhook') {
                throw new \RuntimeException(
                    "[{$base}] mode=webhook requires sdk.method=\"verifyWebhook\" (got \"{$method}\")",
                );
            }
            if (!isset($raw['webhook']) || !\is_array($raw['webhook']) || \array_is_list($raw['webhook'])) {
                throw new \RuntimeException("[{$base}] webhook must be a mapping");
            }
            /** @var array<string, mixed> $w */
            $w = $raw['webhook'];
            if (\count($requests) > 0 || \count($responses) > 0) {
                throw new \RuntimeException(
                    "[{$base}] mode=webhook must not declare requests/responses",
                );
            }
            $hex = self::requireString($w, 'expected_signature_hex', $base . ' webhook');
            if (\preg_match('/^[0-9a-f]{64}$/', $hex) !== 1) {
                throw new \RuntimeException(
                    "[{$base}] webhook.expected_signature_hex must be 64 lowercase hex chars",
                );
            }
            $webhook = [
                'secret' => self::requireString($w, 'secret', $base . ' webhook'),
                'body' => self::requireString($w, 'body', $base . ' webhook'),
                'header_name' => isset($w['header_name']) ? (string) $w['header_name'] : 'x-gis-signature',
                'header_format' => isset($w['header_format']) ? (string) $w['header_format'] : 'sha256={hex}',
                'expected_signature_hex' => $hex,
                'algorithm' => isset($w['algorithm']) ? (string) $w['algorithm'] : 'hmac-sha256',
            ];
        }

        $description = isset($raw['description']) ? (string) $raw['description'] : $name;
        $hasExpectedReturn = \array_key_exists('expected_return', $raw);
        $expectedReturn = $hasExpectedReturn ? $raw['expected_return'] : null;
        $expectsError = ($raw['expects_error'] ?? false) === true;

        // F4-A — schema-version discrimination + v2 assertion blocks.
        // PHP loader is naturally tolerant of unknown top-level keys; the
        // schema-version branch here is enforcement, not gatekeeping. v2
        // blocks on a v1 fixture are an authoring mistake.
        $schemaVersion = Fixture::SCHEMA_VERSION_V1;
        if (\array_key_exists('fixtureSchemaVersion', $raw)) {
            $rawVersion = $raw['fixtureSchemaVersion'];
            if (!\is_string($rawVersion) || !\in_array($rawVersion, self::ALLOWED_SCHEMA_VERSIONS, true)) {
                throw new \RuntimeException(
                    "[{$base}] fixtureSchemaVersion must be '1.0.0' or '2.0.0'",
                );
            }
            $schemaVersion = $rawVersion;
        }
        $hasV2Block =
            \array_key_exists('resolvedOptions', $raw)
            || \array_key_exists('omittedFromWire', $raw)
            || \array_key_exists('localValidationError', $raw);
        if ($schemaVersion === Fixture::SCHEMA_VERSION_V1 && $hasV2Block) {
            throw new \RuntimeException(
                "[{$base}] v2 assertion blocks (resolvedOptions / omittedFromWire / localValidationError) require fixtureSchemaVersion: '2.0.0'",
            );
        }
        if ($mode === Fixture::MODE_LOCAL_VALIDATION_ERROR && $schemaVersion !== Fixture::SCHEMA_VERSION_V2) {
            throw new \RuntimeException(
                "[{$base}] mode='local_validation_error' requires fixtureSchemaVersion: '2.0.0'",
            );
        }

        // Use array_key_exists (NOT isset) so an explicit `resolvedOptions:
        // null` is rejected rather than silently treated as absent — isset()
        // returns false on null, which would pass the v2-block gate above
        // (which uses array_key_exists) yet skip the assertion. The TS loader
        // enters validation on `!== undefined` and throws on null, so PHP must
        // too or the two runners diverge on the same fixture.
        $resolvedOptions = null;
        if (\array_key_exists('resolvedOptions', $raw)) {
            if (!\is_array($raw['resolvedOptions']) || \array_is_list($raw['resolvedOptions'])) {
                throw new \RuntimeException("[{$base}] resolvedOptions must be a mapping");
            }
            /** @var array<string, mixed> $resolvedOptions */
            $resolvedOptions = $raw['resolvedOptions'];
        }

        $omittedFromWire = null;
        if (\array_key_exists('omittedFromWire', $raw)) {
            if (!\is_array($raw['omittedFromWire']) || !\array_is_list($raw['omittedFromWire'])) {
                throw new \RuntimeException(
                    "[{$base}] omittedFromWire must be a sequence of strings",
                );
            }
            foreach ($raw['omittedFromWire'] as $idx => $field) {
                if (!\is_string($field) || $field === '') {
                    throw new \RuntimeException(
                        "[{$base}] omittedFromWire[{$idx}] must be a non-empty string",
                    );
                }
            }
            /** @var list<string> $omittedFromWire */
            $omittedFromWire = $raw['omittedFromWire'];
        }

        $localValidationError = null;
        if (\array_key_exists('localValidationError', $raw)) {
            if (!\is_array($raw['localValidationError']) || \array_is_list($raw['localValidationError'])) {
                throw new \RuntimeException(
                    "[{$base}] localValidationError must be a mapping",
                );
            }
            /** @var array<string, mixed> $localValidationError */
            $localValidationError = $raw['localValidationError'];
            $category = $localValidationError['category'] ?? null;
            if ($category !== 'validation' && $category !== 'config') {
                throw new \RuntimeException(
                    "[{$base}] localValidationError.category must be 'validation' or 'config'",
                );
            }
            if (!isset($localValidationError['code']) || !\is_string($localValidationError['code']) || $localValidationError['code'] === '') {
                throw new \RuntimeException(
                    "[{$base}] localValidationError.code must be a non-empty string",
                );
            }
        }

        return new Fixture(
            name: $name,
            description: $description,
            mode: $mode,
            sdkMethod: $method,
            args: $args,
            requests: $requests,
            responses: $responses,
            expectedReturn: $expectedReturn,
            hasExpectedReturn: $hasExpectedReturn,
            webhook: $webhook,
            expectsError: $expectsError,
            absolutePath: $file,
            schemaVersion: $schemaVersion,
            resolvedOptions: $resolvedOptions,
            omittedFromWire: $omittedFromWire,
            localValidationError: $localValidationError,
        );
    }

    /**
     * Load and validate one fixture by absolute path. F4-A — exposed so
     * the (future) F4-B conformance meta-tests can load named fixtures
     * without scanning the whole directory.
     *
     * @param string $absolutePath
     */
    public static function loadByPath(string $absolutePath): Fixture
    {
        $raw = Yaml::parseFile($absolutePath);
        return self::validate($raw, $absolutePath);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function requireString(array $raw, string $key, string $ctx): string
    {
        if (!isset($raw[$key]) || !\is_string($raw[$key]) || $raw[$key] === '') {
            throw new \RuntimeException("[{$ctx}] {$key} must be a non-empty string");
        }
        return $raw[$key];
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function validateRequest(array $raw, string $ctx): void
    {
        $method = self::requireString($raw, 'method', $ctx);
        if (!\in_array($method, self::ALLOWED_REQUEST_METHODS, true)) {
            throw new \RuntimeException(
                "[{$ctx}] method \"{$method}\" is not one of " . \implode('|', self::ALLOWED_REQUEST_METHODS),
            );
        }
        self::requireString($raw, 'path', $ctx);
        if (isset($raw['headers'])) {
            if (!\is_array($raw['headers']) || \array_is_list($raw['headers'])) {
                throw new \RuntimeException("[{$ctx}] headers must be a mapping");
            }
            foreach (\array_keys($raw['headers']) as $headerName) {
                if (!\is_string($headerName)) {
                    throw new \RuntimeException("[{$ctx}] header keys must be strings");
                }
                if ($headerName !== \strtolower($headerName)) {
                    throw new \RuntimeException("[{$ctx}] headers must use lowercase keys, got \"{$headerName}\"");
                }
            }
        }
        if (isset($raw['body'])) {
            if (!\is_array($raw['body']) || \array_is_list($raw['body'])) {
                throw new \RuntimeException("[{$ctx}] body must be a mapping");
            }
            $type = isset($raw['body']['type']) ? (string) $raw['body']['type'] : '';
            if (!\in_array($type, self::ALLOWED_REQUEST_BODY_TYPES, true)) {
                throw new \RuntimeException(
                    "[{$ctx}] body.type must be one of " . \implode('|', self::ALLOWED_REQUEST_BODY_TYPES),
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function validateResponse(array $raw, string $ctx): void
    {
        if (!isset($raw['status']) || !\is_int($raw['status']) || $raw['status'] < 100 || $raw['status'] > 599) {
            throw new \RuntimeException("[{$ctx}] status must be a number 100-599");
        }
        if (isset($raw['body'])) {
            if (!\is_array($raw['body']) || \array_is_list($raw['body'])) {
                throw new \RuntimeException("[{$ctx}] body must be a mapping");
            }
            $type = isset($raw['body']['type']) ? (string) $raw['body']['type'] : '';
            if (!\in_array($type, self::ALLOWED_RESPONSE_BODY_TYPES, true)) {
                throw new \RuntimeException(
                    "[{$ctx}] body.type must be one of " . \implode('|', self::ALLOWED_RESPONSE_BODY_TYPES),
                );
            }
        }
    }
}
