<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

/**
 * Per-job breakdown surfaced on {@see Result::$jobs}. Used for
 * inspecting partial failures: when `status === 'partially_failed'`
 * inspect `operations[]` — each {@see OperationBreakdown} carries
 * `errorCode` / `errorMessage`.
 *
 * Mirrors the TS `JobBreakdown` interface at
 * `packages/typescript/src/builder.ts:123-128`.
 */
final class JobBreakdown
{
    /**
     * @param list<OperationBreakdown> $operations
     */
    public function __construct(
        public readonly string $jobId,
        public readonly string $ref,
        public readonly string $status,
        public readonly array $operations,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'jobId' => $this->jobId,
            'ref' => $this->ref,
            'status' => $this->status,
            'operations' => \array_map(
                static fn (OperationBreakdown $op): array => $op->toArray(),
                $this->operations,
            ),
        ];
    }
}
