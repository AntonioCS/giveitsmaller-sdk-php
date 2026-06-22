<?php

/**
 * CODE GENERATED — DO NOT EDIT.
 * Source: compression_contracts/sdk-spec/ (see sdk-spec/README.md).
 * Regenerate with: scripts/generate.py.
 */

declare(strict_types=1);

namespace Gisl\Sdk\Generated\SdkSpec;

use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;

/**
 * Flat preset matrix lookup — the shipped per-(mediaOp, level) default
 * cells the ergonomic resolver merges over caller options. Mirrors the
 * TS reference at packages/typescript/src/generated/sdk_spec/presets.ts:
 * camelCase field keys, enum-typed values as canonical-name strings.
 */
final class Presets
{
    /**
     * @var array<string, array<string, array<string, string|int|bool>>>
     */
    public const PRESETS = [
        'image_compress' => [
            'Size' => [
                'quality' => 65,
                'metadata' => 'All',
                'outputFormat' => 'Original',
            ],
            'Balanced' => [
                'quality' => 80,
                'metadata' => 'All',
                'outputFormat' => 'Original',
            ],
            'Quality' => [
                'quality' => 92,
                'metadata' => 'All',
                'outputFormat' => 'Original',
            ],
        ],
        'audio_compress' => [
            'Size' => [
                'bitrate' => '_96',
                'sampleRate' => '_44100',
                'normalize' => true,
            ],
            'Balanced' => [
                'bitrate' => '_192',
                'sampleRate' => '_44100',
                'normalize' => true,
            ],
            'Quality' => [
                'bitrate' => '_320',
                'sampleRate' => '_48000',
                'normalize' => false,
            ],
        ],
        'video_compress' => [
            'Size' => [
                'crf' => 30,
                'preset' => 'Slow',
            ],
            'Balanced' => [
                'crf' => 23,
                'preset' => 'Medium',
            ],
            'Quality' => [
                'crf' => 18,
                'preset' => 'Slow',
            ],
        ],
        'document_pdf_compress' => [
            'Size' => [
                'profile' => 'Screen',
                'grayscale' => true,
            ],
            'Balanced' => [
                'profile' => 'Ebook',
                'grayscale' => false,
            ],
            'Quality' => [
                'profile' => 'Printer',
                'grayscale' => false,
            ],
        ],
        'document_office_compress' => [
            'Size' => [
                'imageQuality' => 60,
                'stripMacros' => true,
                'stripHiddenData' => true,
                'stripUnusedFonts' => true,
            ],
            'Balanced' => [
                'imageQuality' => 80,
                'stripMacros' => true,
                'stripHiddenData' => false,
                'stripUnusedFonts' => false,
            ],
            'Quality' => [
                'imageQuality' => 92,
                'stripMacros' => false,
                'stripHiddenData' => false,
                'stripUnusedFonts' => false,
            ],
        ],
        'document_odf_compress' => [
            'Size' => [
                'imageQuality' => 60,
                'stripMetadata' => true,
                'stripUnusedStyles' => true,
            ],
            'Balanced' => [
                'imageQuality' => 80,
                'stripMetadata' => true,
                'stripUnusedStyles' => false,
            ],
            'Quality' => [
                'imageQuality' => 92,
                'stripMetadata' => false,
                'stripUnusedStyles' => false,
            ],
        ],
        'document_epub_compress' => [
            'Size' => [
                'imageQuality' => 60,
                'fontSubsetting' => true,
                'stripUnusedCss' => true,
            ],
            'Balanced' => [
                'imageQuality' => 80,
                'fontSubsetting' => true,
                'stripUnusedCss' => false,
            ],
            'Quality' => [
                'imageQuality' => 92,
                'fontSubsetting' => false,
                'stripUnusedCss' => false,
            ],
        ],
    ];

    /** @codeCoverageIgnore */
    private function __construct()
    {
    }

    /**
     * Look up the shipped preset cell for (mediaOp, level).
     *
     * @return array<string, string|int|bool>
     *
     * @throws \InvalidArgumentException on an unknown mediaOp or level.
     */
    public static function shippedDefaultsFor(string $mediaOp, OptimizeFor $level): array
    {
        $group = self::PRESETS[$mediaOp] ?? null;
        if ($group === null) {
            throw new \InvalidArgumentException("Unknown preset mediaOp: {$mediaOp}");
        }
        $cell = $group[$level->value] ?? null;
        if ($cell === null) {
            throw new \InvalidArgumentException("Unknown preset level for {$mediaOp}: {$level->value}");
        }

        return $cell;
    }
}
