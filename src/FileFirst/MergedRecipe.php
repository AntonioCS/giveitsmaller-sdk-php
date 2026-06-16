<?php

declare(strict_types=1);

namespace Gisl\Sdk\FileFirst;

use Gisl\Generated\OpenApi\Model\WorkflowCreateResponse;
use Gisl\Sdk\Cancellation;
use Gisl\Sdk\Ergonomic\BuilderInternals;
use Gisl\Sdk\Ergonomic\Handle;
use Gisl\Sdk\Ergonomic\MaxWait;
use Gisl\Sdk\Ergonomic\MergeBuilder;
use Gisl\Sdk\Ergonomic\MergeOptions;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Errors\GislTimeoutError;
use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\Http\UploadSource;
use Gisl\Sdk\JobDefinitionPayload;
use Gisl\Sdk\OperationDef;
use Gisl\Sdk\PresetDefaults;
use Gisl\Sdk\Sources;
use Gisl\Sdk\Ergonomic\UploadProgressEvent;
use Gisl\Sdk\UploadOptions;
use Gisl\Sdk\WorkflowCreatePayload;

/**
 * The single-output recipe you're in AFTER a fluent `files([...])->merge(...)`
 * (FF3b). Merge collapses the N inputs into ONE output, so the per-file ops
 * ({@see FilesRecipe::compress()} etc.) no longer apply — instead this exposes
 * the SAME chain ops as the single-file {@see Recipe}, applied to the merged
 * result. `files([...])->merge()->compress()` is the flagship case (example 14).
 *
 * **Lowering (one workflow):** each input is uploaded once and wrapped in its
 * own single-input `passthrough` source job (`src_N`); the `merge` job consumes
 * those via `job_output` inputs (array order = play order) and carries the merge
 * op FIRST in its `operations[]`, followed by any post-combine ops (compress /
 * convert / thumbnail) so they run on the merged output in the same job. The
 * merge-level wire options reuse {@see MergeBuilder::wireMergeOptions()} so a
 * fluent merge lowers identically to the operation-first `client->merge()`.
 *
 * Immutable / clone-on-write like {@see Recipe} / {@see FilesRecipe}. Mirrors
 * the TS `MergedRecipe` in `packages/typescript/src/file-first.ts`.
 */
final class MergedRecipe
{
    /**
     * @param list<FileInput>  $inputs    Ordered inputs being combined (play order).
     * @param list<RecipeStep> $postSteps Ops applied to the merged output, in order.
     */
    public function __construct(
        private readonly array $inputs,
        private readonly MergeOptions $mergeOptions,
        private readonly array $postSteps = [],
        private readonly ?PresetDefaults $presetDefaults = null,
        private readonly ?PresetDefaults $scopedPresetDefaults = null,
        private readonly ?GislClient $client = null,
    ) {
    }

    /**
     * Reduce the merged output's size. See {@see Recipe::compress()}. The
     * explicit `$optimize` param wins over any `optimize` key in `$options`.
     *
     * @param array<string, mixed> $options
     */
    public function compress(OptimizeFor|string|null $optimize = null, array $options = []): self
    {
        // Shorthand $optimize wins; otherwise preserve a bag-supplied `optimize`
        // (mirrors Recipe::compress / op-first — omitting it must not null a preset).
        $options['optimize'] = Recipe::coerceOptimize($optimize ?? ($options['optimize'] ?? null));
        return $this->withStep(new RecipeStep('compress', $options));
    }

    /**
     * Change the merged output's format. See {@see Recipe::convert()}.
     *
     * @param array<string, mixed> $options
     */
    public function convert(string $format, array $options = []): self
    {
        // The convert op's wire key is `output_format` (contract: convert.yaml),
        // NOT `format`. Spread options FIRST so the explicit shorthand wins.
        // The shorthand owns the format → a stray legacy `format` key in the bag
        // is not a valid convert option; drop it so the wire never carries both.
        unset($options['format']);
        return $this->withStep(new RecipeStep('convert', [...$options, 'output_format' => $format]));
    }

    /**
     * Thumbnail the merged output.
     *
     * @param array<string, mixed> $options
     */
    public function thumbnail(array $options = []): self
    {
        $wire = [];
        foreach ($options as $key => $value) {
            if ($value !== null) {
                $wire[$key] = $value;
            }
        }
        return $this->withStep(new RecipeStep('thumbnail', $wire));
    }

    private function withStep(RecipeStep $step): self
    {
        return new self(
            $this->inputs,
            $this->mergeOptions,
            [...$this->postSteps, $step],
            $this->presetDefaults,
            $this->scopedPresetDefaults,
            $this->client,
        );
    }

    /**
     * Execute end-to-end: upload every input, create the merge workflow, await
     * a terminal state (SSE with poll fallback), then resolve ONLY the merged
     * output into a {@see RunResult}. Throws {@see GislTimeoutError} on `$maxWait`.
     *
     * @param (callable(\Gisl\Sdk\Ergonomic\ProgressEvent): void)|null $onProgress
     * @param bool|null $probeBeforeCreate Best-effort probe-before-create for the
     *        VIDEO inputs that went multipart (default true). Pass false to skip.
     * @param int|null $probeTimeoutMs Aggregate timeout (ms) for the probe waits.
     */
    public function run(
        string|int|null $maxWait = null,
        ?callable $onProgress = null,
        ?int $pollIntervalMs = null,
        ?Cancellation $cancellation = null,
        ?bool $probeBeforeCreate = null,
        ?int $probeTimeoutMs = null,
    ): RunResult {
        $client = $this->requireClient();
        $deadlineMs = BuilderInternals::nowMs() + MaxWait::parse($maxWait ?? 300_000);
        $onProgressClosure = BuilderInternals::callableOrNull($onProgress, 'MergedRecipe::run() $onProgress');

        $created = $this->uploadAllAndCreate(
            null,
            $deadlineMs,
            $onProgressClosure,
            $cancellation,
            $probeBeforeCreate,
            $probeTimeoutMs,
        );
        $workflowId = $created->getWorkflowId() ?? '';

        $finalStatus = BuilderInternals::awaitTerminal(
            client: $client,
            workflowId: $workflowId,
            deadlineMs: $deadlineMs,
            onProgress: $onProgressClosure,
            useSSE: true,
            pollIntervalMs: $pollIntervalMs,
            cancellation: $cancellation,
        );

        BuilderInternals::throwIfCancelled($cancellation, 'downloads fetch');
        if (BuilderInternals::nowMs() >= $deadlineMs) {
            throw new GislTimeoutError(
                "Workflow {$workflowId} reached terminal status but maxWait elapsed before downloads could be fetched.",
            );
        }
        $downloads = $client->getWorkflowDownloads($workflowId);
        // TDqmkWpX: the maxWait deadline also covers the downloads fetch itself —
        // re-check AFTER the call so a slow getWorkflowDownloads cannot return a
        // success past the advertised whole-run deadline.
        if (BuilderInternals::nowMs() >= $deadlineMs) {
            throw new GislTimeoutError(
                "Workflow {$workflowId} downloads fetch completed after maxWait elapsed.",
            );
        }

        // Project ONLY the merge job's output — the `src_*` passthrough jobs
        // re-expose the raw uploads, which are plumbing, not the deliverable.
        $mergeDownloads = \array_values(\array_filter(
            $downloads->getDownloads() ?? [],
            static fn ($d): bool => BuilderInternals::coerceString($d->getRef()) === 'merge',
        ));

        return RunResult::fromTerminalDownloads(
            workflowId: $workflowId,
            finalStatus: $finalStatus,
            jobDownloads: $mergeDownloads,
            key: null,
            downloader: new StreamingDownloader(),
        );
    }

    /**
     * Fire-and-forget: upload + create the merge workflow (wiring `$webhook` into
     * `callback_url` when given), return a client-bound {@see Handle}. Mirrors
     * {@see Recipe::submit()}.
     *
     * @param bool|null $probeBeforeCreate Best-effort probe-before-create for the
     *        VIDEO inputs that went multipart (default true). Pass false to skip.
     * @param int|null $probeTimeoutMs Aggregate timeout (ms) for the probe waits.
     */
    public function submit(
        ?string $webhook = null,
        ?bool $probeBeforeCreate = null,
        ?int $probeTimeoutMs = null,
    ): Handle {
        $this->requireClient();
        $created = $this->uploadAllAndCreate($webhook, null, null, null, $probeBeforeCreate, $probeTimeoutMs);

        return new Handle(
            workflowId: $created->getWorkflowId() ?? '',
            webhookSecret: $created->getWebhookSecret(),
            client: $this->client,
        );
    }

    // ---------------------------------------------------------------------

    private function requireClient(): GislClient
    {
        if ($this->client === null) {
            throw new GislConfigError(
                'MergedRecipe requires a client; build via Gisl::create()->files(...)->merge(...) rather than constructing it directly.',
                reason: 'no_client',
            );
        }
        return $this->client;
    }

    /**
     * Upload every input once, then create ONE merge workflow.
     *
     * @param (\Closure(\Gisl\Sdk\Ergonomic\UploadProgressEvent): void)|null $onProgressClosure
     */
    private function uploadAllAndCreate(
        ?string $webhook,
        ?int $deadlineMs,
        ?\Closure $onProgressClosure,
        ?Cancellation $cancellation,
        ?bool $probeBeforeCreate = null,
        ?int $probeTimeoutMs = null,
    ): WorkflowCreateResponse {
        $client = $this->requireClient();
        $this->validatePreUpload();

        // Preflight every input before uploading any (mirrors FilesRecipe).
        foreach ($this->inputs as $input) {
            if ($input->kind === FileInput::KIND_RESOURCE) {
                UploadSource::assertUploadableStream($input->resource);
                // fFwaKsN5 (codex r2): validate the resource hints up front so a
                // bad hint on a later input fails before earlier inputs upload.
                UploadOptions::assertHintsValid($input->contentType, $input->filename);
            } elseif ($input->kind === FileInput::KIND_PATH) {
                UploadSource::fromPath(BuilderInternals::coerceString($input->path));
            }
        }

        $fileIds = [];
        // Per-input video detection: merge's inferMediaKind decides the OUTPUT
        // media, not each input's, so detect per input via compressMediaHint().
        // A pre-uploaded id carries no local mime/size, so it is never probed.
        /** @var list<array{fileId: string, isVideo: bool, sizeBytes: int|null}> $probeTargets */
        $probeTargets = [];
        foreach ($this->inputs as $input) {
            BuilderInternals::throwIfCancelled($cancellation, 'merge upload');
            if ($deadlineMs !== null && BuilderInternals::nowMs() >= $deadlineMs) {
                throw new GislTimeoutError('maxWait elapsed during merge uploads before all inputs were uploaded.');
            }
            if ($input->kind === FileInput::KIND_UPLOAD_ID) {
                $fileIds[] = BuilderInternals::coerceString($input->fileId);
                continue;
            }
            $onProgressUpload = $onProgressClosure !== null
                ? static function (int $u, int $t) use ($onProgressClosure): void {
                    $onProgressClosure(new UploadProgressEvent($u, $t));
                }
                : null;
            $uploadTarget = BuilderInternals::coerceString($input->path);
            $uploadOpts = $onProgressUpload !== null ? new UploadOptions(onProgress: $onProgressUpload) : null;
            if ($input->kind === FileInput::KIND_RESOURCE) {
                \assert(\is_resource($input->resource));
                $uploadTarget = $input->resource;
                // fFwaKsN5 (codex r1): carry the resource's filename/contentType
                // hints into the merge input upload too.
                $uploadOpts = new UploadOptions(
                    onProgress: $onProgressUpload,
                    contentType: $input->contentType,
                    filename: $input->filename,
                );
            }
            $resp = $client->uploadFile($uploadTarget, $uploadOpts);
            $fileIds[] = $resp->getFileId() ?? '';
            $probeTargets[] = [
                'fileId' => $resp->getFileId() ?? '',
                'isVideo' => $input->compressMediaHint() === 'video',
                'sizeBytes' => $resp->getSizeBytes(),
            ];
        }

        BuilderInternals::throwIfCancelled($cancellation, 'merge workflow creation');
        if ($deadlineMs !== null && BuilderInternals::nowMs() >= $deadlineMs) {
            throw new GislTimeoutError('Uploads completed but maxWait elapsed before the merge workflow could be created.');
        }

        // Best-effort probe-before-create for the multipart-video inputs
        // (sequential with a shared budget; never-bounce). The total budget is
        // capped to the remaining maxWait (run() passes $deadlineMs; submit()
        // passes null → uncapped) so the waits cannot push createWorkflow past
        // the caller's deadline.
        BuilderInternals::waitForVideoProbes($client, $probeTargets, $probeBeforeCreate, $probeTimeoutMs, $cancellation, $deadlineMs);
        // A cancel arriving during a FINAL successful probe request must not
        // still create the workflow (the probe waits return landed without a
        // final cancel re-check), so check here BEFORE createWorkflow.
        BuilderInternals::throwIfCancelled($cancellation, 'merge workflow creation');
        // RE-CHECK the deadline AFTER the probe waits (they consume time).
        if ($deadlineMs !== null && BuilderInternals::nowMs() >= $deadlineMs) {
            throw new GislTimeoutError('Probe wait completed but maxWait elapsed before the merge workflow could be created.');
        }

        return $client->createWorkflow($this->toWorkflowPayload($fileIds, $webhook));
    }

    /**
     * Reject an invalid combine BEFORE any upload fires — mirrors the operation-
     * first {@see MergeBuilder} bounds so a typo'd merge costs no bandwidth:
     * 2–10 inputs (merge schema `min/max_inputs`), and an image merge must carry
     * an explicit `output_type` (the server rejects image merges without one).
     */
    private function validatePreUpload(): void
    {
        $count = \count($this->inputs);
        if ($count < 2) {
            throw new GislConfigError(
                "merge requires at least 2 inputs to combine (got {$count}).",
                reason: 'too_few_inputs',
            );
        }
        if ($count > 10) {
            throw new GislConfigError(
                "merge accepts at most 10 inputs (got {$count}). Split the merge or reduce the input list.",
                reason: 'too_many_inputs',
            );
        }
        if (
            $this->inferMediaKind() === 'image'
            && $this->mergeOptions->output === null
            && $this->mergeOptions->outputType === null
        ) {
            throw new GislConfigError(
                'image merges require an explicit output_type — set MergeOptions(output: "video"|"gif") '
                . 'or MergeOptions(outputType: ...). The server rejects image merge requests with no output_type.',
                reason: 'image_merge_requires_output_type',
            );
        }
    }

    /**
     * Lower to the merge DAG: one `passthrough` source job per input + one
     * `merge` job whose `operations[]` is `[merge, ...post-combine ops]`.
     *
     * @param list<string> $fileIds One uploaded file id per input, in order.
     */
    public function toWorkflowPayload(array $fileIds, ?string $callbackUrl = null): WorkflowCreatePayload
    {
        $mediaKind = $this->inferMediaKind();

        $sourceJobs = [];
        $inputs = [];
        foreach ($fileIds as $i => $fileId) {
            $srcId = 'src_' . $i;
            $sourceJobs[] = new JobDefinitionPayload(
                operations: [new OperationDef(type: 'passthrough')],
                id: $srcId,
                source: Sources::upload($fileId),
            );
            $inputs[] = ['source' => Sources::jobOutput($srcId)];
        }

        $operations = [
            new OperationDef(type: 'merge', options: MergeBuilder::wireMergeOptions($this->mergeOptions, $mediaKind)),
            ...$this->lowerPostSteps($mediaKind),
        ];

        $mergeJob = new JobDefinitionPayload(
            operations: $operations,
            id: 'merge',
            inputs: $inputs,
        );

        return new WorkflowCreatePayload(jobs: [...$sourceJobs, $mergeJob], callbackUrl: $callbackUrl);
    }

    /**
     * Lower the post-combine chain by composing a single-file {@see Recipe} over
     * a synthetic input whose extension matches the merged OUTPUT media — so
     * `compress(optimize: ...)` resolves the correct preset for the merged
     * result (it needs a media hint, which a merge output carries no filename
     * for). Reuses Recipe's `lowerStep` rather than duplicating it.
     *
     * @param "video"|"audio"|"image" $mediaKind
     * @return list<OperationDef>
     */
    private function lowerPostSteps(string $mediaKind): array
    {
        if ($this->postSteps === []) {
            return [];
        }
        $synthetic = FileInput::path('merged.' . $this->outputExtensionFor($mediaKind));
        $recipe = new Recipe($synthetic, null, $this->postSteps, $this->presetDefaults, $this->scopedPresetDefaults);
        return $recipe->toWorkflowPayload('merged')->jobs[0]->operations;
    }

    /**
     * The merged-output media. Honours an explicit
     * {@see MergeOptions::$mediaKind}; otherwise infers from the first input
     * carrying a media SIGNAL — a path extension, or a resource's `contentType`
     * (MIME-first) / `filename` hints (fFwaKsN5, mirrors the TS Blob branch);
     * a hint-less resource / bare upload id carries no signal and is skipped.
     * Defaults to video.
     *
     * @return "video"|"audio"|"image"
     */
    private function inferMediaKind(): string
    {
        if ($this->mergeOptions->mediaKind !== null) {
            return $this->mergeOptions->mediaKind;
        }
        foreach ($this->inputs as $input) {
            if ($input->kind === FileInput::KIND_PATH && \is_string($input->path)) {
                return $this->mergeMediaFromExtension(\strtolower($input->path));
            }
            if ($input->kind === FileInput::KIND_RESOURCE) {
                // MIME is canonical (mirrors the TS Blob branch), then the
                // filename extension. A hint-less resource has no signal — skip
                // it and keep looking (next input / default video).
                if ($input->contentType !== null) {
                    if (\str_starts_with($input->contentType, 'image/')) {
                        return 'image';
                    }
                    if (\str_starts_with($input->contentType, 'audio/')) {
                        return 'audio';
                    }
                    if (\str_starts_with($input->contentType, 'video/')) {
                        return 'video';
                    }
                }
                if ($input->filename !== null) {
                    return $this->mergeMediaFromExtension(\strtolower($input->filename));
                }
            }
        }
        return 'video';
    }

    /**
     * Classify a merge input's media (video/audio/image only) from a lowercased
     * path/filename extension. Shared by the path + resource-filename arms of
     * {@see inferMediaKind()}.
     *
     * @return "video"|"audio"|"image"
     */
    private function mergeMediaFromExtension(string $lower): string
    {
        if (\preg_match('/\.(jpe?g|png|webp|avif|gif|heic|tiff?)$/', $lower) === 1) {
            return 'image';
        }
        if (\preg_match('/\.(mp3|wav|flac|aac|ogg|m4a)$/', $lower) === 1) {
            return 'audio';
        }
        return 'video';
    }

    /**
     * @param "video"|"audio"|"image" $mediaKind
     */
    private function outputExtensionFor(string $mediaKind): string
    {
        // An image merge produces a video/gif output (output_type), so the
        // post-combine media follows the output type when set.
        $output = $this->mergeOptions->output ?? $this->mergeOptions->outputType;
        if ($mediaKind === 'image' && \is_string($output)) {
            return $output === 'gif' ? 'gif' : 'mp4';
        }
        return match ($mediaKind) {
            'audio' => 'mp3',
            'image' => 'png',
            default => 'mp4',
        };
    }
}
