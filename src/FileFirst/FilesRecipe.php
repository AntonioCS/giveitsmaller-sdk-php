<?php

declare(strict_types=1);

namespace Gisl\Sdk\FileFirst;

use Gisl\Generated\OpenApi\Model\WorkflowCreateResponse;
use Gisl\Sdk\Ergonomic\BuilderInternals;
use Gisl\Sdk\Ergonomic\Handle;
use Gisl\Sdk\Ergonomic\MaxWait;
use Gisl\Sdk\Ergonomic\MergeOptions;
use Gisl\Sdk\Ergonomic\UploadProgressEvent;
use Gisl\Sdk\Cancellation;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Errors\GislTimeoutError;
use Gisl\Sdk\Http\UploadSource;
use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\JobDefinitionPayload;
use Gisl\Sdk\PresetDefaults;
use Gisl\Sdk\UploadOptions;
use Gisl\Sdk\WorkflowCreatePayload;

/**
 * The homogeneous fan-out builder value (FF3a). `$client->files([$a, $b, $c])`
 * returns a `FilesRecipe`; the op-chain methods (`compress`, `convert`,
 * `thumbnail`, `textWatermark`) build ONE shared recipe (chain) that is applied
 * to EVERY input file in ONE workflow. `run()` returns a partitioned
 * {@see RunResult} — one `succeeded`/`failed` entry per input, keyed by its
 * 0-based index ("0", "1", …) so one bad input does not sink the rest.
 *
 * **Immutable / clone-on-write**, exactly like {@see Recipe}: every op returns
 * a NEW `FilesRecipe` carrying the appended step. The inputs are held as an
 * ORDERED list (NOT a map) so the per-file index is the partition key.
 *
 * **Lowering composes {@see Recipe} per file** rather than duplicating
 * `lowerStep`/`lowerCompressOptions`: for each input `$i` it builds an internal
 * single-file `Recipe($input_i, …, $steps)`, calls its `toWorkflowPayload()` to
 * get that file's one-job payload, then merges all jobs into ONE
 * {@see WorkflowCreatePayload} with `jobs[$i]->id = "file-{$i}"`. This preserves
 * each file's media-hint (different extensions per input resolve compress
 * presets independently).
 *
 * Exposes both `run()` (blocking, returns a partitioned {@see RunResult}) and
 * `submit(?string $webhook)` (fire-and-forget, returns a {@see Handle}).
 * Mirrors the TS `FilesRecipe` in `packages/typescript/src/file-first.ts`.
 */
final class FilesRecipe
{
    /**
     * @param list<FileInput>  $inputs Ordered input files (the per-file index
     *                                 is the partition key).
     * @param list<RecipeStep> $steps  Ordered operations applied to every input.
     */
    public function __construct(
        private readonly array $inputs,
        private readonly array $steps = [],
        private readonly ?PresetDefaults $presetDefaults = null,
        private readonly ?PresetDefaults $scopedPresetDefaults = null,
        private readonly ?GislClient $client = null,
    ) {
    }

    /**
     * Reduce file size on every input. `optimize` selects a per-media preset
     * (resolved per file at lower-time, so each input's extension picks its own
     * preset) — pass an {@see OptimizeFor} case or its string value. Reuses
     * {@see Recipe}'s coercion/validation, so a bad value throws the same
     * {@see GislConfigError}.
     */
    public function compress(OptimizeFor|string|null $optimize = null): self
    {
        return $this->withRecipe($this->baseRecipe()->compress($optimize));
    }

    /** Change every input's format. `$format` lowers verbatim to the `format` option. */
    public function convert(string $format): self
    {
        return $this->withRecipe($this->baseRecipe()->convert($format));
    }

    /**
     * Generate a preview of every input. Omitted dimensions are dropped from
     * the wire options. Options shape mirrors {@see Recipe::thumbnail()}.
     *
     * @param array{width?: int, height?: int} $options
     */
    public function thumbnail(array $options = []): self
    {
        return $this->withRecipe($this->baseRecipe()->thumbnail($options));
    }

    /** Apply the same text watermark to every input. */
    public function textWatermark(string $text): self
    {
        return $this->withRecipe($this->baseRecipe()->textWatermark($text));
    }

    /**
     * Combine the inputs into ONE output (N→1), in array order (FF3b). Returns a
     * single-output {@see MergedRecipe} you chain further ops on
     * (`files([...])->merge()->compress()`). Reuses the operation-first
     * {@see \Gisl\Sdk\Ergonomic\MergeOptions} for the merge-level options, so the
     * wire shape matches `client->merge([...], $options)`.
     *
     * merge() must be the FIRST op on `files([...])` — per-file ops before a
     * combine (compress-each-then-merge) are a separate follow-up.
     */
    public function merge(?MergeOptions $options = null): MergedRecipe
    {
        if ($this->steps !== []) {
            throw new GislConfigError(
                'merge() must be the first operation on files([...]); applying per-file ops before a combine '
                . '(compress-each-then-merge) is not yet supported — call merge() directly, then chain ops on the merged output.',
                reason: 'pre_merge_ops_unsupported',
            );
        }

        return new MergedRecipe(
            $this->inputs,
            $options ?? new MergeOptions(),
            [],
            $this->presetDefaults,
            $this->scopedPresetDefaults,
            $this->client,
        );
    }

    /** The number of inputs in this fan-out (introspection / tests). */
    public function inputCount(): int
    {
        return \count($this->inputs);
    }

    /** The number of operations chained so far (introspection / tests). */
    public function stepCount(): int
    {
        return \count($this->steps);
    }

    /**
     * Lower this fan-out to a single multi-job workflow-create payload against a
     * list of resolved upload ids (one per input, in input order). Each input
     * `$i` becomes ONE job with `id = "file-{$i}"`, its `source: upload($fileIds[$i])`,
     * and the SHARED lowered `operations[]`. Composes the single-file
     * {@see Recipe::toWorkflowPayload()} per file so per-file media-hints resolve
     * independently and lowering logic is not duplicated.
     *
     * @internal Consumed by {@see run()} (after uploading all inputs) and the
     *           cross-language parity harness (with fixed ids). Not caller-facing.
     *
     * When `$callbackUrl` is given (the file-first `submit()` path) it is built
     * INTO the payload (`callback_url`) — mirrors {@see Recipe::toWorkflowPayload()}.
     * `run()` passes no `$callbackUrl`.
     *
     * @param list<string> $fileIds
     */
    public function toWorkflowPayload(array $fileIds, ?string $callbackUrl = null): WorkflowCreatePayload
    {
        $jobs = [];
        foreach ($this->inputs as $i => $input) {
            $single = new Recipe($input, null, $this->steps, $this->presetDefaults, $this->scopedPresetDefaults);
            $oneJob = $single->toWorkflowPayload($fileIds[$i])->jobs[0];
            // Key order (id, source, operations) matches the TS lowering so the
            // JSON-string serialisation is byte-identical across languages.
            $jobs[] = new JobDefinitionPayload(
                operations: $oneJob->operations,
                id: "file-{$i}",
                source: $oneJob->source,
            );
        }

        return new WorkflowCreatePayload(jobs: $jobs, callbackUrl: $callbackUrl);
    }

    /**
     * Execute the fan-out end-to-end: upload EVERY input, create ONE workflow
     * with one job per input, await a terminal state (SSE with poll fallback),
     * then resolve the per-job downloads into a partitioned {@see RunResult}.
     * `partially_failed` is a NORMAL terminal state here — its successful jobs
     * land in `succeeded`, its failed jobs in `failed`.
     *
     * Requires a client bound at construction time — `Gisl::create()->files(...)`
     * wires it; a directly-constructed `FilesRecipe` throws {@see GislConfigError}.
     * Mirrors the single-file {@see Recipe::run()}; see {@see submit()} for the
     * fire-and-forget arm.
     *
     * @param string|int|null $maxWait Wall-clock deadline for the whole run.
     * @param (callable(\Gisl\Sdk\Ergonomic\ProgressEvent): void)|null $onProgress
     * @param int|null $pollIntervalMs Override the poll-fallback interval (ms).
     * @param Cancellation|null $cancellation Cooperative cancellation token —
     *        cancel it to abort the fan-out early (between uploads / wait frames)
     *        with a {@see \Gisl\Sdk\Errors\GislAbortError}.
     */
    public function run(
        string|int|null $maxWait = null,
        ?callable $onProgress = null,
        ?int $pollIntervalMs = null,
        ?Cancellation $cancellation = null,
    ): RunResult {
        if ($this->client === null) {
            throw new GislConfigError(
                'FilesRecipe::run() requires a client; build the fan-out via Gisl::create()->files(...) rather than constructing FilesRecipe directly.',
                reason: 'no_client',
            );
        }

        $deadlineMs = BuilderInternals::nowMs() + MaxWait::parse($maxWait ?? 300_000);
        $onProgressClosure = BuilderInternals::callableOrNull($onProgress, 'FilesRecipe::run() $onProgress');

        // 1+2. Upload EVERY input + create ONE multi-job workflow. Shared with
        // submit() (which passes a webhook → callback_url and a null deadline).
        $created = $this->uploadAllAndCreate(null, $deadlineMs, $onProgressClosure, $cancellation);
        $workflowId = $created->getWorkflowId() ?? '';

        // 3. Wait to terminal status — SSE first, poll on a genuine SSE error.
        // `partially_failed` is a normal terminal state here.
        $finalStatus = BuilderInternals::awaitTerminal(
            client: $this->client,
            workflowId: $workflowId,
            deadlineMs: $deadlineMs,
            onProgress: $onProgressClosure,
            useSSE: true,
            pollIntervalMs: $pollIntervalMs,
            cancellation: $cancellation,
        );

        // 4. Fetch downloads + project per-job into the partitioned RunResult.
        BuilderInternals::throwIfCancelled($cancellation, 'downloads fetch');
        if (BuilderInternals::nowMs() >= $deadlineMs) {
            throw new GislTimeoutError(
                "Workflow {$workflowId} reached terminal status but maxWait elapsed before downloads could be fetched.",
            );
        }
        $downloads = $this->client->getWorkflowDownloads($workflowId);

        // keyByRef maps each job ref ("file-{i}") to the partition key. Today the
        // key is just the index string; the map seam leaves room for a keyed
        // fan-out card to map refs to caller-supplied keys without changing the
        // producer's signature.
        $keyByRef = [];
        foreach (array_keys($this->inputs) as $i) {
            $keyByRef["file-{$i}"] = (string) $i;
        }

        return RunResult::fromTerminalMultiJob(
            workflowId: $workflowId,
            finalStatus: $finalStatus,
            jobDownloads: \array_values($downloads->getDownloads() ?? []),
            keyByRef: $keyByRef,
            downloader: new StreamingDownloader(),
        );
    }

    /**
     * Fire-and-forget the fan-out: upload every input, create ONE multi-job
     * workflow (wiring `$webhook` into `callback_url` when given), and return a
     * client-bound {@see Handle}. Does NOT wait for terminal status — call
     * `$handle->wait()` / `$handle->result()` later to collect the partitioned
     * {@see RunResult}. The Handle detects the fan-out from the wire `file-{i}`
     * job refs, so per-file `byKey()` works even after a `client->workflow(id)`
     * reattach (the keys are the input indices `"0"`, `"1"`, …).
     *
     * Requires a client bound at construction time (same `no_client` guard as
     * {@see run()}). `$webhook` is OPTIONAL. Fire-and-forget, so NO whole-run
     * deadline (a multi-GB upload is bounded by the HTTP client's own timeout).
     * Mirrors the single-file {@see Recipe::submit()}.
     *
     * @param string|null $webhook Absolute callback URL the server POSTs
     *                             lifecycle events to.
     */
    public function submit(?string $webhook = null): Handle
    {
        if ($this->client === null) {
            throw new GislConfigError(
                'FilesRecipe::submit() requires a client; build the fan-out via Gisl::create()->files(...) rather than constructing FilesRecipe directly.',
                reason: 'no_client',
            );
        }

        // Fire-and-forget — null deadline so a slow-but-successful big upload is
        // not capped before createWorkflow (mirrors Recipe::submit()).
        $created = $this->uploadAllAndCreate($webhook, null, null);

        return new Handle(
            workflowId: $created->getWorkflowId() ?? '',
            webhookSecret: $created->getWebhookSecret(),
            client: $this->client,
            key: null,
        );
    }

    /**
     * Upload every input (verbatim for a pre-uploaded id; uploading a path via
     * the byte-counter progress closure) then create ONE multi-job workflow
     * (one job per input, `callback_url` built in when `$webhook` is given).
     * Shared first half of {@see run()} + {@see submit()}.
     *
     * `run()` passes a whole-run deadline (a slow upload must not proceed to
     * createWorkflow past maxWait); `submit()` passes null, so the deadline
     * checks are skipped. Pre-validates ALL input kinds before uploading
     * anything so a resource arm anywhere in the list fails fast.
     *
     * @param (\Closure(\Gisl\Sdk\Ergonomic\UploadProgressEvent): void)|null $onProgressClosure
     */
    private function uploadAllAndCreate(
        ?string $webhook,
        ?int $deadlineMs,
        ?\Closure $onProgressClosure,
        ?Cancellation $cancellation = null,
    ): WorkflowCreateResponse {
        \assert($this->client !== null);

        // Preflight EVERY input before uploading ANY of them — a non-seekable/
        // non-readable stream OR an un-lowerable op chain (e.g. compress(optimize)
        // on an input with no inferable media) anywhere in the list must fail
        // fast, not after earlier inputs have already uploaded (codex VOxtu0RZ-B4).
        foreach ($this->inputs as $input) {
            if ($input->kind === FileInput::KIND_RESOURCE) {
                UploadSource::assertUploadableStream($input->resource);
            } elseif ($input->kind === FileInput::KIND_PATH) {
                // Validate existence/readability now (codex VOxtu0RZ-B4 r3) so a
                // missing path later in the list does not leave earlier inputs
                // uploaded. `fromPath()` throws GislConfigError on a bad path.
                UploadSource::fromPath(BuilderInternals::coerceString($input->path));
            }
            // Lower this input's op chain with a placeholder id to trigger its
            // per-input validation (media_unknown etc.) before any upload.
            $preflight = new Recipe($input, null, $this->steps, $this->presetDefaults, $this->scopedPresetDefaults);
            $preflight->toWorkflowPayload('preflight');
        }

        // 1. Upload EVERY input: verbatim for a pre-uploaded id; a path or a
        // seekable stream resource (VOxtu0RZ-B4) otherwise, via the byte-counter
        // progress closure.
        $fileIds = [];
        foreach ($this->inputs as $input) {
            // Fail fast between uploads — a deadline that elapses or a caller
            // cancel mid-batch should not force every remaining input to upload
            // before throwing.
            BuilderInternals::throwIfCancelled($cancellation, 'fan-out upload');
            if ($deadlineMs !== null && BuilderInternals::nowMs() >= $deadlineMs) {
                throw new GislTimeoutError('maxWait elapsed during fan-out uploads before all inputs were uploaded.');
            }
            if ($input->kind === FileInput::KIND_UPLOAD_ID) {
                $fileIds[] = BuilderInternals::coerceString($input->fileId);
            } else {
                $uploadOpts = $onProgressClosure !== null
                    ? new UploadOptions(
                        onProgress: static function (int $u, int $t) use ($onProgressClosure): void {
                            $onProgressClosure(new UploadProgressEvent($u, $t));
                        },
                    )
                    : null;
                $uploadTarget = BuilderInternals::coerceString($input->path);
                if ($input->kind === FileInput::KIND_RESOURCE) {
                    \assert(\is_resource($input->resource));
                    $uploadTarget = $input->resource;
                }
                $uploadResp = $this->client->uploadFile($uploadTarget, $uploadOpts);
                $fileIds[] = $uploadResp->getFileId() ?? '';
            }
        }

        BuilderInternals::throwIfCancelled($cancellation, 'workflow creation');
        if ($deadlineMs !== null && BuilderInternals::nowMs() >= $deadlineMs) {
            throw new GislTimeoutError('Uploads completed but maxWait elapsed before workflow could be created.');
        }

        // 2. Create ONE multi-job workflow (callback_url built in when webhook given).
        return $this->client->createWorkflow($this->toWorkflowPayload($fileIds, $webhook));
    }

    /**
     * The shared single-file {@see Recipe} that captures the op chain (input is
     * a placeholder — only the steps are read). Reuses Recipe's op-chain
     * coercion + validation so `FilesRecipe::compress(bad)` throws the identical
     * {@see GislConfigError} as `Recipe::compress(bad)`. A path placeholder
     * gives compress() a media hint matching the single-file path; per-file
     * lowering in {@see toWorkflowPayload()} rebuilds a Recipe with the REAL input.
     */
    private function baseRecipe(): Recipe
    {
        return new Recipe(
            $this->inputs[0] ?? FileInput::path('placeholder'),
            null,
            $this->steps,
            $this->presetDefaults,
            $this->scopedPresetDefaults,
        );
    }

    private function withRecipe(Recipe $recipeWithStep): self
    {
        return new self(
            $this->inputs,
            $recipeWithStep->recipeSteps(),
            $this->presetDefaults,
            $this->scopedPresetDefaults,
            $this->client,
        );
    }
}
