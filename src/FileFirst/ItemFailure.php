<?php

declare(strict_types=1);

namespace Gisl\Sdk\FileFirst;

use Gisl\Sdk\Errors\GislItemFailedError;

/**
 * One failed entry in {@see RunResult::$failed}: an input that did not
 * produce a deliverable, paired with the cause. One bad input does not
 * sink the rest of a multi-input run — failures land here.
 *
 * `error` is a typed {@see GislItemFailedError} carrying the terminal `state`
 * plus the failing operation's `errorMessage`/`errorCode` (when present), so the
 * caller can branch on the failure WITHOUT string-parsing.
 *
 * Mirrors the TS `ItemFailure` in `packages/typescript/src/file-first.ts`.
 */
final class ItemFailure
{
    public function __construct(
        public readonly ?string $key,
        public readonly GislItemFailedError $error,
    ) {
    }

    /**
     * Field order (key, error, state, errorMessage?, errorCode?) matches the TS
     * `RunResult.toJSON()` failed[] projection so JSON-string parity holds; the
     * two optional keys are OMITTED when null (cancel/expire carry only state) —
     * never emitted as `null` — mirroring the `url` omit in {@see RunResult::toArray()}.
     *
     * @return array{key: string|null, error: string, state: string, errorMessage?: string, errorCode?: string}
     */
    public function toArray(): array
    {
        $out = [
            'key' => $this->key,
            'error' => $this->error->getMessage(),
            'state' => $this->error->state,
        ];
        if ($this->error->errorMessage !== null) {
            $out['errorMessage'] = $this->error->errorMessage;
        }
        if ($this->error->errorCode !== null) {
            $out['errorCode'] = $this->error->errorCode;
        }

        return $out;
    }
}
