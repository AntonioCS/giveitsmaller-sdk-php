<?php

declare(strict_types=1);

namespace Gisl\Sdk;

use Gisl\Generated\OpenApi\Model\UploadProbeResponse;

/**
 * Aggregated result of {@see GislClient::preflightClips()} — N probes
 * partitioned by outcome so the caller can drop bad clips before
 * submitting a long-form merge workflow.
 *
 * Mirrors the TS `PreflightClipsResult` at `packages/typescript/src/types.ts`.
 * Aggregation is structural — `ok` is everything the server marked
 * workflow-ready (`probe_status: 'ok'`), `rejected` is everything else with
 * a typed probe response (`corrupt`, `unsupported_codec`,
 * `missing_metadata`), and `errors` carries probe calls that themselves
 * failed (e.g. the `feature_not_available` 422 returned while the endpoint
 * is `availability: planned`).
 *
 * `preflightClips()` NEVER throws on a single failed probe: the per-probe
 * failure is captured in `errors` and the surrounding batch keeps
 * progressing.
 */
final class PreflightClipsResult
{
    /**
     * @param list<UploadProbeResponse> $ok       Probes that returned `probe_status: 'ok'`.
     * @param list<UploadProbeResponse> $rejected Probes that returned a non-`ok` status.
     * @param list<PreflightClipError>  $errors   Probe calls that themselves failed.
     */
    public function __construct(
        public readonly array $ok,
        public readonly array $rejected,
        public readonly array $errors,
    ) {
    }
}
