<?php

declare(strict_types=1);

namespace Gisl\Sdk\FileFirst;

use Gisl\Sdk\Ergonomic\PresetResolver;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\JobDefinitionPayload;
use Gisl\Sdk\OperationDef;
use Gisl\Sdk\PresetDefaults;
use Gisl\Sdk\Sources;
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
     * @internal Consumed by FF2b's `run()` (after a real upload) and by the
     *           cross-language parity harness (with a fixed id). Not part of the
     *           caller-facing fluent surface.
     */
    public function toWorkflowPayload(string $fileId): WorkflowCreatePayload
    {
        $operations = [];
        foreach ($this->steps as $step) {
            $operations[] = $this->lowerStep($step);
        }

        $job = new JobDefinitionPayload(
            operations: $operations,
            source: Sources::upload($fileId),
        );

        return new WorkflowCreatePayload(jobs: [$job]);
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

    private function withStep(RecipeStep $step): self
    {
        return new self(
            $this->input,
            $this->key,
            [...$this->steps, $step],
            $this->presetDefaults,
            $this->scopedPresetDefaults,
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
