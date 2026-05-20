<?php

declare(strict_types=1);

namespace Gisl\Sdk;

/**
 * JSON-serialisable snapshot of an in-progress multipart upload, emitted
 * after every successful part PUT via {@see UploadOptions::$onCheckpoint}.
 *
 * Mirrors the TS `MultipartCheckpointState` interface at
 * `packages/typescript/src/types.ts`. Implements {@see \JsonSerializable} so
 * the snapshot round-trips through `json_encode` for cross-process /
 * cross-language persistence (TS uses `JSON.stringify`).
 *
 * All fields are primitive: no `DateTimeImmutable` (use the ISO-8601 string
 * on `$manifestExpiresAt`), no resources, no closures.
 */
final class MultipartCheckpointState implements \JsonSerializable
{
    /**
     * @param string                  $uploadId            The server-assigned `upload_id` (UUID) used as `$resumeUploadId` later.
     * @param int                     $totalParts          Total parts the server computed for this upload. <=10 000.
     * @param list<int>               $uploadedPartNumbers Part numbers (1-indexed) successfully PUT to S3 so far. Sorted ascending.
     * @param string                  $manifestExpiresAt   ISO-8601 wall-clock instant the server's manifest expires (48h TTL).
     */
    public function __construct(
        public readonly string $uploadId,
        public readonly int $totalParts,
        public readonly array $uploadedPartNumbers,
        public readonly string $manifestExpiresAt,
    ) {
    }

    /**
     * Wire-format mirrors the TS `MultipartCheckpointState` interface
     * exactly so a state persisted by one SDK can be consumed by the other.
     *
     * @return array{
     *     uploadId: string,
     *     totalParts: int,
     *     uploadedPartNumbers: list<int>,
     *     manifestExpiresAt: string,
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'uploadId' => $this->uploadId,
            'totalParts' => $this->totalParts,
            'uploadedPartNumbers' => $this->uploadedPartNumbers,
            'manifestExpiresAt' => $this->manifestExpiresAt,
        ];
    }
}
