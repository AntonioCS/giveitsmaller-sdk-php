<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

/**
 * Static factory for merge assets + clip entries.
 *
 * Mirrors the top-level `asset()` / `handle()` / `clip()` helpers exported
 * from `packages/typescript/src/merge.ts:74-117`. PHP collapses them under
 * a single facade (parallels {@see \Gisl\Sdk\Sources} for source-payload
 * factories) so importing one short class name covers all merge construction.
 *
 * Usage:
 *
 *     $client->merge(
 *         [Merge::asset('/path/to/a.mp4'), Merge::asset('/path/to/b.mp4'), Merge::handle($pre)],
 *         new MergeOptions(transition: 'fade'),
 *     )
 *     ->sequence([
 *         Merge::asset('/path/to/a.mp4'),
 *         Merge::clip(Merge::asset('/path/to/b.mp4'), new ClipOptions(transition: 'crossfade', crossfadeDuration: 1.5)),
 *     ])
 *     ->run(new RunOptions(maxWait: '5m'));
 *
 * Convenience: {@see \Gisl\Sdk\GislErgonomicClient::merge()} also accepts
 * bare strings — `[$pathA, $pathB]` is implicitly `[Merge::asset($pathA),
 * Merge::asset($pathB)]`. The `Merge::*` helpers are needed when wrapping
 * a `file_id` (handle) or constructing a `clip(...)` with per-position
 * options.
 */
final class Merge
{
    public static function asset(string $path): PathAsset
    {
        return new PathAsset($path);
    }

    public static function handle(string $fileId): HandleAsset
    {
        return new HandleAsset($fileId);
    }

    /**
     * Wrap an {@see Asset} or a bare path string as a positional
     * {@see ClipEntry} carrying per-position options.
     */
    public static function clip(Asset|string $ref, ClipOptions $options = new ClipOptions()): ClipEntry
    {
        $asset = $ref instanceof Asset ? $ref : new PathAsset($ref);
        return new ClipEntry($asset, $options);
    }
}
