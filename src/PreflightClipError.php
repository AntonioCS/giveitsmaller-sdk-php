<?php

declare(strict_types=1);

namespace Gisl\Sdk;

/**
 * One row in {@see PreflightClipsResult::$errors}: a probe call that
 * itself threw, paired with the `fileId` that triggered it.
 *
 * Mirrors the TS `PreflightClipError` at `packages/typescript/src/types.ts`.
 * `error` is typed as `\Throwable` rather than the SDK's `GislError` —
 * transport failures wrap as `GislNetworkError`, but a stub HTTP client in
 * tests may raise any `ClientExceptionInterface` and the aggregator
 * surfaces whatever was thrown unchanged so the caller can narrow with
 * `instanceof GislFeatureNotAvailableError` and friends.
 */
final class PreflightClipError
{
    public function __construct(
        public readonly string $fileId,
        public readonly \Throwable $error,
    ) {
    }
}
