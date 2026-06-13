<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\Generated\SdkSpec\Enums\AudioBitrate;
use Gisl\Sdk\Generated\SdkSpec\Enums\AudioSampleRate;
use Gisl\Sdk\Generated\SdkSpec\Enums\IccProfilePolicy;
use Gisl\Sdk\Generated\SdkSpec\Enums\ImageFormat;
use Gisl\Sdk\Generated\SdkSpec\Enums\ImageMetadataPolicy;
use Gisl\Sdk\Generated\SdkSpec\Enums\ImageMode;
use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\Generated\SdkSpec\Enums\PdfColorspace;
use Gisl\Sdk\Generated\SdkSpec\Enums\PdfProfile;
use Gisl\Sdk\Generated\SdkSpec\Enums\VideoPreset;
use Gisl\Sdk\Generated\SdkSpec\Presets;
use Gisl\Sdk\Preset\AudioCompressPresetOptions;
use Gisl\Sdk\Preset\DocumentEpubCompressPresetOptions;
use Gisl\Sdk\Preset\DocumentOdfCompressPresetOptions;
use Gisl\Sdk\Preset\DocumentOfficeCompressPresetOptions;
use Gisl\Sdk\Preset\DocumentPdfCompressPresetOptions;
use Gisl\Sdk\Preset\ImageCompressPresetOptions;
use Gisl\Sdk\Preset\PresetCellTranslator;
use Gisl\Sdk\Preset\VideoCompressPresetOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PresetCellTranslator::class)]
#[CoversClass(ImageCompressPresetOptions::class)]
#[CoversClass(AudioCompressPresetOptions::class)]
#[CoversClass(VideoCompressPresetOptions::class)]
#[CoversClass(DocumentPdfCompressPresetOptions::class)]
#[CoversClass(DocumentOfficeCompressPresetOptions::class)]
#[CoversClass(DocumentOdfCompressPresetOptions::class)]
#[CoversClass(DocumentEpubCompressPresetOptions::class)]
final class PresetCompressOptionsTest extends TestCase
{
    // -----------------------------------------------------------------
    // PresetCellTranslator
    // -----------------------------------------------------------------

    public function testCaseByNameResolvesStringBackedEnum(): void
    {
        $this->assertSame(ImageMode::Lossy, PresetCellTranslator::caseByName(ImageMode::class, 'Lossy'));
    }

    public function testCaseByNameResolvesIntBackedEnumByName(): void
    {
        // Cells store the canonical NAME '_96', not the int wire value 96 —
        // so name-matching (not ::from) is required for int-backed enums.
        $this->assertSame(AudioBitrate::_96, PresetCellTranslator::caseByName(AudioBitrate::class, '_96'));
    }

    public function testCaseByNameThrowsOnUnknownName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'Nope' is not a case of");
        PresetCellTranslator::caseByName(ImageMode::class, 'Nope');
    }

    public function testCellReadersReturnNullWhenAbsent(): void
    {
        $this->assertNull(PresetCellTranslator::enum([], 'mode', ImageMode::class));
        $this->assertNull(PresetCellTranslator::int([], 'quality'));
        $this->assertNull(PresetCellTranslator::bool([], 'progressive'));
    }

    public function testEnumReaderThrowsOnNonStringCellValue(): void
    {
        // An int leaking into an enum-typed field is a generator-invariant
        // violation and must fail loudly (distinct from caseByName's
        // unknown-name InvalidArgumentException).
        $this->expectException(\LogicException::class);
        PresetCellTranslator::enum(['mode' => 96], 'mode', ImageMode::class);
    }

    public function testIntReaderThrowsOnNonInt(): void
    {
        $this->expectException(\LogicException::class);
        PresetCellTranslator::int(['quality' => 'oops'], 'quality');
    }

    public function testBoolReaderThrowsOnNonBool(): void
    {
        $this->expectException(\LogicException::class);
        PresetCellTranslator::bool(['progressive' => 1], 'progressive');
    }

    // -----------------------------------------------------------------
    // Leaf DTOs — named-arg construction (sparse)
    // -----------------------------------------------------------------

    public function testLeafDtoNamedArgConstructionIsSparse(): void
    {
        $dto = new ImageCompressPresetOptions(mode: ImageMode::Lossy, quality: 75);
        $this->assertSame(ImageMode::Lossy, $dto->mode);
        $this->assertSame(75, $dto->quality);
        // Unset fields stay null (sparse delta).
        $this->assertNull($dto->metadata);
        $this->assertNull($dto->outputFormat);
    }

    // -----------------------------------------------------------------
    // shippedDefaultsFor — values come from the generated PRESETS matrix
    // -----------------------------------------------------------------

    public function testImageShippedDefaultsForSize(): void
    {
        $dto = ImageCompressPresetOptions::shippedDefaultsFor(OptimizeFor::Size);
        $this->assertSame(ImageMode::Lossy, $dto->mode);
        $this->assertSame(65, $dto->quality);
        $this->assertSame(ImageMetadataPolicy::All, $dto->metadata);
        $this->assertSame(IccProfilePolicy::Strip, $dto->iccProfile);
        $this->assertSame(ImageFormat::Smallest, $dto->outputFormat);
    }

    public function testImageShippedDefaultsForQualityOmitsQuality(): void
    {
        // The Quality cell omits `quality` (contract gates it on mode:lossy);
        // a sparse null must result, NOT a hardcoded fallback.
        $dto = ImageCompressPresetOptions::shippedDefaultsFor(OptimizeFor::Quality);
        $this->assertSame(ImageMode::Lossless, $dto->mode);
        $this->assertNull($dto->quality);
        $this->assertSame(ImageFormat::Original, $dto->outputFormat);
    }

    public function testAudioShippedDefaultsForSize(): void
    {
        $dto = AudioCompressPresetOptions::shippedDefaultsFor(OptimizeFor::Size);
        $this->assertSame(AudioBitrate::_96, $dto->bitrate);
        $this->assertSame(AudioSampleRate::_44100, $dto->sampleRate);
        $this->assertTrue($dto->normalize);
        $this->assertNull($dto->channels);
    }

    public function testVideoShippedDefaultsForSize(): void
    {
        // Video is the only DTO that resolves an int-backed enum
        // (audioBitrate '_96') through the enum() reader against a live cell
        // — this exercises that name->case wiring end-to-end.
        $dto = VideoCompressPresetOptions::shippedDefaultsFor(OptimizeFor::Size);
        $this->assertSame(30, $dto->crf);
        $this->assertSame(VideoPreset::Slow, $dto->preset);
        $this->assertSame(AudioBitrate::_96, $dto->audioBitrate);
        // v2.66.0 (contracts ADR-0020): presets no longer bake codec /
        // audioCodec / faststart — the server container-resolves those so a
        // WebM target cannot 422 on a baked MP4-oriented codec.
        $this->assertNull($dto->codec);
        $this->assertNull($dto->faststart);
        $this->assertNull($dto->audioCodec);
        // Per-call / sparse knobs absent from the cell stay null.
        $this->assertNull($dto->targetSize);
        $this->assertNull($dto->width);
        $this->assertNull($dto->height);
        $this->assertNull($dto->fit);
        $this->assertNull($dto->fps);
    }

    public function testPdfShippedDefaultsForSize(): void
    {
        $dto = DocumentPdfCompressPresetOptions::shippedDefaultsFor(OptimizeFor::Size);
        $this->assertSame(PdfProfile::Max, $dto->profile);
        $this->assertSame(PdfColorspace::Grayscale, $dto->colorspace);
        $this->assertFalse($dto->flattenForms);
    }

    public function testOfficeShippedDefaultsForSize(): void
    {
        $dto = DocumentOfficeCompressPresetOptions::shippedDefaultsFor(OptimizeFor::Size);
        $this->assertSame(60, $dto->imageQuality);
        $this->assertTrue($dto->stripMacros);
        $this->assertTrue($dto->stripHiddenData);
        $this->assertTrue($dto->stripUnusedFonts);
    }

    public function testOdfShippedDefaultsForSize(): void
    {
        $dto = DocumentOdfCompressPresetOptions::shippedDefaultsFor(OptimizeFor::Size);
        $this->assertSame(60, $dto->imageQuality);
        $this->assertTrue($dto->stripMetadata);
        $this->assertTrue($dto->stripUnusedStyles);
    }

    public function testEpubShippedDefaultsForSize(): void
    {
        $dto = DocumentEpubCompressPresetOptions::shippedDefaultsFor(OptimizeFor::Size);
        $this->assertSame(60, $dto->imageQuality);
        $this->assertTrue($dto->fontSubsetting);
        $this->assertTrue($dto->stripUnusedCss);
    }

    /**
     * Drift guard: the DTO's enum fields must carry the SAME canonical name
     * the generated PRESETS cell stores — proving shippedDefaultsFor reads
     * the generated lookup rather than hardcoding values.
     */
    public function testShippedDefaultsAreDerivedFromGeneratedLookup(): void
    {
        foreach (OptimizeFor::cases() as $level) {
            $cell = Presets::shippedDefaultsFor('image_compress', $level);
            $dto = ImageCompressPresetOptions::shippedDefaultsFor($level);

            if (\array_key_exists('mode', $cell)) {
                $this->assertNotNull($dto->mode);
                $this->assertSame($cell['mode'], $dto->mode->name);
            }
            if (\array_key_exists('outputFormat', $cell)) {
                $this->assertNotNull($dto->outputFormat);
                $this->assertSame($cell['outputFormat'], $dto->outputFormat->name);
            }
            // A field absent from the cell must be null on the DTO.
            if (!\array_key_exists('quality', $cell)) {
                $this->assertNull($dto->quality);
            }
        }
    }
}
