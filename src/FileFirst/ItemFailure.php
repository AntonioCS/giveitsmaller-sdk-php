<?php

declare(strict_types=1);

namespace Gisl\Sdk\FileFirst;

/**
 * One failed entry in {@see RunResult::$failed}: an input that did not
 * produce a deliverable, paired with the cause. One bad input does not
 * sink the rest of a multi-input run — failures land here.
 *
 * `error` is typed `\Throwable` (not the SDK's `GislError`) for the same
 * reason {@see \Gisl\Sdk\PreflightClipError} is: the surrounding run
 * surfaces whatever was thrown unchanged so the caller can narrow with
 * `instanceof`.
 *
 * Mirrors the TS `ItemFailure` in `packages/typescript/src/file-first.ts`.
 */
final class ItemFailure
{
    public function __construct(
        public readonly ?string $key,
        public readonly \Throwable $error,
    ) {
    }

    /**
     * @return array{key: string|null, error: string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'error' => $this->error->getMessage(),
        ];
    }
}
