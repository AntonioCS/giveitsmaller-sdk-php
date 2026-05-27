<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

/**
 * A single deliverable output file. Flat projection of
 * {@see \Gisl\Generated\OpenApi\Model\OperationDownload} with `url`
 * aliasing `downloadUrl` + the parent `JobDownload`'s `ref` + `jobId`
 * carried in.
 *
 * Mirrors the TS `Artifact` interface at
 * `packages/typescript/src/builder.ts:82-101`.
 *
 *  - `url`: pre-signed download URL (aliases `OperationDownload.downloadUrl`).
 *  - `pageIndex`: 1-based page number for PDF-page fan-out (mutually
 *     exclusive with `position`).
 *  - `position`: 0-based ordinal for non-PDF multi-output (mutually
 *     exclusive with `pageIndex`).
 */
final class Artifact
{
    public function __construct(
        public readonly string $url,
        public readonly string $filename,
        public readonly int $sizeBytes,
        public readonly string $operation,
        public readonly string $operationId,
        public readonly string $jobId,
        public readonly string $ref,
        public readonly ?int $pageIndex = null,
        public readonly ?int $position = null,
    ) {
    }

    /**
     * Plain-array projection used by tests + JSON-serialise paths. Omits
     * the page/position fields when null so the serialised shape matches
     * the TS reference (where `undefined` values are dropped by
     * `JSON.stringify`).
     *
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        $out = [
            'url' => $this->url,
            'filename' => $this->filename,
            'sizeBytes' => $this->sizeBytes,
            'operation' => $this->operation,
            'operationId' => $this->operationId,
            'jobId' => $this->jobId,
            'ref' => $this->ref,
        ];
        if ($this->pageIndex !== null) {
            $out['pageIndex'] = $this->pageIndex;
        }
        if ($this->position !== null) {
            $out['position'] = $this->position;
        }
        return $out;
    }
}
