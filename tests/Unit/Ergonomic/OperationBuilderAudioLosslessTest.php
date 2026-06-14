<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Ergonomic;

use Gisl\Sdk\Ergonomic\OperationBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 0Vcogefw — the LOSSLESS-audio classifiers used by the compress preset
 * resolver to drop the shipped-preset bitrate on flac/wav. PHP analogue of
 * the TS `_detectAudioLossless` unit block (`builder.test.ts`):
 *   - {@see OperationBuilder::detectAudioLossless()} — filename extension.
 *   - {@see OperationBuilder::detectAudioLosslessFromMime()} — 5-mime set.
 */
#[CoversClass(OperationBuilder::class)]
final class OperationBuilderAudioLosslessTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function losslessFilenames(): array
    {
        return [
            'song.flac' => ['song.flac'],
            'a.wav' => ['a.wav'],
            'uppercase path' => ['PATH/X.FLAC'],
            'mixed case wav' => ['audio/clip.WAV'],
        ];
    }

    #[DataProvider('losslessFilenames')]
    public function testDetectAudioLosslessTrueForFlacWav(string $input): void
    {
        $this->assertTrue(OperationBuilder::detectAudioLossless($input));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function lossyOrUnknownFilenames(): array
    {
        return [
            'mp3' => ['song.mp3'],
            'aac' => ['a.aac'],
            'm4a' => ['b.m4a'],
            'ogg' => ['c.ogg'],
            'oga' => ['d.oga'],
            'opus' => ['e.opus'],
            'unknown ext' => ['f.unknownext'],
            'no extension' => ['noextension'],
        ];
    }

    #[DataProvider('lossyOrUnknownFilenames')]
    public function testDetectAudioLosslessFalseForLossyOrUnknown(string $input): void
    {
        $this->assertFalse(OperationBuilder::detectAudioLossless($input));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function losslessMimes(): array
    {
        return [
            'audio/flac' => ['audio/flac'],
            'audio/x-flac' => ['audio/x-flac'],
            'audio/wav' => ['audio/wav'],
            'audio/x-wav' => ['audio/x-wav'],
            'audio/wave' => ['audio/wave'],
            // MIME parameters must be stripped before the exact-set lookup
            // (codex 18b6b684) — mirrors the TS Blob parameterised-MIME case.
            'audio/flac; codecs=flac' => ['audio/flac; codecs=flac'],
        ];
    }

    #[DataProvider('losslessMimes')]
    public function testDetectAudioLosslessFromMimeTrueForLosslessSet(string $mime): void
    {
        $this->assertTrue(OperationBuilder::detectAudioLosslessFromMime($mime));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonLosslessMimes(): array
    {
        return [
            'audio/mpeg' => ['audio/mpeg'],
            'audio/ogg' => ['audio/ogg'],
            'image/png' => ['image/png'],
        ];
    }

    #[DataProvider('nonLosslessMimes')]
    public function testDetectAudioLosslessFromMimeFalseForOthers(string $mime): void
    {
        $this->assertFalse(OperationBuilder::detectAudioLosslessFromMime($mime));
    }

    public function testDetectAudioLosslessFromMimeIsCaseInsensitive(): void
    {
        $this->assertTrue(OperationBuilder::detectAudioLosslessFromMime('AUDIO/FLAC'));
    }
}
