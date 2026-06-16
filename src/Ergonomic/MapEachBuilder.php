<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

use Gisl\Sdk\Errors\GislTimeoutError;

/**
 * Fan-out chain over a parent builder's artifacts. Pattern-port of the
 * TS reference at `packages/typescript/src/builder.ts:421-488` (T6).
 *
 * **KNOWN LIMITATION** (mirrors the TS docblock at builder.ts:362-389):
 * the downstream {@see OperationBuilder} constructor today still takes a
 * `string` filesystem path as input — NOT an artifact URL. Passing
 * `$art->url` into a child builder would have `uploadFile()` treat it as
 * a local path. The fan-out cannot consume parent artifacts without
 * out-of-band prefetching the caller does themselves. The proper fix is
 * an artifact-as-input path (chain via `JobOutputSource::from(...)`)
 * that tracks as a follow-up card.
 *
 * P2 ships the SCAFFOLD: the method, this class, the orchestration that
 * fans out the fn. P4 (`OMuSCt7y`) will fill in the artifact-source
 * feature without API churn.
 *
 * Single-output parents degrade gracefully (1 artifact = 1 fn call = 1
 * child run). Multi-output parents (PDF → N pages, future split ops)
 * fan out N child runs. Each child shares the SAME `maxWait` deadline
 * (subtracting elapsed time as it goes).
 *
 * `submit()` is NOT supported on a `MapEachBuilder` — fan-out
 * submit-with-webhook is a future card.
 */
final class MapEachBuilder
{
    /**
     * @param callable(Artifact $artifact): OperationBuilder $fn
     */
    public function __construct(
        private readonly OperationBuilder $parent,
        private readonly mixed $fn,
    ) {
        if (!\is_callable($this->fn)) {
            throw new \InvalidArgumentException('MapEachBuilder fn must be callable.');
        }
    }

    /**
     * Run the parent builder to completion, then fan out the fn over
     * each resulting artifact, sequentially. The deadline covers
     * parent + every child run; each child sees the REMAINING budget.
     */
    public function run(RunOptions $options): Result
    {
        $totalBudgetMs = MaxWait::parse($options->maxWait);
        $deadlineMs = self::nowMs() + $totalBudgetMs;

        // 1. Run the parent against the full deadline. Propagate the
        // cancellation token so a cancel aborts the parent run too.
        $remainingForParent = \max(1, $deadlineMs - self::nowMs());
        $parentResult = $this->parent->run(new RunOptions(
            maxWait: $remainingForParent,
            onProgress: $options->onProgress,
            useSSE: $options->useSSE,
            pollIntervalMs: $options->pollIntervalMs,
            cancellation: $options->cancellation,
            probeBeforeCreate: $options->probeBeforeCreate,
            probeTimeoutMs: $options->probeTimeoutMs,
        ));

        // 2. Fan out the fn over each artifact, sequentially.
        $collectedArtifacts = [];
        $collectedJobs = $parentResult->jobs;
        $childStatuses = [$parentResult->status];
        $childWorkflowIds = [];

        $fn = $this->fn;
        foreach ($parentResult->artifacts as $art) {
            BuilderInternals::throwIfCancelled($options->cancellation, 'fan-out child run');
            $remaining = $deadlineMs - self::nowMs();
            if ($remaining <= 0) {
                throw new GislTimeoutError(
                    'maxWait elapsed during fan-out (after ' . \count($collectedArtifacts) . ' child runs).',
                );
            }
            $childBuilder = $fn($art);
            if (!$childBuilder instanceof OperationBuilder) {
                throw new \LogicException(
                    'MapEachBuilder fn must return an OperationBuilder; got ' . \get_debug_type($childBuilder),
                );
            }
            $childResult = $childBuilder->run(new RunOptions(
                maxWait: $remaining,
                onProgress: $options->onProgress,
                useSSE: $options->useSSE,
                pollIntervalMs: $options->pollIntervalMs,
                cancellation: $options->cancellation,
                probeBeforeCreate: $options->probeBeforeCreate,
                probeTimeoutMs: $options->probeTimeoutMs,
            ));
            foreach ($childResult->artifacts as $a) {
                $collectedArtifacts[] = $a;
            }
            foreach ($childResult->jobs as $j) {
                $collectedJobs[] = $j;
            }
            $childStatuses[] = $childResult->status;
            $childWorkflowIds[] = $childResult->workflowId;
        }

        // 3. Combined result — workflowId stays the parent's; status
        //    aggregates worst-of so a failed child surfaces. Codex TS r1
        //    HIGH 88e7186edc9a — previously always reported parent.status,
        //    masking failed children. childWorkflowIds is recorded on the
        //    PHP side via a sidecar accessor since `Result` is frozen.
        return new Result(
            workflowId: $parentResult->workflowId,
            status: self::aggregateStatus($childStatuses),
            createdAt: $parentResult->createdAt,
            updatedAt: $parentResult->updatedAt,
            artifacts: $collectedArtifacts,
            jobs: $collectedJobs,
            url: \count($collectedArtifacts) === 1 ? $collectedArtifacts[0]->url : null,
            resolvedOptions: $parentResult->resolvedOptions,
        );
    }

    /**
     * Aggregate the worst-of N workflow statuses. Precedence:
     * failed > expired > paused_insufficient_credits > cancelled >
     * partially_failed > completed (anything else falls through as the
     * first listed status — defensive default).
     *
     * @param list<string> $statuses
     */
    private static function aggregateStatus(array $statuses): string
    {
        $order = [
            'failed',
            'expired',
            'paused_insufficient_credits',
            'cancelled',
            'partially_failed',
            'completed',
        ];
        foreach ($order as $candidate) {
            if (\in_array($candidate, $statuses, true)) {
                return $candidate;
            }
        }
        return $statuses[0] ?? 'completed';
    }

    private static function nowMs(): int
    {
        return (int) (\microtime(true) * 1_000);
    }
}
