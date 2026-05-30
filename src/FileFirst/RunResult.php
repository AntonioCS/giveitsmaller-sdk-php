<?php

declare(strict_types=1);

namespace Gisl\Sdk\FileFirst;

use Gisl\Sdk\Errors\GislNoSuchKeyError;
use Gisl\Sdk\Errors\GislSinkError;

/**
 * Result of a file-first run — the value the file-first layer's `run()` /
 * `Handle::wait()` / `Handle::result()` return (producers land FF2b/FF5).
 *
 * Coexists with the operation-first {@see \Gisl\Sdk\Ergonomic\Result}
 * (different FQCN) until FF6 removes the operation-first layer. The
 * file-first shape is flatter and adds an always-present per-input
 * partition (`succeeded` / `failed`) so one bad input in a multi-input run
 * doesn't sink the rest.
 *
 * Mirrors the TS `RunResult` class in `packages/typescript/src/file-first.ts`.
 *
 * Field notes:
 *  - `url`: single-output sugar — the lone artifact's URL when exactly one
 *    output exists, else null.
 *  - `ok`: true iff `failed` is empty. (NOT a list — the partition lists are
 *    `succeeded` / `failed`; resolves the design doc's `ok` bool-vs-list
 *    contradiction.)
 *  - `state`: lifecycle state (`completed` | `failed` | ...). Named `state`,
 *    NOT `status`, matching the file-first `StatusSnapshot.state`.
 *  - sinks fetch via the injected {@see Downloader}; a result with no
 *    downloader throws {@see GislSinkError} (reason `downloader_unavailable`).
 */
final class RunResult
{
    /** Single-output sugar: the lone artifact's URL, or null for 0 / >1 outputs. */
    public readonly ?string $url;

    /** True iff {@see $failed} is empty. */
    public readonly bool $ok;

    /**
     * @param list<OutputFile>  $artifacts All outputs produced by the run.
     * @param list<ItemResult>  $succeeded Per-input successes (always present;
     *                                     one entry for a single-recipe run).
     * @param list<ItemFailure> $failed    Per-input failures (empty on full success).
     * @param Downloader|null   $downloader Streams outputs to disk for the sinks;
     *                                      producers inject it, no-I/O contexts omit it.
     */
    public function __construct(
        public readonly string $workflowId,
        public readonly string $state,
        public readonly array $artifacts,
        public readonly array $succeeded,
        public readonly array $failed,
        private readonly ?Downloader $downloader = null,
    ) {
        $this->url = \count($artifacts) === 1 ? $artifacts[0]->url : null;
        $this->ok = $failed === [];
    }

    /**
     * Address a succeeded input by the `key:` given to `file()`. Duplicate
     * keys are not valid input — the producer enforces key uniqueness (a
     * later ticket); the first match is returned.
     *
     * @throws GislNoSuchKeyError when no succeeded entry has that key (a
     *         keyless run always throws — it is positionally addressable only).
     */
    public function byKey(string $key): ItemResult
    {
        foreach ($this->succeeded as $item) {
            if ($item->key === $key) {
                return $item;
            }
        }
        throw new GislNoSuchKeyError("No result for key '{$key}'.");
    }

    /**
     * Write the single output to `$path`. Requires EXACTLY ONE artifact.
     *
     * @throws GislSinkError reason `not_single_output` when the run produced
     *         0 or >1 outputs; reason `downloader_unavailable` when no
     *         downloader is bound.
     */
    public function toFile(string $path): void
    {
        if (\count($this->artifacts) !== 1) {
            throw new GislSinkError(
                'toFile() requires exactly one output; this run produced '
                    . \count($this->artifacts) . '. Use downloadTo() for multi-output runs.',
                reason: 'not_single_output',
            );
        }
        $this->requireDownloader()->downloadTo($this->artifacts[0]->url, $path);
    }

    /**
     * Download every output into `$dir` (filename per output), in output
     * order. Returns the {@see Manifest} of local paths written.
     *
     * @throws GislSinkError reason `partial_failure` when `$failOnPartial`
     *         and the run had failed inputs; reason `downloader_unavailable`
     *         when no downloader is bound.
     */
    public function downloadTo(string $dir, bool $failOnPartial = false): Manifest
    {
        if ($failOnPartial && $this->failed !== []) {
            throw new GislSinkError(
                'downloadTo(failOnPartial: true) but the run had '
                    . \count($this->failed) . ' failed input(s).',
                reason: 'partial_failure',
            );
        }
        if ($dir === '') {
            throw new GislSinkError(
                "downloadTo(): the directory argument is empty. Pass a target directory "
                    . "(use '.' for the current directory).",
                reason: 'invalid_directory',
            );
        }
        $downloader = $this->requireDownloader();
        $base = rtrim($dir, '/\\');
        // Resolve destinations first so a basename collision fails loudly BEFORE
        // any byte is written — silently overwriting an earlier output is data loss.
        // basename() also strips any directory component from a server-supplied
        // filename so a value like "../x" or "a/b" cannot escape $dir.
        $names = array_map(
            static fn (OutputFile $a): string => basename($a->filename),
            $this->artifacts,
        );
        // Collision key is case-folded: many destination filesystems (macOS,
        // NTFS) are case-insensitive, so "a.jpg" and "A.jpg" hit the same file.
        $seen = [];
        foreach ($names as $name) {
            $key = strtolower($name);
            if (isset($seen[$key])) {
                throw new GislSinkError(
                    "downloadTo(): two outputs resolve to the same filename '{$name}' in "
                        . "'{$dir}' (case-insensitively). Download them to separate directories.",
                    reason: 'duplicate_filename',
                );
            }
            $seen[$key] = true;
        }
        $paths = [];
        foreach ($this->artifacts as $i => $artifact) {
            $dest = $base . \DIRECTORY_SEPARATOR . $names[$i];
            $downloader->downloadTo($artifact->url, $dest);
            $paths[] = $dest;
        }
        return new Manifest($paths);
    }

    /**
     * Plain-array projection. Field order fixed to match the TS reference
     * for the cross-language shape assertion (FF1) + harness fixture (FF2b).
     *
     * @return array{
     *     workflowId: string,
     *     state: string,
     *     ok: bool,
     *     url?: string,
     *     artifacts: list<array{url: string, filename: string, sizeBytes: int, operation: string}>,
     *     succeeded: list<array{key: string|null, outputs: list<array{url: string, filename: string, sizeBytes: int, operation: string}>}>,
     *     failed: list<array{key: string|null, error: string}>
     * }
     */
    public function toArray(): array
    {
        $out = [
            'workflowId' => $this->workflowId,
            'state' => $this->state,
            'ok' => $this->ok,
        ];
        // Omit `url` when null so the serialised shape matches the TS
        // reference, where `undefined` is dropped by `JSON.stringify` (same
        // convention as Ergonomic\Artifact::toArray()).
        if ($this->url !== null) {
            $out['url'] = $this->url;
        }
        return $out + [
            'artifacts' => array_map(
                static fn (OutputFile $o): array => $o->toArray(),
                $this->artifacts,
            ),
            'succeeded' => array_map(
                static fn (ItemResult $i): array => $i->toArray(),
                $this->succeeded,
            ),
            'failed' => array_map(
                static fn (ItemFailure $f): array => $f->toArray(),
                $this->failed,
            ),
        ];
    }

    private function requireDownloader(): Downloader
    {
        if ($this->downloader === null) {
            throw new GislSinkError(
                'This result has no downloader bound, so its outputs cannot be '
                    . 'written to disk here (e.g. a browser / no-I/O context). '
                    . 'Fetch each output from its URL instead.',
                reason: 'downloader_unavailable',
            );
        }
        return $this->downloader;
    }
}
