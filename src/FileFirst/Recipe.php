<?php

declare(strict_types=1);

namespace Gisl\Sdk\FileFirst;

use Gisl\Generated\OpenApi\Model\WorkflowCreateResponse;
use Gisl\Sdk\Ergonomic\BuilderInternals;
use Gisl\Sdk\Ergonomic\Handle;
use Gisl\Sdk\Ergonomic\MaxWait;
use Gisl\Sdk\Ergonomic\PresetResolver;
use Gisl\Sdk\Ergonomic\UploadProgressEvent;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Errors\GislTimeoutError;
use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\JobDefinitionPayload;
use Gisl\Sdk\OperationDef;
use Gisl\Sdk\PresetDefaults;
use Gisl\Sdk\Sources;
use Gisl\Sdk\UploadOptions;
use Gisl\Sdk\WorkflowCreatePayload;

/**
 * The file-first builder value. `$client->file($path)` returns a `Recipe`;
 * single-input operations called on it (`compress`, `convert`, `thumbnail`,
 * `textWatermark`) chain SEQUENTIALLY — each op feeds the next, and the chain
 * lowers to ONE workflow job with an ordered `operations[]` (per ADR-0004:
 * "Ordered list of operations. Executed sequentially — each operation consumes
 * the previous operation's output"). A chain yields the TERMINAL output only;
 * intermediates are consumed (surfaced by FF2b's `run()`/`RunResult`).
 *
 * **Immutable / clone-on-write.** Every op returns a NEW `Recipe` carrying the
 * appended step — `$this` is never mutated. A Recipe is therefore a reusable
 * value: branching the same base recipe two different ways cannot let one
 * branch observe the other's steps (the aliasing trap that mutable fluent
 * builders fall into). All properties are readonly and there is no setter;
 * `withStep()` rebuilds via the constructor rather than `clone`-and-mutate
 * (PHP forbids writing a readonly property on a clone).
 *
 * FF2a is network-free: there is NO `run()` here (that is FF2b). The lowering
 * seam {@see toWorkflowPayload()} takes the resolved upload id as a parameter
 * so it stays pure — FF2b's `run()` will call the SAME method after uploading,
 * and the parity harness calls it with a fixed id to assert the lowered shape.
 *
 * Mirrors the TS `Recipe` in `packages/typescript/src/file-first.ts`.
 */
final class Recipe
{
    /**
     * @param list<RecipeStep> $steps Ordered operations applied so far.
     */
    public function __construct(
        private readonly FileInput $input,
        private readonly ?string $key = null,
        private readonly array $steps = [],
        private readonly ?PresetDefaults $presetDefaults = null,
        private readonly ?PresetDefaults $scopedPresetDefaults = null,
        private readonly ?GislClient $client = null,
    ) {
    }

    /**
     * Reduce file size. `optimize` selects a per-media preset (resolved to
     * concrete wire fields at lower-time, exactly as `client->compress()` does)
     * — pass an {@see OptimizeFor} case or its string value.
     */
    public function compress(OptimizeFor|string|null $optimize = null): self
    {
        return $this->withStep(new RecipeStep('compress', ['optimize' => self::coerceOptimize($optimize)]));
    }

    /**
     * Change format. `$format` is the target container/codec family
     * (e.g. `'mp4'`, `'webp'`) — lowered verbatim to the `format` wire option.
     */
    public function convert(string $format): self
    {
        return $this->withStep(new RecipeStep('convert', ['format' => $format]));
    }

    /**
     * Generate a preview. Width and/or height in pixels; an omitted dimension
     * is dropped from the wire options (not sent as null).
     */
    public function thumbnail(?int $width = null, ?int $height = null): self
    {
        $options = [];
        if ($width !== null) {
            $options['width'] = $width;
        }
        if ($height !== null) {
            $options['height'] = $height;
        }
        return $this->withStep(new RecipeStep('thumbnail', $options));
    }

    /**
     * Apply a text watermark. Single-input (the text is an option, not a
     * secondary file) — lowers to the `text_watermark` op with a `text` option.
     */
    public function textWatermark(string $text): self
    {
        return $this->withStep(new RecipeStep('text_watermark', ['text' => $text]));
    }

    /**
     * Lower this recipe to a workflow-create payload against a resolved upload
     * id. Single-input chain → ONE job, `source: upload($fileId)`, ordered
     * `operations[]`; the job `id` is omitted (a single job referenced by
     * nothing — the server auto-assigns `job_N`).
     *
     * When `$callbackUrl` is given (the file-first `submit()` path), it is built
     * INTO the payload at construction (`callback_url`) rather than spread onto
     * an already-built readonly payload. `run()` passes no `$callbackUrl`.
     *
     * @internal Consumed by FF2b's `run()` (after a real upload), FF5b's
     *           `submit()` (with a webhook), and the cross-language parity
     *           harness (with a fixed id). Not part of the caller-facing fluent
     *           surface.
     */
    public function toWorkflowPayload(string $fileId, ?string $callbackUrl = null): WorkflowCreatePayload
    {
        $operations = [];
        foreach ($this->steps as $step) {
            $operations[] = $this->lowerStep($step);
        }

        $job = new JobDefinitionPayload(
            operations: $operations,
            source: Sources::upload($fileId),
        );

        return new WorkflowCreatePayload(jobs: [$job], callbackUrl: $callbackUrl);
    }

    /** The result-addressing key passed to `file()`, or null. */
    public function key(): ?string
    {
        return $this->key;
    }

    /** The number of operations chained so far (introspection / tests). */
    public function stepCount(): int
    {
        return \count($this->steps);
    }

    /**
     * The captured op chain. Read by {@see FilesRecipe} to compose a shared
     * chain across many inputs without duplicating the chain-method validation.
     *
     * @internal
     *
     * @return list<RecipeStep>
     */
    public function recipeSteps(): array
    {
        return $this->steps;
    }

    /**
     * Execute the recipe end-to-end: upload the input (when required), create
     * the workflow, await a terminal state (SSE with poll fallback), then
     * resolve the produced downloads into a flat {@see RunResult}. Throws
     * {@see GislTimeoutError} if `$maxWait` elapses before terminal status.
     *
     * Mirrors {@see \Gisl\Sdk\Ergonomic\OperationBuilder::run()}. Requires a
     * client bound at construction time — `Gisl::create()->file(...)` wires it;
     * a directly-constructed Recipe (e.g. a lowering-only test) has no client
     * and throws {@see GislConfigError}.
     *
     * @param string|int|null $maxWait Wall-clock deadline for the whole run
     *                                 (upload + create + wait + downloads).
     *                                 String suffix (`'2h'`/`'30m'`/`'120s'`)
     *                                 or milliseconds; defaults to 300s.
     * @param (callable(\Gisl\Sdk\Ergonomic\ProgressEvent): void)|null $onProgress
     * @param int|null $pollIntervalMs Override the poll-fallback interval (ms).
     */
    public function run(
        string|int|null $maxWait = null,
        ?callable $onProgress = null,
        ?int $pollIntervalMs = null,
    ): RunResult {
        if ($this->client === null) {
            throw new GislConfigError(
                'Recipe::run() requires a client; build the recipe via Gisl::create()->file(...) rather than constructing Recipe directly.',
                reason: 'no_client',
            );
        }

        $deadlineMs = BuilderInternals::nowMs() + MaxWait::parse($maxWait ?? 300_000);
        $onProgressClosure = BuilderInternals::callableOrNull($onProgress, 'Recipe::run() $onProgress');

        // 1+2. Upload (when required) + create the workflow. Shared with
        // submit() (which passes a webhook → callback_url). run() passes none.
        $created = $this->uploadAndCreate(null, $deadlineMs, $onProgressClosure);
        $workflowId = $created->getWorkflowId() ?? '';

        // 3. Wait to terminal status — SSE first, poll on a genuine SSE error.
        $finalStatus = BuilderInternals::awaitTerminal(
            client: $this->client,
            workflowId: $workflowId,
            deadlineMs: $deadlineMs,
            onProgress: $onProgressClosure,
            useSSE: true,
            pollIntervalMs: $pollIntervalMs,
        );

        // 4. Fetch downloads. Codex TS r1 medium 42a6ea3b6102 — the maxWait
        // deadline covers upload + create + wait + downloads, so check before
        // issuing the request rather than letting a slow call exceed it.
        if (BuilderInternals::nowMs() >= $deadlineMs) {
            throw new GislTimeoutError(
                "Workflow {$workflowId} reached terminal status but maxWait elapsed before downloads could be fetched.",
            );
        }
        $downloads = $this->client->getWorkflowDownloads($workflowId);

        // Download URLs from getWorkflowDownloads are pre-signed and require no
        // SDK auth, so the downloader issues a plain unauthenticated stream.
        // The flatten + succeeded/failed partition lives in the shared
        // RunResult::fromTerminalDownloads() helper (also used by Handle).
        return RunResult::fromTerminalDownloads(
            workflowId: $workflowId,
            finalStatus: $finalStatus,
            jobDownloads: $downloads->getDownloads() ?? [],
            key: $this->key,
            downloader: new StreamingDownloader(),
        );
    }

    /**
     * Fire-and-forget the recipe: upload the input (when required), create the
     * workflow (wiring `$webhook` into `callback_url` when given), and return a
     * client-bound {@see Handle} carrying the workflow id + webhook secret + the
     * recipe key. Does NOT wait for terminal status — call `$handle->wait()` /
     * `$handle->result()` later to collect the {@see RunResult}.
     *
     * Requires a client bound at construction time (same `no_client` guard as
     * {@see run()}). `$webhook` is OPTIONAL: when null, no `callback_url` is
     * sent. Mirrors the TS `Recipe.submit()`.
     *
     * @param string|null $webhook Absolute callback URL the server POSTs
     *                             lifecycle events to.
     */
    public function submit(?string $webhook = null): Handle
    {
        if ($this->client === null) {
            throw new GislConfigError(
                'Recipe::submit() requires a client; build the recipe via Gisl::create()->file(...) rather than constructing Recipe directly.',
                reason: 'no_client',
            );
        }

        // submit() is fire-and-forget — NO whole-run deadline. The upload may be
        // large (a multi-GB master, example 12) and is bounded by the HTTP
        // client's own request timeout, not an arbitrary submit-side cap. Pass
        // null so the post-upload deadline check is skipped: a 300s cap here
        // would throw on a slow-but-successful big upload before createWorkflow
        // (codex).
        $created = $this->uploadAndCreate($webhook, null, null);

        return new Handle(
            workflowId: $created->getWorkflowId() ?? '',
            webhookSecret: $created->getWebhookSecret(),
            client: $this->client,
            key: $this->key,
        );
    }

    /**
     * Resolve the upload id (verbatim for a pre-uploaded id; uploading a path
     * otherwise via the byte-counter progress closure; resource-stream inputs
     * throw `resource_input_unsupported`), check the post-upload deadline, lower
     * to the workflow-create payload (wiring `$webhook` into `callback_url`),
     * and create the workflow. Shared first half of {@see run()} + {@see submit()}.
     *
     * The post-upload deadline check carries a prior codex fix (9a117f04eb59):
     * a slow upload must not proceed to createWorkflow past the deadline.
     *
     * @param \Closure|null $onProgressClosure Already-coerced progress closure.
     */
    private function uploadAndCreate(
        ?string $webhook,
        ?int $deadlineMs,
        ?\Closure $onProgressClosure,
    ): WorkflowCreateResponse {
        // 1. Resolve the upload id. A pre-uploaded id skips the upload entirely;
        // a path is uploaded now, emitting UploadProgressEvent from the byte
        // counter. Resource-stream inputs are deferred (see GislClient).
        if ($this->input->kind === FileInput::KIND_UPLOAD_ID) {
            $fileId = BuilderInternals::coerceString($this->input->fileId);
        } elseif ($this->input->kind === FileInput::KIND_PATH) {
            $uploadOpts = $onProgressClosure !== null
                ? new UploadOptions(
                    onProgress: static function (int $u, int $t) use ($onProgressClosure): void {
                        $onProgressClosure(new UploadProgressEvent($u, $t));
                    },
                )
                : null;
            $uploadResp = $this->client?->uploadFile(BuilderInternals::coerceString($this->input->path), $uploadOpts);
            $fileId = $uploadResp?->getFileId() ?? '';
        } else {
            throw new GislConfigError(
                'Recipe::run() does not yet support resource-stream inputs; use a filesystem path or a pre-uploaded file id.',
                reason: 'resource_input_unsupported',
            );
        }

        // Codex TS r2 medium 9a117f04eb59 — run() passes a whole-run deadline so
        // a slow upload doesn't proceed to createWorkflow past maxWait. submit()
        // passes null (fire-and-forget, no upload cap), so the check is skipped.
        if ($deadlineMs !== null && BuilderInternals::nowMs() >= $deadlineMs) {
            throw new GislTimeoutError('Upload completed but maxWait elapsed before workflow could be created.');
        }

        // 2. Create the workflow from the lowered payload (callback_url built
        // into the payload at construction when a webhook is given).
        $payload = $this->toWorkflowPayload($fileId, $webhook);

        // Guaranteed non-null by the no_client guard in run()/submit() before
        // this private helper is reached.
        \assert($this->client !== null);

        return $this->client->createWorkflow($payload);
    }

    private function withStep(RecipeStep $step): self
    {
        return new self(
            $this->input,
            $this->key,
            [...$this->steps, $step],
            $this->presetDefaults,
            $this->scopedPresetDefaults,
            $this->client,
        );
    }

    private function lowerStep(RecipeStep $step): OperationDef
    {
        $options = $step->opType === 'compress'
            ? $this->lowerCompressOptions($step->options)
            : $step->options;

        // Empty options omit the `options` wire key entirely, so PHP (null →
        // absent) and TS (undefined → absent) serialise byte-identically — an
        // empty PHP array would otherwise emit `[]` where TS emits `{}`.
        return new OperationDef($step->opType, $options === [] ? null : $options);
    }

    /**
     * Resolve a compress step's `optimize` selector to concrete wire options
     * via the shared {@see PresetResolver}, keyed off the input's media class
     * (extension-derived, offline-deterministic). When the media cannot be
     * inferred locally (a resource handle or bare upload id), preset resolution
     * is impossible here — emit empty options and let the server apply its
     * media defaults. This path is not reached by path-based inputs.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function lowerCompressOptions(array $options): array
    {
        $optimize = $options['optimize'] ?? null;
        \assert($optimize === null || $optimize instanceof OptimizeFor);

        $media = $this->input->compressMediaHint();
        if ($media === null) {
            // Cannot infer a media class (a resource handle or bare upload id
            // carries no extension) → preset resolution is impossible. Fail
            // FAST rather than silently dropping an explicit `optimize` choice;
            // a bare `compress()` is still fine (server applies defaults).
            if ($optimize !== null) {
                throw new GislConfigError(
                    "compress(optimize: {$optimize->value}) needs a media type to resolve the preset, but the "
                    . 'input has no inferable media (a pre-uploaded file id or stream carries no extension). '
                    . 'Use a path with a file extension, or call compress() without optimize.',
                    reason: 'media_unknown',
                    conflictingFields: ['optimize'],
                );
            }
            return [];
        }

        $resolved = PresetResolver::resolveCompress(
            media: $media,
            presetDefaults: $this->presetDefaults,
            scopedDefaults: $this->scopedPresetDefaults,
            presetOverrides: null,
            optimize: $optimize,
            explicitOptions: [],
        );

        return $resolved['wireOptions'];
    }

    /**
     * Coerce an `optimize` argument to an {@see OptimizeFor} case. Mirrors the
     * operation-first builder's coercion so a string value validates the same
     * way and fails early with the same {@see GislConfigError}.
     */
    private static function coerceOptimize(OptimizeFor|string|null $raw): ?OptimizeFor
    {
        if ($raw === null || $raw instanceof OptimizeFor) {
            return $raw;
        }

        $level = OptimizeFor::tryFrom($raw);
        if ($level === null) {
            $allowed = \implode(', ', \array_map(static fn (OptimizeFor $o): string => $o->value, OptimizeFor::cases()));
            throw new GislConfigError(
                "compress 'optimize' must be one of {$allowed}; got '{$raw}'.",
                reason: 'invalid_optimize',
                conflictingFields: ['optimize'],
            );
        }
        return $level;
    }
}
