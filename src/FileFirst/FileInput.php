<?php

declare(strict_types=1);

namespace Gisl\Sdk\FileFirst;

use Gisl\Sdk\Ergonomic\OperationBuilder;

/**
 * The primary file a {@see Recipe} operates on — the "subject" of the
 * file-first surface. A discriminated value over the three ways a caller
 * can name an input:
 *
 *  - `path`      — a local filesystem path (the common case, examples 01/02).
 *  - `resource`  — an open PHP stream/resource handle.
 *  - `uploadId`  — a previously-uploaded `file_id` (reuse across recipes).
 *
 * FF2a does NO upload, so only the `path` + `uploadId` arms are exercised
 * end-to-end here; the `resource` arm is DEFINED and type-checked but its
 * upload is wired by FF2b (`run()`). Mirrors the TS `FileInput` union in
 * `packages/typescript/src/file-first.ts`.
 *
 * Immutable: all properties are readonly and the only constructor is private,
 * reached through the three named factories.
 */
final class FileInput
{
    public const KIND_PATH = 'path';
    public const KIND_RESOURCE = 'resource';
    public const KIND_UPLOAD_ID = 'upload_id';

    /**
     * @param self::KIND_*  $kind     Which arm of the union is populated.
     * @param string|null   $path     Filesystem path when `$kind === KIND_PATH`.
     * @param resource|null $resource Open stream when `$kind === KIND_RESOURCE`.
     * @param string|null   $fileId   Pre-uploaded id when `$kind === KIND_UPLOAD_ID`.
     */
    private function __construct(
        public readonly string $kind,
        public readonly ?string $path = null,
        public readonly mixed $resource = null,
        public readonly ?string $fileId = null,
    ) {
    }

    public static function path(string $path): self
    {
        return new self(self::KIND_PATH, path: $path);
    }

    /**
     * @param resource $resource
     */
    public static function resource(mixed $resource): self
    {
        return new self(self::KIND_RESOURCE, resource: $resource);
    }

    /**
     * Reference an already-uploaded file by its upload id, instead of
     * re-uploading bytes.
     *
     * Auth-ownership: an upload created by an *authenticated* caller is owned
     * by that caller. If you reuse the id from a client configured with a
     * DIFFERENT auth context (a different api key / session), workflow-create
     * returns `404 upload_not_found` — the server enforces ownership (api
     * PqpD9ySv). Reference an upload id only under the SAME auth that created
     * it. The normal upload-then-create-in-one-client flow is consistent by
     * construction (the same Authorization rides every request). Ownerless
     * (anonymous-intake) uploads are unaffected.
     */
    public static function uploadId(string $fileId): self
    {
        return new self(self::KIND_UPLOAD_ID, fileId: $fileId);
    }

    /**
     * The compress media class for preset resolution, derived from the input's
     * filename extension — or null when the media cannot be inferred locally
     * (a resource handle or a bare upload id carries no extension). Reuses the
     * same extension classifier the operation-first builder uses so a file-first
     * `compress()` lowers to the identical wire options as `client->compress()`.
     *
     * @return 'image'|'audio'|'video'|'document_pdf'|'document_office'|'document_odf'|'document_epub'|null
     */
    public function compressMediaHint(): ?string
    {
        if ($this->kind !== self::KIND_PATH || $this->path === null) {
            return null;
        }
        return OperationBuilder::detectCompressMedia($this->path);
    }
}
