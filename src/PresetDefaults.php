<?php

declare(strict_types=1);

namespace Gisl\Sdk;

use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\Preset\AudioCompressPresetOptions;
use Gisl\Sdk\Preset\DocumentEpubCompressPresetOptions;
use Gisl\Sdk\Preset\DocumentOdfCompressPresetOptions;
use Gisl\Sdk\Preset\DocumentOfficeCompressPresetOptions;
use Gisl\Sdk\Preset\DocumentPdfCompressPresetOptions;
use Gisl\Sdk\Preset\ImageCompressPresetOptions;
use Gisl\Sdk\Preset\VideoCompressPresetOptions;

/**
 * Immutable builder for layered preset configuration. Mirrors the TS
 * `PresetDefaults` (T4a).
 *
 *   $defaults = PresetDefaults::create()
 *       ->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 75))
 *       ->videoCompress(OptimizeFor::Quality);
 *
 * Each per-cell method is immutable: it returns a FRESH instance carrying
 * the prior registrations plus the new (mediaOp, level) delta — calling the
 * same method twice on one instance yields two distinct objects. A call
 * with no input registers an empty delta ("apply shipped defaults verbatim
 * for this cell"); the P6 resolver reads the registered delta via
 * {@see cellFor()} and merges shipped defaults + this user delta + per-call
 * overrides. This card ships only the typed slot + builder semantics; the
 * merge logic and `Gisl::create()` wiring land with the resolver (P6).
 *
 * @phpstan-type PresetCellDelta ImageCompressPresetOptions|AudioCompressPresetOptions|VideoCompressPresetOptions|DocumentPdfCompressPresetOptions|DocumentOfficeCompressPresetOptions|DocumentOdfCompressPresetOptions|DocumentEpubCompressPresetOptions
 */
final class PresetDefaults
{
    /**
     * Registered user deltas keyed by "<mediaOpKey>:<level wire value>".
     *
     * @param array<string, PresetCellDelta> $cells
     */
    private function __construct(private readonly array $cells)
    {
    }

    public static function create(): self
    {
        return new self([]);
    }

    public function imageCompress(OptimizeFor $level, ?ImageCompressPresetOptions $input = null): self
    {
        return $this->with('image_compress', $level, $input ?? new ImageCompressPresetOptions());
    }

    public function audioCompress(OptimizeFor $level, ?AudioCompressPresetOptions $input = null): self
    {
        return $this->with('audio_compress', $level, $input ?? new AudioCompressPresetOptions());
    }

    public function videoCompress(OptimizeFor $level, ?VideoCompressPresetOptions $input = null): self
    {
        return $this->with('video_compress', $level, $input ?? new VideoCompressPresetOptions());
    }

    public function pdfCompress(OptimizeFor $level, ?DocumentPdfCompressPresetOptions $input = null): self
    {
        return $this->with('document_pdf_compress', $level, $input ?? new DocumentPdfCompressPresetOptions());
    }

    public function officeCompress(OptimizeFor $level, ?DocumentOfficeCompressPresetOptions $input = null): self
    {
        return $this->with('document_office_compress', $level, $input ?? new DocumentOfficeCompressPresetOptions());
    }

    public function odfCompress(OptimizeFor $level, ?DocumentOdfCompressPresetOptions $input = null): self
    {
        return $this->with('document_odf_compress', $level, $input ?? new DocumentOdfCompressPresetOptions());
    }

    public function epubCompress(OptimizeFor $level, ?DocumentEpubCompressPresetOptions $input = null): self
    {
        return $this->with('document_epub_compress', $level, $input ?? new DocumentEpubCompressPresetOptions());
    }

    /**
     * Return the registered USER delta for (mediaOpKey, level), or null if
     * no delta was registered. Does NOT return shipped defaults — those
     * live on the leaf DTO via `*::shippedDefaultsFor($level)`.
     *
     * `$mediaOpKey` is the generated PRESETS key, e.g. `'image_compress'`
     * or `'document_pdf_compress'`.
     *
     * @return PresetCellDelta|null
     */
    public function cellFor(string $mediaOpKey, OptimizeFor $level): ImageCompressPresetOptions|AudioCompressPresetOptions|VideoCompressPresetOptions|DocumentPdfCompressPresetOptions|DocumentOfficeCompressPresetOptions|DocumentOdfCompressPresetOptions|DocumentEpubCompressPresetOptions|null
    {
        return $this->cells[self::key($mediaOpKey, $level)] ?? null;
    }

    /**
     * @param PresetCellDelta $delta
     */
    private function with(string $mediaOpKey, OptimizeFor $level, object $delta): self
    {
        $cells = $this->cells;
        $cells[self::key($mediaOpKey, $level)] = $delta;

        return new self($cells);
    }

    private static function key(string $mediaOpKey, OptimizeFor $level): string
    {
        return $mediaOpKey . ':' . $level->value;
    }
}
