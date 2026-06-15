<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Conformance;

use Gisl\Generated\Operations\ArchiveMetadata;
use Gisl\Generated\Operations\CompressMetadata;
use Gisl\Generated\Operations\ConvertMetadata;
use Gisl\Generated\Operations\MergeMetadata;
use Gisl\Generated\Operations\OperationMetadata;
use Gisl\Generated\Operations\TextWatermarkMetadata;
use Gisl\Generated\Operations\ThumbnailMetadata;
use Gisl\Sdk\Ergonomic\ArchiveFormat;
use Gisl\Sdk\Ergonomic\MergeOptions;
use Gisl\Sdk\Ergonomic\PresetResolver;
use Gisl\Sdk\FileFirst\ArchivedRecipe;
use Gisl\Sdk\FileFirst\FileInput;
use Gisl\Sdk\FileFirst\MergedRecipe;
use Gisl\Sdk\FileFirst\Recipe;
use Gisl\Sdk\OperationDef;
use Gisl\Sdk\WorkflowCreatePayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Wire-key conformance guard (card y2qOUp90) — PHP arm; mirrors the TS
 * `wire-key-conformance.test.ts`.
 *
 * The convert `format`→`output_format` bug (FE-caught 2026-06-14, fixed in
 * PR #204/#205) slipped through because the ergonomic builders' lowered wire
 * keys were never systematically validated against the contract operation
 * schemas — only compress went through a contract-checked resolver.
 *
 * This suite turns "we hope the keys match" into a CI gate: for every
 * ergonomic op it collects the wire option keys the SDK can emit and asserts
 * each one is a real contract option key, read from the generated, in-repo
 * {@see OperationMetadata} sidecars (regenerated from the contract; their
 * `options` keys are the verbatim contract wire keys).
 */
final class WireKeyConformanceTest extends TestCase
{
    private const FILE_ID = 'file_0001';

    /**
     * OPERATION-LEVEL contract option keys (valid in `OperationDef::$options`):
     * the union of every mime group's `options` plus `direct_options` for
     * media-agnostic ops (archive has no mime_groups — its keys live only in
     * `direct_options`). Deliberately EXCLUDES `per_input_options`: those are
     * valid only on a merge input's `per_input_options`, never at operation
     * level, so folding them in would let a misplaced per-input key pass.
     *
     * @return array<string, true>
     */
    private function operationOptionKeys(OperationMetadata $metadata): array
    {
        $keys = [];
        foreach ($metadata->mime_groups as $group) {
            foreach (array_keys($group->options) as $k) {
                $keys[$k] = true;
            }
        }
        foreach (array_keys($metadata->direct_options) as $k) {
            $keys[$k] = true;
        }

        return $keys;
    }

    /**
     * Operation-level option keys for ONE mime group (media-precise). Used for
     * merge, whose option sets differ per media kind — validating against the
     * specific media group catches a wrong-media or per-input-only key emitted
     * at merge level.
     *
     * @return array<string, true>
     */
    private function mediaGroupOptionKeys(OperationMetadata $metadata, string $kind): array
    {
        self::assertArrayHasKey($kind, $metadata->mime_groups, "metadata has a '{$kind}' mime group");

        $keys = [];
        foreach (array_keys($metadata->mime_groups[$kind]->options) as $k) {
            $keys[$k] = true;
        }

        return $keys;
    }

    /**
     * @param list<string>        $emitted
     * @param array<string, true> $contract
     */
    private function assertKeysConform(string $opType, array $emitted, array $contract): void
    {
        $stray = array_values(array_filter($emitted, static fn (string $k): bool => !isset($contract[$k])));
        $allowed = array_keys($contract);
        sort($allowed);
        self::assertSame(
            [],
            $stray,
            \sprintf(
                '%s: emitted wire option key(s) %s are not in the contract option set %s',
                $opType,
                \json_encode($stray),
                \json_encode($allowed),
            ),
        );
    }

    /**
     * The option keys of the op named `$type` in the LAST job of a payload
     * (single-input chains have one job; merge/archive append the op job last).
     *
     * @return list<string>
     */
    private function optionKeysOf(WorkflowCreatePayload $payload, string $type): array
    {
        $job = $payload->jobs[count($payload->jobs) - 1];
        $match = null;
        foreach ($job->operations as $op) {
            \assert($op instanceof OperationDef);
            if ($op->type === $type) {
                $match = $op;
                break;
            }
        }
        self::assertNotNull($match, "expected a '{$type}' op in the lowered payload");

        return array_keys($match->options ?? []);
    }

    // --- compress -----------------------------------------------------------

    #[Test]
    public function every_known_wire_field_is_a_real_compress_contract_option(): void
    {
        $contract = $this->operationOptionKeys(CompressMetadata::instance());
        $declared = [];
        foreach (PresetResolver::KNOWN_WIRE_FIELDS as $fields) {
            foreach ($fields as $field) {
                $declared[$field] = true;
            }
        }
        $this->assertKeysConform('compress', array_keys($declared), $contract);
    }

    // --- convert ------------------------------------------------------------

    #[Test]
    public function convert_lowers_the_format_shorthand_to_output_format_never_format(): void
    {
        $contract = $this->operationOptionKeys(ConvertMetadata::instance());
        $payload = (new Recipe(FileInput::path('photo.png')))
            ->convert('webp', ['quality' => 80, 'background' => '#ffffff'])
            ->toWorkflowPayload(self::FILE_ID);
        $keys = $this->optionKeysOf($payload, 'convert');

        self::assertContains('output_format', $keys);
        // Regression pin for the 06-14 bug: the ergonomic `format` must never reach the wire.
        self::assertNotContains('format', $keys);
        $this->assertKeysConform('convert', $keys, $contract);
    }

    #[Test]
    public function post_merge_convert_also_lowers_to_output_format(): void
    {
        $contract = $this->operationOptionKeys(ConvertMetadata::instance());
        $payload = (new MergedRecipe(
            [FileInput::path('a.mp4'), FileInput::path('b.mp4')],
            new MergeOptions(mediaKind: 'video'),
        ))->convert('webm')->toWorkflowPayload(['f0', 'f1'], null);
        $keys = $this->optionKeysOf($payload, 'convert');

        self::assertContains('output_format', $keys);
        self::assertNotContains('format', $keys);
        $this->assertKeysConform('convert', $keys, $contract);
    }

    // --- thumbnail ----------------------------------------------------------

    #[Test]
    public function thumbnail_passthrough_keys_conform_to_the_contract(): void
    {
        $contract = $this->operationOptionKeys(ThumbnailMetadata::instance());
        // thumbnail is open passthrough — callers supply contract keys directly.
        $payload = (new Recipe(FileInput::path('photo.png')))
            ->thumbnail(['width' => 320, 'height' => 240, 'fit' => 'crop', 'format' => 'png'])
            ->toWorkflowPayload(self::FILE_ID);
        $this->assertKeysConform('thumbnail', $this->optionKeysOf($payload, 'thumbnail'), $contract);
    }

    // --- text_watermark -----------------------------------------------------

    #[Test]
    public function text_watermark_injected_text_and_passthrough_keys_conform(): void
    {
        $contract = $this->operationOptionKeys(TextWatermarkMetadata::instance());
        $payload = (new Recipe(FileInput::path('photo.png')))
            ->textWatermark('(c) Acme', ['font_size' => 48, 'anchor' => 'bottom_right'])
            ->toWorkflowPayload(self::FILE_ID);
        $keys = $this->optionKeysOf($payload, 'text_watermark');

        self::assertContains('text', $keys); // SDK injects the positional text as the literal `text` wire key
        $this->assertKeysConform('text_watermark', $keys, $contract);
    }

    // --- merge --------------------------------------------------------------

    /**
     * @return iterable<string, array{MergeOptions}>
     */
    public static function mergeOptionsProvider(): iterable
    {
        // Fully-populated per media kind so every branch of wireMergeOptions fires.
        yield 'video' => [new MergeOptions(
            transition: 'crossfade',
            crossfadeDuration: 1.0,
            normalizeAudio: true,
            codec: 'h264',
            crf: 23,
            preset: 'medium',
            targetSize: '10MB',
            output: 'video',
            mediaKind: 'video',
        )];
        yield 'audio' => [new MergeOptions(
            transition: 'crossfade',
            crossfadeDuration: 1.0,
            gapDuration: 0.5,
            normalizeAudio: true,
            output: 'audio',
            mediaKind: 'audio',
        )];
        yield 'image' => [new MergeOptions(
            transition: 'fade',
            transitionDuration: 0.5,
            fps: 30.0,
            durationPerImage: 3.0,
            loopCount: 0,
            output: 'video',
            videoFormat: 'mp4',
            mediaKind: 'image',
        )];
    }

    #[Test]
    #[DataProvider('mergeOptionsProvider')]
    public function merge_emitted_keys_conform_to_the_contract(MergeOptions $options): void
    {
        // Media-precise: validate against the specific media group's options so a
        // wrong-media or per-input-only key at merge level is rejected.
        $kind = $options->mediaKind;
        self::assertNotNull($kind, 'each merge fixture pins mediaKind');
        $contract = $this->mediaGroupOptionKeys(MergeMetadata::instance(), $kind);
        $payload = (new MergedRecipe([FileInput::path('a'), FileInput::path('b')], $options))
            ->toWorkflowPayload(['f0', 'f1'], null);
        $this->assertKeysConform("merge:{$kind}", $this->optionKeysOf($payload, 'merge'), $contract);
    }

    #[Test]
    public function merge_does_not_leak_sdk_only_options_to_the_wire(): void
    {
        $payload = (new MergedRecipe(
            [FileInput::path('a'), FileInput::path('b')],
            new MergeOptions(transition: 'crossfade', mediaKind: 'video', allowUnusedAssets: true),
        ))->toWorkflowPayload(['f0', 'f1'], null);
        $keys = $this->optionKeysOf($payload, 'merge');

        self::assertNotContains('mediaKind', $keys);
        self::assertNotContains('allowUnusedAssets', $keys);
    }

    // --- archive ------------------------------------------------------------

    #[Test]
    public function archive_emitted_keys_conform_to_the_contract(): void
    {
        $contract = $this->operationOptionKeys(ArchiveMetadata::instance());
        $payload = (new ArchivedRecipe(
            [FileInput::path('a.png'), FileInput::path('b.pdf')],
            ArchiveFormat::Zip,
            'by_job',
        ))->toWorkflowPayload(['f0', 'f1'], null);
        $this->assertKeysConform('archive', $this->optionKeysOf($payload, 'archive'), $contract);
    }
}
