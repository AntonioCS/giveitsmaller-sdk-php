<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

/**
 * Upload-phase progress event. The byte counter comes from
 * {@see \Gisl\Sdk\UploadOptions::$onProgress} — there is no `phase`
 * field on the wire; the SDK synthesises this event from the upload
 * callback's `(uploadedBytes, totalBytes)` tuple.
 *
 * Mirrors `UploadProgressEvent` at
 * `packages/typescript/src/builder.ts:182-186`.
 */
final class UploadProgressEvent extends ProgressEvent
{
    public function __construct(
        public readonly int $uploadedBytes,
        public readonly int $totalBytes,
    ) {
        parent::__construct('upload');
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            'phase' => $this->phase,
            'uploadedBytes' => $this->uploadedBytes,
            'totalBytes' => $this->totalBytes,
        ];
    }
}
