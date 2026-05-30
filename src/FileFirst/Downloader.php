<?php

declare(strict_types=1);

namespace Gisl\Sdk\FileFirst;

/**
 * Streams a single output URL to a local path. The seam between the
 * file-first {@see RunResult} sinks and the SDK's HTTP/auth layer.
 *
 * FF1 defines ONLY this interface — the concrete implementation (a
 * fetch + filesystem streamer) is wired by the producer tickets
 * (`run()`/`submit()`, FF2b/FF5), which construct a `RunResult` with a
 * real downloader bound to the client's auth context. Unit tests inject
 * a small stub. A `RunResult` built WITHOUT a downloader (e.g. in a
 * browser, or any no-I/O context) throws {@see \Gisl\Sdk\Errors\GislSinkError}
 * from its sinks rather than reaching for a global client.
 *
 * Mirrors the TS `Downloader` in `packages/typescript/src/file-first.ts`.
 *
 * STREAMING CONTRACT: implementations MUST stream the URL body to
 * `$destPath` — they MUST NOT buffer the whole output in memory. The
 * signature returns nothing precisely so no buffered-bytes value can leak
 * into the calling convention.
 */
interface Downloader
{
    /**
     * Stream the body at `$url` to the local filesystem path `$destPath`.
     * Implementations create/overwrite `$destPath`. Failures propagate as
     * thrown exceptions (the sink wraps/surfaces them).
     */
    public function downloadTo(string $url, string $destPath): void;
}
