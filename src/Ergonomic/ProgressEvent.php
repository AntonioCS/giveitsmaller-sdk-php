<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

/**
 * Marker base for the SDK-synthesised progress union surfaced via
 * {@see RunOptions::$onProgress}. The two concrete events
 * ({@see UploadProgressEvent}, {@see ProcessingProgressEvent}) carry
 * the `phase` discriminator and the phase-specific fields.
 *
 * **NB:** the `phase` discriminator is SDK-added — it is NOT a wire
 * field. The upload phase comes from {@see \Gisl\Sdk\UploadOptions}'s
 * byte-counter callback (no wire equivalent); the processing phase
 * projects `SseOperationProgressData` verbatim with the discriminator
 * tacked on. Mirrors the TS reference at
 * `packages/typescript/src/builder.ts:176-212`.
 */
abstract class ProgressEvent
{
    public function __construct(
        public readonly string $phase,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;
}
