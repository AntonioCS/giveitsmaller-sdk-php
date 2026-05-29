<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\Preset\ImageCompressPresetOptions;
use Gisl\Sdk\Preset\VideoCompressPresetOptions;
use Gisl\Sdk\PresetDefaults;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PresetDefaults::class)]
final class PresetDefaultsTest extends TestCase
{
    public function testCreateStartsEmpty(): void
    {
        $defaults = PresetDefaults::create();
        $this->assertNull($defaults->cellFor('image_compress', OptimizeFor::Size));
    }

    public function testPerCellMethodsAreImmutable(): void
    {
        $base = PresetDefaults::create();
        $a = $base->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 75));
        $b = $base->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 90));

        // Same method twice on the same instance yields distinct objects.
        $this->assertNotSame($a, $b);
        $this->assertNotSame($base, $a);

        // The base is untouched by the derive calls.
        $this->assertNull($base->cellFor('image_compress', OptimizeFor::Size));

        $aCell = $a->cellFor('image_compress', OptimizeFor::Size);
        $bCell = $b->cellFor('image_compress', OptimizeFor::Size);
        $this->assertInstanceOf(ImageCompressPresetOptions::class, $aCell);
        $this->assertInstanceOf(ImageCompressPresetOptions::class, $bCell);
        $this->assertSame(75, $aCell->quality);
        $this->assertSame(90, $bCell->quality);
    }

    public function testRegistrationsAccumulateAcrossCells(): void
    {
        $defaults = PresetDefaults::create()
            ->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 70))
            ->videoCompress(OptimizeFor::Quality);

        $this->assertInstanceOf(
            ImageCompressPresetOptions::class,
            $defaults->cellFor('image_compress', OptimizeFor::Size),
        );
        $this->assertInstanceOf(
            VideoCompressPresetOptions::class,
            $defaults->cellFor('video_compress', OptimizeFor::Quality),
        );
        // A level that was never registered for video stays null.
        $this->assertNull($defaults->cellFor('video_compress', OptimizeFor::Size));
    }

    public function testEmptyInputRegistersAnEmptyDelta(): void
    {
        // Calling with no input registers an empty delta (distinct from "not
        // registered") — the resolver reads it as "apply shipped defaults".
        $defaults = PresetDefaults::create()->videoCompress(OptimizeFor::Balanced);

        $cell = $defaults->cellFor('video_compress', OptimizeFor::Balanced);
        $this->assertInstanceOf(VideoCompressPresetOptions::class, $cell);
        $this->assertNull($cell->codec);
        $this->assertNull($cell->crf);
    }

    public function testCellForDistinguishesLevels(): void
    {
        $defaults = PresetDefaults::create()
            ->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 60));

        $this->assertNotNull($defaults->cellFor('image_compress', OptimizeFor::Size));
        $this->assertNull($defaults->cellFor('image_compress', OptimizeFor::Balanced));
        $this->assertNull($defaults->cellFor('image_compress', OptimizeFor::Quality));
    }
}
