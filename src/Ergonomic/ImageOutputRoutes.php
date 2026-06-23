<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

use Gisl\Generated\Operations\CompressMetadata;

/**
 * Route-aware image "Output" model (card YNLrGhNo, contracts tewB37Jg /
 * v2.97.0). PHP arm of the TS `image_output_routes.ts` — keep in lockstep.
 *
 * The file-first `output()`/`resize()` helpers resolve a single user-facing
 * "Output" operation to the right underlying wire op + options, driven by the
 * contract's `accepted-options/image-output-routes.json` projection. The route
 * is `(input format token, output_format token)`:
 *   - `same_format` (output == input) → `source_op: compress` (libcaesium
 *     optimiser), wire `output_format: 'original'`;
 *   - `format_change` (output != input) → `source_op: convert` (transcoder),
 *     wire `output_format: <token>`.
 *
 * Each route cell lists the options the worker HONORS (live) and PLANS
 * (advertised, not yet honored — gated unavailable). Resize (`width`/`height`/
 * `fit`) is INPUT-gated: since v2.103.0 convert is the resize engine, so the
 * projection lists resize on every `format_change` cell too — but resizability
 * is keyed to the INPUT (raster only — `svg` is vector and carries no resize).
 * The lowering reads resize capability from `same_format[input]` on BOTH routes,
 * so a `png → webp + resize` request resizes (png is raster) while an `svg → png
 * + resize` request does NOT (svg's same_format cell has no resize keys).
 *
 * This hand table MIRRORS the generated projection and is PINNED to it by the
 * output-route conformance test (the watermark-capability-gate precedent) — a
 * contract regen that changes a route's source_op / honored / planned options
 * fails that test. Kept a hand table (not a runtime JSON read) so the gate is
 * deterministic + offline, exactly like {@see WatermarkGate::CAPABILITY}.
 */
final class ImageOutputRoutes
{
    /** The resize option keys — input-keyed, raster-only (see class doc). */
    public const RESIZE_KEYS = ['width', 'height', 'fit'];

    /** Image area cap shared by every resizable route (projection `max_output_pixels`). */
    public const MAX_OUTPUT_PIXELS = 16_000_000;

    /**
     * Output formats reachable via the legacy `compress(output_format=…)` facade
     * (projection `facade_managed_outputs`). Used ONLY as the undetectable-input
     * fallback — a detectable input always routes via `source_op`.
     *
     * @var list<string>
     */
    public const FACADE_MANAGED_OUTPUTS = ['webp'];

    /** Canonical MIME → bare format token (projection `mime_tokens`). */
    private const MIME_TOKEN = [
        'image/avif' => 'avif',
        'image/gif' => 'gif',
        'image/jpeg' => 'jpeg',
        'image/png' => 'png',
        'image/svg+xml' => 'svg',
        'image/tiff' => 'tiff',
        'image/webp' => 'webp',
    ];

    /** File extension → bare format token (for path / named-resource inputs). */
    private const EXT_TOKEN = [
        'jpg' => 'jpeg', 'jpeg' => 'jpeg', 'jpe' => 'jpeg', 'jfif' => 'jpeg',
        'png' => 'png', 'webp' => 'webp', 'gif' => 'gif', 'avif' => 'avif',
        'tif' => 'tiff', 'tiff' => 'tiff', 'svg' => 'svg',
    ];

    /**
     * Per-route, per-output-format honored + planned option keys, mirroring
     * `image-output-routes.json` `media.image`. `source_op` is uniform
     * (same_format→compress, format_change→convert) so it is a derivation rule,
     * not a table column. `same_format` is keyed by the INPUT token;
     * `format_change` by the OUTPUT token.
     *
     * @var array{
     *   same_format: array<string, array{honored: list<string>, planned: list<string>}>,
     *   format_change: array<string, array{honored: list<string>, planned: list<string>}>,
     * }
     */
    public const IMAGE_OUTPUT_ROUTES = [
        'same_format' => [
            'avif' => ['honored' => ['avif_speed', 'encoding_mode', 'fit', 'height', 'metadata', 'output_format', 'quality', 'width'], 'planned' => ['target_size_bytes']],
            'gif' => ['honored' => ['fit', 'height', 'metadata', 'output_format', 'quality', 'width'], 'planned' => []],
            'jpeg' => ['honored' => ['encoding_mode', 'fit', 'height', 'lossless', 'metadata', 'output_format', 'progressive', 'quality', 'width'], 'planned' => ['target_size_bytes']],
            'png' => ['honored' => ['fit', 'height', 'metadata', 'optimization_level', 'output_format', 'quality', 'width'], 'planned' => ['lossy']],
            'svg' => ['honored' => ['metadata', 'output_format', 'quality'], 'planned' => []],
            'tiff' => ['honored' => ['fit', 'height', 'metadata', 'output_format', 'quality', 'width'], 'planned' => []],
            'webp' => ['honored' => ['encoding_mode', 'fit', 'height', 'lossless', 'metadata', 'output_format', 'quality', 'width'], 'planned' => ['target_size_bytes']],
        ],
        'format_change' => [
            'avif' => ['honored' => ['fit', 'height', 'output_format', 'quality', 'width'], 'planned' => []],
            'gif' => ['honored' => ['fit', 'height', 'output_format', 'width'], 'planned' => []],
            'jpeg' => ['honored' => ['background', 'fit', 'height', 'output_format', 'quality', 'width'], 'planned' => []],
            'png' => ['honored' => ['fit', 'height', 'output_format', 'width'], 'planned' => []],
            'tiff' => ['honored' => ['fit', 'height', 'output_format', 'width'], 'planned' => []],
            'webp' => ['honored' => ['fit', 'height', 'output_format', 'quality', 'width'], 'planned' => []],
        ],
    ];

    /** The bare format token for a MIME type, or null if not a known image MIME. */
    public static function tokenForMime(string $mime): ?string
    {
        $bare = \strtolower(\trim(\explode(';', $mime)[0]));
        return self::MIME_TOKEN[$bare] ?? null;
    }

    /** The bare format token for a filename / path extension, or null. */
    public static function tokenForPath(string $path): ?string
    {
        $dot = \strrpos($path, '.');
        if ($dot === false) {
            return null;
        }
        $ext = \strtolower(\substr($path, $dot + 1));
        return self::EXT_TOKEN[$ext] ?? null;
    }

    /**
     * Every image format token the projection knows (for validation / tests).
     *
     * @return list<string>
     */
    public static function knownImageTokens(): array
    {
        return \array_keys(self::IMAGE_OUTPUT_ROUTES['same_format']);
    }

    /**
     * Resolve an Output request to its wire op + gating sets. Returns null when
     * the route is unrepresentable (e.g. converting TO a format no
     * `format_change` cell covers). `$outputFormat` null → same-format (keep
     * input format). Mirrors the TS `resolveOutputRoute`.
     *
     * The returned shape (a small assoc array) mirrors the TS
     * `ResolvedOutputRoute`: `route`, `sourceOp`, `outputFormatWire`,
     * `inputToken`, plus `honored`/`planned` as key-sets (`array<string, true>`
     * for O(1) membership, the PHP idiom for the TS `ReadonlySet`).
     *
     * @return array{
     *   route: 'same_format'|'format_change',
     *   sourceOp: 'compress'|'convert',
     *   outputFormatWire: string,
     *   inputToken: string,
     *   honored: array<string, true>,
     *   planned: array<string, true>,
     * }|null
     */
    public static function resolveOutputRoute(string $inputToken, ?string $outputFormat): ?array
    {
        $outToken = $outputFormat ?? $inputToken;
        if ($outToken === $inputToken) {
            $cell = self::IMAGE_OUTPUT_ROUTES['same_format'][$inputToken] ?? null;
            if ($cell === null) {
                return null;
            }
            return [
                'route' => 'same_format',
                'sourceOp' => 'compress',
                'outputFormatWire' => 'original',
                'inputToken' => $inputToken,
                'honored' => self::keySet($cell['honored']),
                'planned' => self::keySet($cell['planned']),
            ];
        }
        $cell = self::IMAGE_OUTPUT_ROUTES['format_change'][$outToken] ?? null;
        if ($cell === null) {
            return null;
        }
        // Resize is INPUT-gated. Since v2.103.0 convert is the resize engine, so
        // the projection lists width/height/fit on EVERY format_change cell — but
        // an SVG INPUT cannot be raster-resized (the convert worker rejects it).
        // So strip the cell's resize keys and re-add only those the INPUT's
        // same_format cell honors: raster inputs carry them, svg does not. The
        // transcoder options (output_format/quality/background) ride the cell.
        $transcoderHonored = \array_values(\array_filter(
            $cell['honored'],
            static fn (string $k): bool => !\in_array($k, self::RESIZE_KEYS, true),
        ));
        $inCell = self::IMAGE_OUTPUT_ROUTES['same_format'][$inputToken] ?? null;
        $resize = $inCell !== null
            ? \array_values(\array_filter(self::RESIZE_KEYS, static fn (string $k): bool => \in_array($k, $inCell['honored'], true)))
            : [];
        return [
            'route' => 'format_change',
            'sourceOp' => 'convert',
            'outputFormatWire' => $outToken,
            'inputToken' => $inputToken,
            'honored' => self::keySet([...$transcoderHonored, ...$resize]),
            'planned' => self::keySet($cell['planned']),
        ];
    }

    /**
     * Whether a specific VALUE of an option is `availability: 'planned'` for the
     * given input format — the per-value gate (e.g. `metadata: 'keep'` is planned
     * even though the `metadata` key is honored). Reads the generated
     * `CompressMetadata` `per_value_availability`; same_format only (the only
     * route where value-level options like `metadata` are honored). Returns false
     * when the option / value / group is unknown (no gate). Mirrors the TS
     * `isPlannedValue`.
     */
    public static function isPlannedValue(string $inputToken, string $optionKey, mixed $value): bool
    {
        $group = CompressMetadata::instance()->mime_groups[self::compressGroupForToken($inputToken)] ?? null;
        if ($group === null) {
            return false;
        }
        $opt = $group->options[$optionKey] ?? null;
        if ($opt === null) {
            return false;
        }
        $entry = $opt->per_value_availability[self::stringifyForMessage($value)] ?? null;
        return $entry !== null && $entry->availability === 'planned';
    }

    /**
     * Input token → its `compress.image*` mime-group name (for per-value
     * availability lookup). Mirrors the TS `compressGroupForToken`.
     */
    public static function compressGroupForToken(string $token): string
    {
        if ($token === 'jpeg') {
            return 'image_jpeg';
        }
        if ($token === 'png') {
            return 'image_png';
        }
        if ($token === 'avif') {
            return 'image_avif';
        }
        return 'image'; // webp / gif / svg / tiff
    }

    /**
     * Convert a list of keys to a presence-keyed set (`array<string, true>`),
     * the PHP idiom for the TS `ReadonlySet<string>` (O(1) `isset` membership).
     *
     * @param list<string> $keys
     *
     * @return array<string, true>
     */
    private static function keySet(array $keys): array
    {
        $set = [];
        foreach ($keys as $k) {
            $set[$k] = true;
        }
        return $set;
    }

    /**
     * Stringify an option value to match the TS `String(value)` — used BOTH as
     * the `per_value_availability` lookup key AND in the planned-value error
     * message, so PHP and TS produce byte-identical gating + identical message
     * text (`true`/`false` lowercase, numbers bare).
     */
    public static function stringifyForMessage(mixed $value): string
    {
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (\is_string($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }
        return \get_debug_type($value);
    }
}
