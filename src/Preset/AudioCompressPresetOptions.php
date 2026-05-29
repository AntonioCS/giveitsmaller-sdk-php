<?php

declare(strict_types=1);

namespace Gisl\Sdk\Preset;

use Gisl\Sdk\Generated\SdkSpec\Enums\AudioBitrate;
use Gisl\Sdk\Generated\SdkSpec\Enums\AudioSampleRate;
use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\Generated\SdkSpec\Presets;

/**
 * Audio-compress preset leaf DTO — sparse delta. Mirrors the TS
 * `AudioCompressPresetOptions` (T4a). Field set: bitrate, channels,
 * sampleRate, normalize. `trimStart`/`trimEnd` are deliberately excluded
 * (destructive content selection, never a preset default).
 */
final class AudioCompressPresetOptions
{
    public function __construct(
        public readonly ?AudioBitrate $bitrate = null,
        public readonly ?int $channels = null,
        public readonly ?AudioSampleRate $sampleRate = null,
        public readonly ?bool $normalize = null,
    ) {
    }

    public static function shippedDefaultsFor(OptimizeFor $level): self
    {
        $cell = Presets::shippedDefaultsFor('audio_compress', $level);

        return new self(
            bitrate: PresetCellTranslator::enum($cell, 'bitrate', AudioBitrate::class),
            channels: PresetCellTranslator::int($cell, 'channels'),
            sampleRate: PresetCellTranslator::enum($cell, 'sampleRate', AudioSampleRate::class),
            normalize: PresetCellTranslator::bool($cell, 'normalize'),
        );
    }
}
