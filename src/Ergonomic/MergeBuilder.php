<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Errors\GislTimeoutError;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\JobDefinitionPayload;
use Gisl\Sdk\OperationDef;
use Gisl\Sdk\Sources;
use Gisl\Sdk\UploadOptions;
use Gisl\Sdk\WorkflowCreatePayload;

/**
 * Merge-compose layer for the SDK ergonomic surface (PHP P3 / dxIeLVbP).
 * Pattern-port of the TS reference at `packages/typescript/src/merge.ts`.
 *
 *     $client->merge([$a, $b, $c], new MergeOptions(transition: 'fade'))
 *         ->sequence([
 *             Merge::asset($a),
 *             Merge::clip($b, new ClipOptions(transition: 'crossfade', crossfadeDuration: 1.5)),
 *             Merge::asset($c),
 *         ])
 *         ->run(new RunOptions(maxWait: '5m'));
 *
 * Separates WHAT (declared asset set) from ORDER (the timeline). Each
 * unique declared asset uploads ONCE per run even when referenced many
 * times in the sequence; without a sequence, declared order is used with
 * no per-input options.
 *
 * Wire-truth boundaries (lowering.md §sequences, mirrored from TS):
 *  - Image merges: NO `per_input_options` (image-merge clips with any
 *    options raise {@see GislConfigError} at plan time); the merge-level
 *    `transition` applies between every join.
 *  - Audio merges: per-input `transition`/`crossfade_duration`/
 *    `gap_duration`; merge-level may also carry `gap_duration`.
 *  - Video merges: per-input `transition`/`crossfade_duration`;
 *    merge-level DROPS `gap_duration` (TS R2 medium ab2422e56ea0).
 *
 * Local validation runs BEFORE any upload (`planSequence()`). Errors are
 * unified under {@see GislConfigError} — the TS reference splits the same
 * cases across three subclasses (`GislUndeclaredAssetError`,
 * `GislUnusedAssetError`, `GislPerInputOptionsNotSupportedError`). The PHP
 * SDK collapses to one class per the P3 ticket scope ("local validation:
 * undeclared ref → GislConfigError; unused declared asset → GislConfigError
 * unless allowUnusedAssets"); the parity-comparator tolerates the
 * class-name divergence via a documented `KNOWN_DIVERGENCES` note.
 *
 * Differences from the TS reference (all PHP-idiomatic):
 *  - No `AbortSignal` — same constraint as {@see OperationBuilder}: only
 *    the wall-clock `maxWait` deadline aborts.
 *  - No `Blob` analogue — assets are filesystem paths or pre-uploaded
 *    file_ids (`HandleAsset`). Bytes-input is out of scope for P3.
 *  - Constructor takes `array $assets` (not variadic) — matches the
 *    rest of the SDK (`compress($input, $options)`) and avoids the TS
 *    reference's runtime-sniff for "is the last arg an options object".
 */
final class MergeBuilder
{
    /** @var list<ClipEntry|Asset>|null */
    private ?array $sequenceEntries = null;

    /**
     * @param list<Asset> $assets Declared asset set. Each unique asset
     *                            uploads once per run.
     */
    public function __construct(
        private readonly GislClient $client,
        private readonly array $assets,
        private readonly MergeOptions $opOptions,
    ) {
    }

    /**
     * Pin the merge play order. Each entry must reference an asset that
     * was declared in the parent `merge(...)` call. Repeats are allowed
     * and deduped on upload (one upload per unique declared asset).
     *
     * @param list<ClipEntry|Asset> $entries
     */
    public function sequence(array $entries): self
    {
        $this->sequenceEntries = $entries;
        return $this;
    }

    public function run(RunOptions $options): Result
    {
        $deadlineMs = BuilderInternals::nowMs() + MaxWait::parse($options->maxWait);
        $onProgress = BuilderInternals::callableOrNull($options->onProgress, 'RunOptions::$onProgress');

        // 1. Validate locally BEFORE any upload.
        $plan = $this->planSequence();

        // 2. Upload each unique asset exactly ONCE. Pass the deadline so the
        // upload loop can abort mid-batch on a slow connection.
        $uploadedByAssetId = $this->uploadUniqueAssets($plan, $onProgress, $deadlineMs);

        if (BuilderInternals::nowMs() >= $deadlineMs) {
            throw new GislTimeoutError(
                'Upload(s) completed but maxWait elapsed before merge workflow could be created.',
            );
        }

        // 3. Build + create the merge workflow.
        $payload = $this->buildPayload($plan, $uploadedByAssetId, callbackUrl: null);
        $created = $this->client->createWorkflow($payload);

        // 4. Wait to terminal status.
        $workflowId = $created->getWorkflowId() ?? '';
        $finalStatus = BuilderInternals::awaitTerminal(
            client: $this->client,
            workflowId: $workflowId,
            deadlineMs: $deadlineMs,
            onProgress: $onProgress,
            useSSE: $options->useSSE,
            pollIntervalMs: $options->pollIntervalMs,
        );

        // 5. Fetch downloads + project.
        if (BuilderInternals::nowMs() >= $deadlineMs) {
            throw new GislTimeoutError(
                "Merge workflow {$workflowId} reached terminal status but maxWait elapsed before downloads could be fetched.",
            );
        }
        $downloads = $this->client->getWorkflowDownloads($workflowId);

        return OperationBuilder::projectResult(
            $finalStatus,
            $downloads->getDownloads() ?? [],
            $this->opOptionsForResolved($plan->mediaKind),
        );
    }

    public function submit(SubmitOptions $options): Handle
    {
        $plan = $this->planSequence();
        $uploadedByAssetId = $this->uploadUniqueAssets($plan, onProgress: null, deadlineMs: null);

        $payload = $this->buildPayload($plan, $uploadedByAssetId, callbackUrl: $options->webhook);
        $created = $this->client->createWorkflow($payload);

        return new Handle(
            workflowId: $created->getWorkflowId() ?? '',
            webhookSecret: $created->getWebhookSecret(),
        );
    }

    // ---------------------------------------------------------------------

    /**
     * Resolve declared assets + sequence (or fall back to declared order),
     * dedupe by identity, and run the local validators.
     */
    private function planSequence(): SequencePlan
    {
        // Build the declared-asset map keyed by identity.
        $declared = [];
        foreach ($this->assets as $a) {
            $id = self::assetIdentity($a);
            if (!isset($declared[$id])) {
                $declared[$id] = $a;
            }
        }

        // Use the explicit sequence if set; otherwise the declared order as-is.
        $rawEntries = $this->sequenceEntries ?? array_values($this->assets);

        $mediaKind = $this->inferMediaKind();
        /** @var list<PositionEntry> $positions */
        $positions = [];
        /** @var array<string, true> $refIds */
        $refIds = [];
        foreach ($rawEntries as $entry) {
            $isClip = $entry instanceof ClipEntry;
            $assetRef = $isClip ? $entry->asset : $entry;
            $id = self::assetIdentity($assetRef);
            if (!isset($declared[$id])) {
                throw new GislConfigError(sprintf(
                    'merge sequence references asset %s which was not declared. Declared: %s',
                    $id,
                    implode(', ', array_keys($declared)),
                ));
            }
            $refIds[$id] = true;
            if ($isClip) {
                $opts = $entry->options;
                if (!$opts->isEmpty() && $mediaKind === 'image') {
                    throw new GislConfigError(
                        'image merges do not support per-input clip options today (transition/crossfadeDuration/gapDuration). '
                        . 'Use the merge-level transition on MergeOptions for image merges.',
                    );
                }
                $positions[] = new PositionEntry($id, $opts);
            } else {
                $positions[] = new PositionEntry($id, new ClipOptions());
            }
        }

        // Unused-asset check — only when sequence is set AND not bypassed.
        if ($this->sequenceEntries !== null && $this->opOptions->allowUnusedAssets !== true) {
            $unused = array_values(array_diff(array_keys($declared), array_keys($refIds)));
            if (\count($unused) > 0) {
                throw new GislConfigError(sprintf(
                    'merge declared assets that were never referenced in sequence(): %s. '
                    . 'Either reference them, drop them from merge(), or pass MergeOptions(allowUnusedAssets: true).',
                    implode(', ', $unused),
                ));
            }
        }

        // Restrict upload set to sequenced assets when allowUnusedAssets bypassed the check.
        // Mirrors TS R1 medium edb1bb641d81 — saves wasted bandwidth.
        $uploadSet = $this->sequenceEntries === null
            ? $declared
            : array_intersect_key($declared, $refIds);

        // Enforce merge schema input bounds (min_inputs: 2, max_inputs: 10).
        // Counted on positions (repeats included), not unique assets.
        // Mirrors TS R1 medium 5c86b67c979b.
        $positionCount = \count($positions);
        if ($positionCount < 2) {
            throw new GislConfigError(
                "merge requires at least 2 inputs (got {$positionCount}). Declare more assets or check the sequence.",
            );
        }
        if ($positionCount > 10) {
            throw new GislConfigError(
                "merge accepts at most 10 inputs (got {$positionCount}). Reduce the sequence or split the merge.",
            );
        }

        // Validate merge-level options BEFORE upload. Codex R1 medium
        // a93bd61d39a9 — parseSizeString used to fire from buildPayload()
        // AFTER uploadUniqueAssets, so a `targetSize: 'garbage'` typo would
        // burn N uploads before the throw.
        if ($this->opOptions->targetSize !== null && \is_string($this->opOptions->targetSize)) {
            self::parseSizeString($this->opOptions->targetSize);
        }

        // Image merges require an `output_type` (per the generated merge schema
        // at `generated/typescript/operations/schemas/merge.yaml` — image kind
        // marks `output_type` required). Codex R1 medium b53ad6d22f53 — without
        // this check, the SDK would upload all assets then receive a server-
        // side validation failure instead of a free local failure.
        if ($mediaKind === 'image'
            && $this->opOptions->output === null
            && $this->opOptions->outputType === null
        ) {
            throw new GislConfigError(
                'image merges require an explicit output_type — set MergeOptions(output: "video"|"gif") or '
                . 'MergeOptions(outputType: ...). The server rejects image merge requests with no output_type.',
            );
        }

        return new SequencePlan($mediaKind, $positions, $uploadSet);
    }

    /**
     * @return "video"|"audio"|"image"
     */
    private function inferMediaKind(): string
    {
        if ($this->opOptions->mediaKind !== null) {
            return $this->opOptions->mediaKind;
        }
        $first = $this->assets[0] ?? null;
        if ($first instanceof PathAsset) {
            $lower = strtolower($first->path);
            if (preg_match('/\.(jpe?g|png|webp|avif|gif|heic|tiff?)$/', $lower) === 1) {
                return 'image';
            }
            if (preg_match('/\.(mp3|wav|flac|aac|ogg|m4a)$/', $lower) === 1) {
                return 'audio';
            }
            return 'video';
        }
        return 'video';
    }

    /**
     * Upload each unique asset in `$plan->uniqueAssets` exactly once.
     * Handles short-circuit to their pre-uploaded `file_id`. Paths route
     * through `GislClient::uploadFile()`. Checks `$deadlineMs` between
     * uploads when supplied (TS R1 medium 797b4113431f).
     *
     * @param \Closure(ProgressEvent): void|null $onProgress
     * @return array<string, string>  Asset-id → uploaded `file_id`.
     */
    private function uploadUniqueAssets(
        SequencePlan $plan,
        ?\Closure $onProgress,
        ?int $deadlineMs,
    ): array {
        $uploaded = [];
        $total = \count($plan->uniqueAssets);
        $done = 0;
        foreach ($plan->uniqueAssets as $id => $asset) {
            if ($deadlineMs !== null && BuilderInternals::nowMs() >= $deadlineMs) {
                throw new GislTimeoutError(
                    "maxWait elapsed mid-upload (after {$done} of {$total} merge assets).",
                );
            }
            if ($asset instanceof HandleAsset) {
                $uploaded[$id] = $asset->fileId;
                $done++;
                continue;
            }
            /** @var PathAsset $asset */
            $uploadOpts = null;
            if ($onProgress !== null) {
                $uploadOpts = new UploadOptions(
                    onProgress: static function (int $uploadedBytes, int $totalBytes) use ($onProgress): void {
                        $onProgress(new UploadProgressEvent($uploadedBytes, $totalBytes));
                    },
                );
            }
            $resp = $this->client->uploadFile($asset->path, $uploadOpts);
            $uploaded[$id] = $resp->getFileId() ?? '';
            $done++;
        }
        return $uploaded;
    }

    /**
     * Build the merge `WorkflowCreatePayload`. Emits a single
     * multi-input `JobDefinitionPayload`:
     *  - `inputs[]` carries one entry per SEQUENCE POSITION (repeats
     *    included) with `source: {type: upload, file_id}` and the
     *    media-kind-appropriate `per_input_options`.
     *  - `operations[0]` is `{type: merge, options: <merge-level opts>}`.
     *
     * @param array<string, string> $uploadedByAssetId
     */
    private function buildPayload(
        SequencePlan $plan,
        array $uploadedByAssetId,
        ?string $callbackUrl,
    ): WorkflowCreatePayload {
        $inputs = [];
        foreach ($plan->positions as $pos) {
            if (!isset($uploadedByAssetId[$pos->assetId])) {
                // Defensive — planSequence should have rejected this.
                throw new \LogicException(
                    "Asset '{$pos->assetId}' was never uploaded — internal MergeBuilder bug.",
                );
            }
            $input = ['source' => Sources::upload($uploadedByAssetId[$pos->assetId])];
            // Image merges never emit per_input_options (planSequence already
            // rejects opts on image-merge clips). Video/audio emit a
            // narrowed projection by media kind.
            if ($plan->mediaKind !== 'image') {
                $wireOpts = self::wirePerInputOptions($pos->options, $plan->mediaKind);
                if (\count($wireOpts) > 0) {
                    $input['per_input_options'] = $wireOpts;
                }
            }
            $inputs[] = $input;
        }

        $mergeOpts = self::wireMergeOptions($this->opOptions, $plan->mediaKind);

        $job = new JobDefinitionPayload(
            operations: [new OperationDef(type: 'merge', options: $mergeOpts)],
            id: 'merge',
            inputs: $inputs,
        );

        return new WorkflowCreatePayload(
            jobs: [$job],
            callbackUrl: $callbackUrl,
        );
    }

    /**
     * Strip SDK-only fields before exposing on `resolvedOptions.applied`.
     * Codex R1 medium 4b583705c2f9 — drop fields the wire silently
     * filtered (e.g. `gap_duration` on video/image), so callers don't see
     * a "the option was applied" report for an option that never crossed
     * the wire.
     *
     * @param "video"|"audio"|"image" $mediaKind
     * @return array<string, mixed>
     */
    private function opOptionsForResolved(string $mediaKind): array
    {
        // Mirror `wireMergeOptions`'s per-media allowlist so the caller's
        // "applied" view tracks what actually crossed the wire. Codex R1
        // medium 4b583705c2f9 + R2 medium a5aa664e6c74.
        $out = [];
        if ($this->opOptions->output !== null) {
            $out['output'] = $this->opOptions->output;
        }
        if ($this->opOptions->outputType !== null) {
            $out['outputType'] = $this->opOptions->outputType;
        }
        if ($this->opOptions->transition !== null) {
            $out['transition'] = $this->opOptions->transition;
        }

        if ($mediaKind === 'video' || $mediaKind === 'audio') {
            if ($this->opOptions->crossfadeDuration !== null) {
                $out['crossfadeDuration'] = $this->opOptions->crossfadeDuration;
            }
            if ($this->opOptions->normalizeAudio !== null) {
                $out['normalizeAudio'] = $this->opOptions->normalizeAudio;
            }
        }

        if ($mediaKind === 'audio' && $this->opOptions->gapDuration !== null) {
            $out['gapDuration'] = $this->opOptions->gapDuration;
        }

        if ($mediaKind === 'video') {
            if ($this->opOptions->codec !== null) {
                $out['codec'] = $this->opOptions->codec;
            }
            if ($this->opOptions->crf !== null) {
                $out['crf'] = $this->opOptions->crf;
            }
            if ($this->opOptions->preset !== null) {
                $out['preset'] = $this->opOptions->preset;
            }
            if ($this->opOptions->targetSize !== null) {
                $out['targetSize'] = $this->opOptions->targetSize;
            }
        }

        if ($mediaKind === 'image') {
            if ($this->opOptions->transitionDuration !== null) {
                $out['transitionDuration'] = $this->opOptions->transitionDuration;
            }
            if ($this->opOptions->fps !== null) {
                $out['fps'] = $this->opOptions->fps;
            }
            if ($this->opOptions->durationPerImage !== null) {
                $out['durationPerImage'] = $this->opOptions->durationPerImage;
            }
            if ($this->opOptions->loopCount !== null) {
                $out['loopCount'] = $this->opOptions->loopCount;
            }
            if ($this->opOptions->videoFormat !== null) {
                $out['videoFormat'] = $this->opOptions->videoFormat;
            }
        }

        return $out;
    }

    /**
     * Asset identity for dedupe. Handles use their fileId; paths use the
     * EXACT caller-provided string (no trim, no trailing-slash strip).
     * Mirrors TS R2 medium bb500566a683 — exact-string ensures the upload
     * call's path matches the dedupe identity character-for-character.
     */
    private static function assetIdentity(Asset $a): string
    {
        if ($a instanceof HandleAsset) {
            return "handle:{$a->fileId}";
        }
        if ($a instanceof PathAsset) {
            return "path:{$a->path}";
        }
        // Unreachable — the Asset interface is sealed via the Merge factory.
        throw new \LogicException('Unknown Asset variant: ' . get_class($a));
    }

    /**
     * Project the merge-level option bag into the wire shape per media
     * kind. `gap_duration` is audio-only at the merge level (TS R2 medium
     * ab2422e56ea0).
     *
     * @param "video"|"audio"|"image" $mediaKind
     * @return array<string, mixed>
     */
    private static function wireMergeOptions(MergeOptions $opts, string $mediaKind): array
    {
        // Per-media wire allowlists (mirrors generated/typescript/operations/merge.ts):
        //   video: output_type, transition, crossfade_duration, normalize_audio,
        //          codec, crf, preset, target_size_bytes, encoding_mode
        //   audio: output_type, transition, crossfade_duration, gap_duration, normalize_audio
        //   image: output_type, transition, transition_duration, fps,
        //          duration_per_image, loop_count, video_format
        // Codex R2 medium a5aa664e6c74 — without per-media gating, callers
        // hit a server-side 422 only AFTER paying for uploads. Drop the
        // disallowed fields locally instead.
        $out = [];
        if ($opts->output !== null) {
            $out['output_type'] = $opts->output;
        }
        if ($opts->outputType !== null) {
            $out['output_type'] = $opts->outputType;
        }
        if ($opts->transition !== null) {
            $out['transition'] = $opts->transition;
        }

        if ($mediaKind === 'video' || $mediaKind === 'audio') {
            if ($opts->crossfadeDuration !== null) {
                $out['crossfade_duration'] = $opts->crossfadeDuration;
            }
            if ($opts->normalizeAudio !== null) {
                $out['normalize_audio'] = $opts->normalizeAudio;
            }
        }

        if ($mediaKind === 'audio' && $opts->gapDuration !== null) {
            $out['gap_duration'] = $opts->gapDuration;
        }

        if ($mediaKind === 'video') {
            if ($opts->codec !== null) {
                $out['codec'] = $opts->codec;
            }
            if ($opts->crf !== null) {
                $out['crf'] = $opts->crf;
            }
            if ($opts->preset !== null) {
                $out['preset'] = $opts->preset;
            }
            if ($opts->targetSize !== null) {
                $out['target_size_bytes'] = \is_int($opts->targetSize)
                    ? $opts->targetSize
                    : self::parseSizeString($opts->targetSize);
                $out['encoding_mode'] = 'target_size';
            }
        }

        if ($mediaKind === 'image') {
            if ($opts->transitionDuration !== null) {
                $out['transition_duration'] = $opts->transitionDuration;
            }
            if ($opts->fps !== null) {
                $out['fps'] = $opts->fps;
            }
            if ($opts->durationPerImage !== null) {
                $out['duration_per_image'] = $opts->durationPerImage;
            }
            if ($opts->loopCount !== null) {
                $out['loop_count'] = $opts->loopCount;
            }
            if ($opts->videoFormat !== null) {
                $out['video_format'] = $opts->videoFormat;
            }
        }

        return $out;
    }

    /**
     * Project a {@see ClipOptions} into the per-input wire shape.
     * `gap_duration` is audio-only on per-input too (TS R1 medium 128404fa16a9).
     *
     * @param "video"|"audio"|"image" $mediaKind  (image is excluded upstream)
     * @return array<string, mixed>
     */
    private static function wirePerInputOptions(ClipOptions $opts, string $mediaKind): array
    {
        $out = [];
        if ($opts->transition !== null) {
            $out['transition'] = $opts->transition;
        }
        if ($opts->crossfadeDuration !== null) {
            $out['crossfade_duration'] = $opts->crossfadeDuration;
        }
        if ($opts->gapDuration !== null && $mediaKind === 'audio') {
            $out['gap_duration'] = $opts->gapDuration;
        }
        return $out;
    }

    private static function parseSizeString(string $s): int
    {
        $trimmed = trim($s);
        if (preg_match('/^(\d+(?:\.\d+)?)\s*(KB|MB|GB|B)?$/i', $trimmed, $m) !== 1) {
            throw new GislConfigError("Invalid targetSize string '{$s}' — expected '<num>[B|KB|MB|GB]'.");
        }
        $n = (float) $m[1];
        $unit = strtoupper($m[2] ?? 'B');
        return match ($unit) {
            'B' => (int) round($n),
            'KB' => (int) round($n * 1_000),
            'MB' => (int) round($n * 1_000_000),
            'GB' => (int) round($n * 1_000_000_000),
            default => throw new GislConfigError("Unknown size unit '{$unit}'."),
        };
    }
}
