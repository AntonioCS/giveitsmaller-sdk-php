<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Ergonomic;

use Gisl\Sdk\Ergonomic\PresetResolver;
use Gisl\Sdk\Ergonomic\ResolvedOptions;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Generated\SdkSpec\Enums\IccProfilePolicy;
use Gisl\Sdk\Generated\SdkSpec\Enums\ImageMode;
use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\Generated\SdkSpec\Enums\VideoCodec;
use Gisl\Sdk\Preset\ImageCompressPresetOptions;
use Gisl\Sdk\Preset\VideoCompressPresetOptions;
use Gisl\Sdk\PresetDefaults;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PresetResolver::class)]
#[CoversClass(ResolvedOptions::class)]
final class PresetResolverTest extends TestCase
{
    // -----------------------------------------------------------------
    // Layer precedence
    // -----------------------------------------------------------------

    public function testExplicitOnlyNoOptimize(): void
    {
        $out = PresetResolver::resolveCompress('image', null, null, null, null, ['quality' => 80]);

        $this->assertSame(['quality' => 80], $out['wireOptions']);
        $ro = $out['resolvedOptions'];
        $this->assertNull($ro->preset);
        $this->assertSame(['quality' => 80], $ro->applied);
        $this->assertSame(['quality'], $ro->sources->explicit);
        $this->assertSame([], $ro->sources->sdkDefault);
        $this->assertSame([], $ro->sources->clientDefault);
        $this->assertSame('1.0', $ro->presetVersion);
        $this->assertNull($ro->presetConfigHash);
        // overrides back-compat mirrors sources.explicit.
        $this->assertSame(['quality'], $ro->overrides);
    }

    public function testShippedDefaultsAppliedWhenOptimizeSet(): void
    {
        $out = PresetResolver::resolveCompress('image', null, null, null, OptimizeFor::Size, []);
        $ro = $out['resolvedOptions'];

        $this->assertSame('Size', $ro->preset);
        // Stable cell values from the generated PRESETS image_compress/Size cell.
        $this->assertSame(65, $out['wireOptions']['quality']);
        $this->assertTrue($out['wireOptions']['auto_orient']);
        $this->assertSame('lossy', $out['wireOptions']['mode']);
        // Every shipped field is attributed to sdkDefault (wire names, sorted).
        $this->assertSame(
            ['auto_orient', 'icc_profile', 'metadata', 'mode', 'output_format', 'progressive', 'quality'],
            $ro->sources->sdkDefault,
        );
        $this->assertSame([], $ro->sources->explicit);
        // Only sdkDefault participated → no presetConfigHash.
        $this->assertNull($ro->presetConfigHash);
    }

    public function testClientDefaultBeatsShipped(): void
    {
        $defaults = PresetDefaults::create()
            ->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 75));

        $out = PresetResolver::resolveCompress('image', $defaults, null, null, OptimizeFor::Size, []);
        $ro = $out['resolvedOptions'];

        $this->assertSame(75, $out['wireOptions']['quality']);
        $this->assertSame(['quality'], $ro->sources->clientDefault);
        $this->assertNotContains('quality', $ro->sources->sdkDefault);
        $this->assertIsString($ro->presetConfigHash);
        $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $ro->presetConfigHash);
    }

    public function testCallPresetOverrideBeatsClientAndShipped(): void
    {
        $defaults = PresetDefaults::create()
            ->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 75));

        $out = PresetResolver::resolveCompress('image', $defaults, null, ['quality' => 90], OptimizeFor::Size, []);
        $ro = $out['resolvedOptions'];

        $this->assertSame(90, $out['wireOptions']['quality']);
        $this->assertSame(['quality'], $ro->sources->callPresetOverride);
        $this->assertNotContains('quality', $ro->sources->clientDefault);
        $this->assertNotContains('quality', $ro->sources->sdkDefault);
    }

    public function testExplicitBeatsEveryLowerLayer(): void
    {
        $defaults = PresetDefaults::create()
            ->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 75));

        $out = PresetResolver::resolveCompress('image', $defaults, null, ['quality' => 90], OptimizeFor::Size, ['quality' => 100]);
        $ro = $out['resolvedOptions'];

        $this->assertSame(100, $out['wireOptions']['quality']);
        $this->assertSame(['quality'], $ro->sources->explicit);
        $this->assertNotContains('quality', $ro->sources->callPresetOverride);
        $this->assertNotContains('quality', $ro->sources->clientDefault);
    }

    public function testOptimizeUnsetSkipsClientAndShippedButKeepsOverrideAndExplicit(): void
    {
        $defaults = PresetDefaults::create()
            ->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 75));

        // No optimize ⇒ shipped + client layers contribute nothing.
        $out = PresetResolver::resolveCompress('image', $defaults, null, ['progressive' => true], null, ['quality' => 50]);
        $ro = $out['resolvedOptions'];

        $this->assertNull($ro->preset);
        $this->assertEqualsCanonicalizing(['quality' => 50, 'progressive' => true], $out['wireOptions']);
        $this->assertSame([], $ro->sources->sdkDefault);
        $this->assertSame([], $ro->sources->clientDefault);
        $this->assertSame(['progressive'], $ro->sources->callPresetOverride);
        $this->assertSame(['quality'], $ro->sources->explicit);
    }

    // -----------------------------------------------------------------
    // Enum case → wire value + alias
    // -----------------------------------------------------------------

    public function testEnumCaseConvertedToWireValue(): void
    {
        $out = PresetResolver::resolveCompress('video', null, null, null, null, ['codec' => VideoCodec::H264]);
        $this->assertSame('h264', $out['wireOptions']['codec']);
    }

    public function testCamelFieldAliasedToSnakeWire(): void
    {
        $out = PresetResolver::resolveCompress('image', null, null, null, null, ['iccProfile' => IccProfilePolicy::Strip]);
        $this->assertArrayHasKey('icc_profile', $out['wireOptions']);
        $this->assertArrayNotHasKey('iccProfile', $out['wireOptions']);
        $this->assertSame('strip', $out['wireOptions']['icc_profile']);
        $this->assertSame(['icc_profile'], $out['resolvedOptions']->sources->explicit);
    }

    // -----------------------------------------------------------------
    // targetSize derivation
    // -----------------------------------------------------------------

    public function testTargetSizeStringDerivesWireFields(): void
    {
        $out = PresetResolver::resolveCompress('video', null, null, null, null, ['targetSize' => '8MB', 'codec' => 'h264']);
        $wire = $out['wireOptions'];

        $this->assertSame(8_388_608, $wire['target_size_bytes']);
        $this->assertSame('target_size', $wire['encoding_mode']);
        $this->assertSame('h264', $wire['codec']);
        $this->assertArrayNotHasKey('targetSize', $wire);
        $this->assertSame(
            ['codec', 'encoding_mode', 'target_size_bytes'],
            $out['resolvedOptions']->sources->explicit,
        );
    }

    public function testTargetSizeIntegerPassesThrough(): void
    {
        $out = PresetResolver::resolveCompress('video', null, null, null, null, ['targetSize' => 5_000_000, 'codec' => 'h264']);
        $this->assertSame(5_000_000, $out['wireOptions']['target_size_bytes']);
        $this->assertSame('target_size', $out['wireOptions']['encoding_mode']);
    }

    public function testExplicitCrfDerivesCrfEncodingMode(): void
    {
        $out = PresetResolver::resolveCompress('video', null, null, null, null, ['crf' => 23, 'codec' => 'h264']);
        $this->assertSame('crf', $out['wireOptions']['encoding_mode']);
        $this->assertSame(23, $out['wireOptions']['crf']);
    }

    public function testLowerLayerCrfDroppedWhenTargetSizeWins(): void
    {
        // sdkDefault video/Size carries crf:30; an explicit targetSize must
        // supersede it (encoding_mode=target_size) AND drop the orphaned crf
        // from both the wire and the source buckets (TS codex r1 HIGH).
        $out = PresetResolver::resolveCompress('video', null, null, null, OptimizeFor::Size, ['targetSize' => '8MB', 'codec' => 'h264']);
        $wire = $out['wireOptions'];
        $ro = $out['resolvedOptions'];

        $this->assertArrayNotHasKey('crf', $wire);
        $this->assertSame('target_size', $wire['encoding_mode']);
        $this->assertNotContains('crf', $ro->sources->sdkDefault);
        $this->assertNotContains('crf', $ro->sources->explicit);
    }

    // -----------------------------------------------------------------
    // presetConfigHash — cross-language determinism pins
    // -----------------------------------------------------------------

    public function testPresetConfigHashExactForSingleOverride(): void
    {
        $out = PresetResolver::resolveCompress('image', null, null, ['quality' => 70], null, []);
        $this->assertSame(
            'sha256:5a5b9d555e824e78f2a06f0b57fe9c5c09c9e2fc396d79f2b0510a457018bd23',
            $out['resolvedOptions']->presetConfigHash,
        );
    }

    public function testPresetConfigHashSortsKeysDeterministically(): void
    {
        // autoOrient + quality, regardless of insertion order, hash to the
        // same value (recursive key-sort, camelCase record).
        $out = PresetResolver::resolveCompress('image', null, null, ['quality' => 70, 'autoOrient' => true], null, []);
        $this->assertSame(
            'sha256:1c312c5c010b9da62ccc1050776179b736dab241d839fe827f85e5f745abe9e4',
            $out['resolvedOptions']->presetConfigHash,
        );
    }

    public function testPresetConfigHashForClientDefaultOnly(): void
    {
        // WITHIN-PHP determinism pin (NOT cross-anchored with TS). PHP and TS
        // reconstruct a registered client-default cell into different record
        // shapes, so the clientDefault-layer hash diverges across SDKs — see
        // the cross-SDK presetConfigHash follow-up. The override-path hashes
        // ARE cross-anchored (testPresetConfigHashExactForSingleOverride et al.).
        $defaults = PresetDefaults::create()
            ->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 75));
        $out = PresetResolver::resolveCompress('image', $defaults, null, null, OptimizeFor::Size, []);
        $this->assertSame(
            'sha256:2d0bc3473e067653a67d92c0e03ff3666ff498f737b74b9550eb116e91bb2c96',
            $out['resolvedOptions']->presetConfigHash,
        );
    }

    public function testEmptyRegisteredOverrideHashesAsEmptyObject(): void
    {
        // A registered-but-empty override participates (presence), and its
        // canonical form is `{}` — NOT `[]`. Locks the PHP empty-array edge.
        $out = PresetResolver::resolveCompress('image', null, null, [], null, []);
        $this->assertSame(
            'sha256:1ff6cf5e4bcbc2dbeb458597b0726417ab369f013c4696b3b1a485a475cfb25d',
            $out['resolvedOptions']->presetConfigHash,
        );
    }

    // -----------------------------------------------------------------
    // Validations (post-merge)
    // -----------------------------------------------------------------

    public function testImageLosslessWithQualityThrowsMissingDependency(): void
    {
        try {
            PresetResolver::resolveCompress('image', null, null, null, null, ['mode' => ImageMode::Lossless, 'quality' => 80]);
            $this->fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            $this->assertSame('missing_dependency', $e->reason);
            $this->assertSame(['quality', 'mode'], $e->conflictingFields);
            $this->assertIsArray($e->resolvedSnapshot);
        }
    }

    public function testTargetSizeWithNonH264ThrowsInvalidCombination(): void
    {
        try {
            PresetResolver::resolveCompress('video', null, null, null, null, ['targetSize' => '8MB', 'codec' => 'vp9']);
            $this->fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            $this->assertSame('invalid_combination', $e->reason);
            $this->assertSame(['targetSize', 'codec'], $e->conflictingFields);
        }
    }

    public function testTargetSizeWithExplicitCrfThrowsInvalidCombination(): void
    {
        try {
            PresetResolver::resolveCompress('video', null, null, null, null, ['targetSize' => '8MB', 'crf' => 23]);
            $this->fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            $this->assertSame('invalid_combination', $e->reason);
            $this->assertSame(['targetSize', 'crf'], $e->conflictingFields);
        }
    }

    public function testUnknownFieldThrows(): void
    {
        try {
            PresetResolver::resolveCompress('image', null, null, null, null, ['bogus' => 1]);
            $this->fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            $this->assertSame('unknown_field', $e->reason);
            $this->assertSame(['bogus'], $e->conflictingFields);
        }
    }

    public function testMismatchedOverrideMediaThrowsTypeMismatch(): void
    {
        try {
            // Video-only field set passed as image presetOverrides.
            PresetResolver::resolveCompress('image', null, null, ['codec' => 'h264'], null, []);
            $this->fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            $this->assertSame('type_mismatch', $e->reason);
            $this->assertSame(['codec'], $e->conflictingFields);
            $this->assertNotNull($e->suggestion);
            $this->assertStringContainsString('VideoCompressPresetOptions', (string) $e->suggestion);
        }
    }

    public function testUnknownMediaThrows(): void
    {
        $this->expectException(GislConfigError::class);
        PresetResolver::resolveCompress('hologram', null, null, null, null, []);
    }

    // -----------------------------------------------------------------
    // parseTargetSize
    // -----------------------------------------------------------------

    public function testParseTargetSizeBinaryUnits(): void
    {
        $this->assertSame(1024, PresetResolver::parseTargetSize('1KB'));
        $this->assertSame(8_388_608, PresetResolver::parseTargetSize('8MB'));
        $this->assertSame(1_610_612_736, PresetResolver::parseTargetSize('1.5GB'));
        $this->assertSame(100, PresetResolver::parseTargetSize('100')); // bare → bytes
        $this->assertSame(100, PresetResolver::parseTargetSize('100B'));
        $this->assertSame(2048, PresetResolver::parseTargetSize(2048)); // int passthrough
    }

    public function testParseTargetSizeRejectsBadInput(): void
    {
        foreach (['abc', '5XB', '0', '-5', ''] as $bad) {
            try {
                PresetResolver::parseTargetSize($bad);
                $this->fail("expected GislConfigError for '{$bad}'");
            } catch (GislConfigError $e) {
                $this->assertSame('invalid_target_size', $e->reason, "input '{$bad}'");
            }
        }
    }

    public function testParseTargetSizeRejectsNonPositiveInteger(): void
    {
        $this->expectException(GislConfigError::class);
        PresetResolver::parseTargetSize(0);
    }

    // -----------------------------------------------------------------
    // Cross-media coverage (non-image leaf wired correctly)
    // -----------------------------------------------------------------

    public function testVideoShippedDefaultsResolveThroughLeaf(): void
    {
        $out = PresetResolver::resolveCompress('video', null, null, null, OptimizeFor::Balanced, []);
        // video_compress/Balanced cell: codec H264, crf 23, etc.
        $this->assertSame('h264', $out['wireOptions']['codec']);
        $this->assertSame(23, $out['wireOptions']['crf']);
        $this->assertContains('codec', $out['resolvedOptions']->sources->sdkDefault);
    }

    public function testVideoPresetOverrideAsLeafDto(): void
    {
        // presetOverrides supplied as a typed leaf DTO instance.
        $out = PresetResolver::resolveCompress(
            'video',
            null,
            null,
            new VideoCompressPresetOptions(codec: VideoCodec::H265),
            null,
            [],
        );
        $this->assertSame('h265', $out['wireOptions']['codec']);
        $this->assertSame(['codec'], $out['resolvedOptions']->sources->callPresetOverride);
    }

    // -----------------------------------------------------------------
    // Additional coverage (review follow-ups)
    // -----------------------------------------------------------------

    public function testClientDefaultMergesFieldByFieldWithShipped(): void
    {
        // Client sets only `quality`; the shipped `mode` must survive (the
        // client layer must NOT wipe the whole sdkDefault layer).
        $defaults = PresetDefaults::create()
            ->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 75));
        $out = PresetResolver::resolveCompress('image', $defaults, null, null, OptimizeFor::Size, []);
        $ro = $out['resolvedOptions'];

        $this->assertSame(75, $out['wireOptions']['quality']);
        $this->assertSame('lossy', $out['wireOptions']['mode']);
        $this->assertSame(['quality'], $ro->sources->clientDefault);
        $this->assertContains('mode', $ro->sources->sdkDefault);
    }

    public function testTargetSizeSourcedFromClientDefaultAttributesDerivedKeys(): void
    {
        // targetSize set via a client default (NOT explicit) — the derived
        // wire keys must be attributed to the clientDefault bucket (exercises
        // the non-explicit arm of targetSizeSource()).
        $defaults = PresetDefaults::create()->videoCompress(
            OptimizeFor::Size,
            new VideoCompressPresetOptions(codec: VideoCodec::H264, targetSize: '100MB'),
        );
        $out = PresetResolver::resolveCompress('video', $defaults, null, null, OptimizeFor::Size, []);
        $wire = $out['wireOptions'];
        $ro = $out['resolvedOptions'];

        $this->assertSame(104_857_600, $wire['target_size_bytes']);
        $this->assertSame('target_size', $wire['encoding_mode']);
        $this->assertSame('h264', $wire['codec']);
        $this->assertContains('target_size_bytes', $ro->sources->clientDefault);
        $this->assertContains('encoding_mode', $ro->sources->clientDefault);
        $this->assertArrayNotHasKey('crf', $wire); // sdk crf dropped under target_size
    }

    public function testScopedDefaultStaysEmptyInP6(): void
    {
        $defaults = PresetDefaults::create()
            ->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 75));
        $out = PresetResolver::resolveCompress('image', $defaults, null, ['progressive' => false], OptimizeFor::Size, ['quality' => 100]);
        // scopedDefault is reserved for P7 — always empty regardless of the
        // other layers being populated.
        $this->assertSame([], $out['resolvedOptions']->sources->scopedDefault);
    }

    public function testMixedOverrideKeysFallThroughToUnknownField(): void
    {
        // `bogus` belongs to no media, so detectMismatchedOverrides must NOT
        // raise type_mismatch — it falls through to the merged unknown_field
        // check (reason unknown_field, not type_mismatch).
        try {
            PresetResolver::resolveCompress('image', null, null, ['quality' => 70, 'bogus' => 1], null, []);
            $this->fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            $this->assertSame('unknown_field', $e->reason);
            $this->assertSame(['bogus'], $e->conflictingFields);
        }
    }

    public function testParseTargetSizeRejectsNonStringNonIntWithConflictingFields(): void
    {
        foreach ([1.5, true, []] as $bad) {
            try {
                PresetResolver::parseTargetSize($bad);
                $this->fail('expected GislConfigError for ' . \get_debug_type($bad));
            } catch (GislConfigError $e) {
                $this->assertSame('invalid_target_size', $e->reason);
                $this->assertSame(['targetSize'], $e->conflictingFields);
            }
        }
    }

    public function testWrongMediaTypedDtoRejectedEvenWithOverlappingFields(): void
    {
        // `width` exists on both image and video, so field-based detection
        // can't catch it — but the DTO's class media (video) does not match
        // the operation media (image), so the class guard rejects it.
        try {
            PresetResolver::resolveCompress(
                'image',
                null,
                null,
                new VideoCompressPresetOptions(width: 100),
                null,
                [],
            );
            $this->fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            $this->assertSame('type_mismatch', $e->reason);
            $this->assertSame(['presetOverrides'], $e->conflictingFields);
        }
    }

    public function testExplicitCrfNullStillDerivesEncodingMode(): void
    {
        // An explicit `crf => null` array key is PRESENT (mirrors TS
        // `!== undefined`), so encoding_mode='crf' is still derived.
        $out = PresetResolver::resolveCompress('video', null, null, null, null, ['crf' => null]);
        $this->assertArrayHasKey('encoding_mode', $out['wireOptions']);
        $this->assertSame('crf', $out['wireOptions']['encoding_mode']);
    }

    public function testArrayExplicitNullPreserved(): void
    {
        // Array-provided null is intentional (no `undefined` in PHP) and is
        // kept, unlike a sparse DTO's null which is dropped.
        $out = PresetResolver::resolveCompress('image', null, null, null, null, ['quality' => null]);
        $this->assertArrayHasKey('quality', $out['wireOptions']);
        $this->assertNull($out['wireOptions']['quality']);
    }
}
