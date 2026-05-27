<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

/**
 * Per-job operation status entry. Failure diagnostics live HERE
 * (`OperationResponse.errorCode` / `errorMessage`), NOT on the parent
 * job — mirrors the codex r1 medium fix at
 * `packages/typescript/src/builder.ts:108-116` (previous shape lost
 * failure details on `partially_failed` workflows).
 */
final class OperationBreakdown
{
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $status,
        public readonly ?float $progress = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
    ) {
    }

    /**
     * @return array<string, float|string>
     */
    public function toArray(): array
    {
        $out = [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
        ];
        if ($this->progress !== null) {
            $out['progress'] = $this->progress;
        }
        if ($this->errorCode !== null) {
            $out['errorCode'] = $this->errorCode;
        }
        if ($this->errorMessage !== null) {
            $out['errorMessage'] = $this->errorMessage;
        }
        return $out;
    }
}
