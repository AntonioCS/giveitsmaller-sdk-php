<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Ergonomic;

use Gisl\Sdk\Ergonomic\ClipOptions;
use Gisl\Sdk\Ergonomic\Handle;
use Gisl\Sdk\Ergonomic\Merge;
use Gisl\Sdk\Ergonomic\MergeBuilder;
use Gisl\Sdk\Ergonomic\MergeOptions;
use Gisl\Sdk\Ergonomic\PathAsset;
use Gisl\Sdk\Ergonomic\SubmitOptions;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\GislErgonomicClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(MergeBuilder::class)]
final class MergeBuilderTest extends TestCase
{
    public function test_submit_uploads_each_unique_asset_once_then_creates_workflow(): void
    {
        $pathA = self::writeTempFile('aaaa');
        $pathB = self::writeTempFile('bbbb');

        $captured = [];
        $http = self::stubClient([
            self::uploadResponse('01936fb1-7bb3-7000-8000-00000000aa01', 'a.mp4', 'video/mp4', 4),
            self::uploadResponse('01936fb1-7bb3-7000-8000-00000000aa02', 'b.mp4', 'video/mp4', 4),
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-00000000aa03'),
        ], $captured);

        $client = self::makeClient($http);
        $handle = $client
            ->merge([$pathA, $pathB], new MergeOptions(mediaKind: 'video'))
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        $this->assertInstanceOf(Handle::class, $handle);
        $this->assertSame('01936fb2-0000-7000-8000-00000000aa03', $handle->workflowId);
        // FF5a back-compat: submit() now returns the enriched Handle CLASS, but
        // its toArray() shape must stay byte-identical to {workflowId,
        // webhookSecret} — no `client` leakage, no extra keys.
        $this->assertSame(
            [
                'workflowId' => '01936fb2-0000-7000-8000-00000000aa03',
                'webhookSecret' => 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789',
            ],
            $handle->toArray(),
        );
        $this->assertArrayNotHasKey('client', $handle->toArray());

        // 2 uploads + 1 workflow create.
        $this->assertCount(3, $captured);
        $this->assertStringContainsString('/api/uploads', (string) $captured[0]->getUri());
        $this->assertStringContainsString('/api/uploads', (string) $captured[1]->getUri());
        $this->assertStringContainsString('/api/workflows', (string) $captured[2]->getUri());

        $body = self::decodeJson($captured[2]);
        $this->assertSame('https://example.com/cb', $body['callback_url']);
        // p0SuJEeK — 2 unique assets → 2 passthrough src jobs + merge job last.
        $mergeJob = $this->assertMergeShapeAndReturnMergeJob($body, 2);
        $this->assertCount(2, $mergeJob['inputs']);
        // Each merge input references its source job; the uploaded file_ids
        // now live on the src jobs, not the merge inputs.
        $this->assertSame('src_0', $mergeJob['inputs'][0]['source']['from']);
        $this->assertSame('src_1', $mergeJob['inputs'][1]['source']['from']);
        $this->assertSame('01936fb1-7bb3-7000-8000-00000000aa01', $body['jobs'][0]['source']['file_id']);
        $this->assertSame('01936fb1-7bb3-7000-8000-00000000aa02', $body['jobs'][1]['source']['file_id']);
        $this->assertArrayNotHasKey('per_input_options', $mergeJob['inputs'][0]);
        $this->assertArrayNotHasKey('per_input_options', $mergeJob['inputs'][1]);
    }

    public function test_sequence_with_clip_emits_per_input_options_on_video(): void
    {
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');

        $captured = [];
        $http = self::stubClient([
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000a1', 'a.mp4', 'video/mp4', 1),
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000b2', 'b.mp4', 'video/mp4', 1),
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-000000000a01'),
        ], $captured);

        $client = self::makeClient($http);
        $client->merge([$pathA, $pathB], new MergeOptions(mediaKind: 'video'))
            ->sequence([
                Merge::asset($pathA),
                Merge::clip($pathB, new ClipOptions(transition: 'crossfade', crossfadeDuration: 1.5)),
            ])
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        $body = self::decodeJson($captured[2]);
        $mergeJob = $this->assertMergeShapeAndReturnMergeJob($body, 2);
        $inputs = $mergeJob['inputs'];
        $this->assertCount(2, $inputs);
        $this->assertArrayNotHasKey('per_input_options', $inputs[0]);
        $this->assertSame(
            ['transition' => 'crossfade', 'crossfade_duration' => 1.5],
            $inputs[1]['per_input_options'],
        );
    }

    public function test_handle_asset_short_circuits_upload(): void
    {
        $pathA = self::writeTempFile('a');

        $captured = [];
        $http = self::stubClient([
            // Only ONE upload — the handle skips its own upload.
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000a1', 'a.mp4', 'video/mp4', 1),
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-000000000a02'),
        ], $captured);

        $client = self::makeClient($http);
        $client->merge(
            [Merge::asset($pathA), Merge::handle('01936fb1-7bb3-7000-8000-0000000000d4')],
            new MergeOptions(mediaKind: 'video'),
        )->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        // Only 2 outbound requests (1 upload + 1 workflow create).
        $this->assertCount(2, $captured);
        $body = self::decodeJson($captured[1]);
        // p0SuJEeK — a handle asset still gets its own passthrough src job;
        // the file_ids (uploaded + handle) live on the src jobs now.
        $mergeJob = $this->assertMergeShapeAndReturnMergeJob($body, 2);
        $this->assertSame('src_0', $mergeJob['inputs'][0]['source']['from']);
        $this->assertSame('src_1', $mergeJob['inputs'][1]['source']['from']);
        $this->assertSame('01936fb1-7bb3-7000-8000-0000000000a1', $body['jobs'][0]['source']['file_id']);
        $this->assertSame('01936fb1-7bb3-7000-8000-0000000000d4', $body['jobs'][1]['source']['file_id']);
    }

    public function test_repeated_path_dedupes_to_one_upload(): void
    {
        // ONE path referenced THREE times in the asset set + sequence → one upload.
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');

        $captured = [];
        $http = self::stubClient([
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000a1', 'a.mp4', 'video/mp4', 1),
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000b2', 'b.mp4', 'video/mp4', 1),
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-000000000a03'),
        ], $captured);

        $client = self::makeClient($http);
        $client->merge([$pathA, $pathB, $pathA], new MergeOptions(mediaKind: 'video'))
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        // Three positions, two uploads. Wire emits three merge `inputs[]`
        // entries but only TWO src jobs (the repeated pathA re-uses its src).
        $this->assertCount(3, $captured);
        $body = self::decodeJson($captured[2]);
        $mergeJob = $this->assertMergeShapeAndReturnMergeJob($body, 2);
        $this->assertCount(3, $mergeJob['inputs']);
        // Positions 0 and 2 are the same asset → SAME src job (not a second one).
        $this->assertSame(
            $mergeJob['inputs'][0]['source']['from'],
            $mergeJob['inputs'][2]['source']['from'],
        );
    }

    public function test_two_distinct_path_asset_objects_with_same_path_dedupe_to_one_upload(): void
    {
        // Reality-check from karen: PHP value semantics — two distinct
        // `PathAsset` instances carrying the same path must dedupe by
        // identity-string, not object reference.
        $path = self::writeTempFile('shared');

        $captured = [];
        $http = self::stubClient([
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000c3', 'shared.mp4', 'video/mp4', 6),
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-000000000a04'),
        ], $captured);

        $a = new PathAsset($path);
        $b = new PathAsset($path);
        $this->assertNotSame($a, $b, 'precondition: distinct objects');

        $client = self::makeClient($http);
        $client->merge([$a, $b], new MergeOptions(mediaKind: 'video'))
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        $this->assertCount(2, $captured, 'two distinct PathAsset objects with identical path string upload once');
        $body = self::decodeJson($captured[1]);
        // 1 unique asset → 1 src job; both merge positions reference it.
        $mergeJob = $this->assertMergeShapeAndReturnMergeJob($body, 1);
        $this->assertCount(2, $mergeJob['inputs']);
        $this->assertSame(
            $mergeJob['inputs'][0]['source']['from'],
            $mergeJob['inputs'][1]['source']['from'],
            'both positions point at the same src job (same uploaded file_id)',
        );
    }

    public function test_undeclared_sequence_ref_raises_config_error(): void
    {
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');
        $pathC = self::writeTempFile('c');

        $captured = [];
        $client = self::makeClient(self::stubClient([], $captured));

        $this->expectException(GislConfigError::class);
        $this->expectExceptionMessageMatches('/not declared/');

        $client->merge([$pathA, $pathB], new MergeOptions(mediaKind: 'video'))
            ->sequence([Merge::asset($pathA), Merge::asset($pathC)])
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));
    }

    public function test_unused_declared_asset_raises_config_error(): void
    {
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');
        $pathC = self::writeTempFile('c');

        $captured = [];
        $client = self::makeClient(self::stubClient([], $captured));

        $this->expectException(GislConfigError::class);
        $this->expectExceptionMessageMatches('/never referenced in sequence/');

        $client->merge([$pathA, $pathB, $pathC], new MergeOptions(mediaKind: 'video'))
            // Sequence omits $pathC — caught by unused-asset check.
            ->sequence([Merge::asset($pathA), Merge::asset($pathB)])
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));
    }

    public function test_allow_unused_assets_bypasses_unused_check_and_skips_upload(): void
    {
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');
        $pathC = self::writeTempFile('c');

        $captured = [];
        $http = self::stubClient([
            // Only TWO uploads — pathC declared but never sequenced.
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000a1', 'a.mp4', 'video/mp4', 1),
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000b2', 'b.mp4', 'video/mp4', 1),
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-000000000a05'),
        ], $captured);

        $client = self::makeClient($http);
        $client->merge(
            [$pathA, $pathB, $pathC],
            new MergeOptions(mediaKind: 'video', allowUnusedAssets: true),
        )
            ->sequence([Merge::asset($pathA), Merge::asset($pathB)])
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        $this->assertCount(3, $captured, 'pathC declared-but-unsequenced does NOT upload');
    }

    public function test_image_merge_rejects_per_input_clip_options(): void
    {
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');

        $captured = [];
        $client = self::makeClient(self::stubClient([], $captured));

        $this->expectException(GislConfigError::class);
        $this->expectExceptionMessageMatches('/image merges do not support per-input/');

        $client->merge([$pathA, $pathB], new MergeOptions(mediaKind: 'image'))
            ->sequence([
                Merge::asset($pathA),
                Merge::clip($pathB, new ClipOptions(transition: 'fade')),
            ])
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));
    }

    public function test_video_merge_drops_merge_level_gap_duration(): void
    {
        // Video merges don't support merge-level gap_duration (TS R2 medium
        // ab2422e56ea0). Set it and assert it's stripped from the wire.
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');

        $captured = [];
        $http = self::stubClient([
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000a1', 'a.mp4', 'video/mp4', 1),
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000b2', 'b.mp4', 'video/mp4', 1),
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-000000000a06'),
        ], $captured);

        $client = self::makeClient($http);
        $client->merge(
            [$pathA, $pathB],
            new MergeOptions(mediaKind: 'video', gapDuration: 2.0, transition: 'fade'),
        )->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        $body = self::decodeJson($captured[2]);
        $mergeJob = $this->assertMergeShapeAndReturnMergeJob($body, 2);
        $options = $mergeJob['operations'][0]['options'];
        $this->assertSame('fade', $options['transition']);
        $this->assertArrayNotHasKey('gap_duration', $options);
    }

    public function test_audio_merge_keeps_merge_level_gap_duration(): void
    {
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');

        $captured = [];
        $http = self::stubClient([
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000a1', 'a.mp3', 'audio/mpeg', 1),
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000b2', 'b.mp3', 'audio/mpeg', 1),
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-000000000a07'),
        ], $captured);

        $client = self::makeClient($http);
        $client->merge(
            [$pathA, $pathB],
            new MergeOptions(mediaKind: 'audio', gapDuration: 2.5),
        )->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        $body = self::decodeJson($captured[2]);
        $mergeJob = $this->assertMergeShapeAndReturnMergeJob($body, 2);
        $this->assertSame(2.5, $mergeJob['operations'][0]['options']['gap_duration']);
    }

    public function test_video_per_input_clip_drops_gap_duration(): void
    {
        // Per-input gap_duration is audio-only (TS R1 medium 128404fa16a9).
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');

        $captured = [];
        $http = self::stubClient([
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000a1', 'a.mp4', 'video/mp4', 1),
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000b2', 'b.mp4', 'video/mp4', 1),
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-000000000a08'),
        ], $captured);

        $client = self::makeClient($http);
        $client->merge([$pathA, $pathB], new MergeOptions(mediaKind: 'video'))
            ->sequence([
                Merge::asset($pathA),
                Merge::clip($pathB, new ClipOptions(transition: 'crossfade', gapDuration: 1.0)),
            ])
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        $body = self::decodeJson($captured[2]);
        $mergeJob = $this->assertMergeShapeAndReturnMergeJob($body, 2);
        $perInput = $mergeJob['inputs'][1]['per_input_options'];
        $this->assertSame('crossfade', $perInput['transition']);
        $this->assertArrayNotHasKey('gap_duration', $perInput);
    }

    public function test_single_asset_below_min_inputs_raises_config_error(): void
    {
        $pathA = self::writeTempFile('a');

        $captured = [];
        $client = self::makeClient(self::stubClient([], $captured));

        $this->expectException(GislConfigError::class);
        $this->expectExceptionMessageMatches('/at least 2 inputs/');

        $client->merge([$pathA], new MergeOptions(mediaKind: 'video'))
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));
    }

    public function test_sequence_above_max_inputs_raises_config_error(): void
    {
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');

        $captured = [];
        $client = self::makeClient(self::stubClient([], $captured));

        $this->expectException(GislConfigError::class);
        $this->expectExceptionMessageMatches('/at most 10 inputs/');

        // 11 positions via repeats — exceeds max_inputs=10. Both pathA and
        // pathB are referenced so the unused-asset check passes; the
        // max-inputs check fires next.
        $seq = array_merge(
            [Merge::asset($pathA), Merge::asset($pathB)],
            array_fill(0, 9, Merge::asset($pathA)),
        );
        $client->merge([$pathA, $pathB], new MergeOptions(mediaKind: 'video'))
            ->sequence($seq)
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));
    }

    public function test_invalid_target_size_string_raises_config_error(): void
    {
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');

        $captured = [];
        $http = self::stubClient([
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000a1', 'a.mp4', 'video/mp4', 1),
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000b2', 'b.mp4', 'video/mp4', 1),
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-000000000a09'),
        ], $captured);
        $client = self::makeClient($http);

        $this->expectException(GislConfigError::class);
        $this->expectExceptionMessageMatches('/Invalid targetSize/');

        $client->merge([$pathA, $pathB], new MergeOptions(mediaKind: 'video', targetSize: 'garbage'))
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));
    }

    public function test_target_size_sized_string_lowers_to_byte_count_and_encoding_mode(): void
    {
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');

        $captured = [];
        $http = self::stubClient([
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000a1', 'a.mp4', 'video/mp4', 1),
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000b2', 'b.mp4', 'video/mp4', 1),
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-000000000a0a'),
        ], $captured);
        $client = self::makeClient($http);

        $client->merge([$pathA, $pathB], new MergeOptions(mediaKind: 'video', targetSize: '10MB'))
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        $body = self::decodeJson($captured[2]);
        $opts = $this->assertMergeShapeAndReturnMergeJob($body, 2)['operations'][0]['options'];
        $this->assertSame(10_000_000, $opts['target_size_bytes']);
        $this->assertSame('target_size', $opts['encoding_mode']);
    }

    public function test_bare_string_in_merge_auto_wraps_via_path_asset(): void
    {
        // The variadic overload accepts bare strings AND Asset instances —
        // strings auto-wrap via Merge::asset().
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');

        $captured = [];
        $http = self::stubClient([
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000a1', 'a.mp4', 'video/mp4', 1),
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000b2', 'b.mp4', 'video/mp4', 1),
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-000000000a0b'),
        ], $captured);
        $client = self::makeClient($http);

        $client->merge([$pathA, $pathB])
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        $this->assertCount(3, $captured);
    }

    public function test_numeric_target_size_emits_byte_count_verbatim_with_encoding_mode(): void
    {
        // Mirrors the sized-string test; covers the `is_int` branch of
        // wireMergeOptions() which a typo in the int/string union would
        // silently skip.
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');

        $captured = [];
        $http = self::stubClient([
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000a1', 'a.mp4', 'video/mp4', 1),
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000b2', 'b.mp4', 'video/mp4', 1),
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-000000000a0d'),
        ], $captured);
        $client = self::makeClient($http);

        $client->merge([$pathA, $pathB], new MergeOptions(mediaKind: 'video', targetSize: 5_000_000))
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        $body = self::decodeJson($captured[2]);
        $opts = $this->assertMergeShapeAndReturnMergeJob($body, 2)['operations'][0]['options'];
        $this->assertSame(5_000_000, $opts['target_size_bytes']);
        $this->assertSame('target_size', $opts['encoding_mode']);
    }

    public function test_wire_merge_options_drops_video_only_fields_on_image_merge(): void
    {
        // Codex R2 medium a5aa664e6c74 — image merge allowlist is
        // {output_type, transition, transition_duration, fps,
        //  duration_per_image, loop_count, video_format}. Setting
        // video-only fields (codec, crf, preset, targetSize, normalizeAudio)
        // alongside an image merge must silently drop them locally instead
        // of paying for uploads and hitting a server-side 422.
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');

        $captured = [];
        $http = self::stubClient([
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000a1', 'a.png', 'image/png', 1),
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000b2', 'b.png', 'image/png', 1),
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-000000000a0f'),
        ], $captured);
        $client = self::makeClient($http);

        $client->merge(
            [$pathA, $pathB],
            new MergeOptions(
                mediaKind: 'image',
                output: 'video',
                // The following are wire-invalid for image merges and MUST
                // be dropped from the outbound payload.
                crossfadeDuration: 1.0,
                normalizeAudio: true,
                codec: 'h264',
                crf: 23,
                preset: 'fast',
                targetSize: '5MB',
                // image-allowed fields below
                transitionDuration: 0.5,
                fps: 24.0,
            ),
        )->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        $body = self::decodeJson($captured[2]);
        $opts = $this->assertMergeShapeAndReturnMergeJob($body, 2)['operations'][0]['options'];
        $this->assertArrayHasKey('output_type', $opts);
        $this->assertSame('video', $opts['output_type']);
        $this->assertArrayHasKey('transition_duration', $opts);
        $this->assertArrayHasKey('fps', $opts);
        $this->assertArrayNotHasKey('crossfade_duration', $opts);
        $this->assertArrayNotHasKey('normalize_audio', $opts);
        $this->assertArrayNotHasKey('codec', $opts);
        $this->assertArrayNotHasKey('crf', $opts);
        $this->assertArrayNotHasKey('preset', $opts);
        $this->assertArrayNotHasKey('target_size_bytes', $opts);
        $this->assertArrayNotHasKey('encoding_mode', $opts);
    }

    public function test_wire_merge_options_drops_image_only_fields_on_video_merge(): void
    {
        // Twin coverage of a5aa664e6c74 — image-only fields
        // (transition_duration, fps, duration_per_image, loop_count,
        // video_format) must not leak into video merge wire payload.
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');

        $captured = [];
        $http = self::stubClient([
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000a1', 'a.mp4', 'video/mp4', 1),
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000b2', 'b.mp4', 'video/mp4', 1),
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-000000000a10'),
        ], $captured);
        $client = self::makeClient($http);

        $client->merge(
            [$pathA, $pathB],
            new MergeOptions(
                mediaKind: 'video',
                codec: 'h264',
                // image-only — must drop
                transitionDuration: 0.5,
                fps: 24.0,
                durationPerImage: 2.0,
                loopCount: 1,
                videoFormat: 'webm',
            ),
        )->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        $body = self::decodeJson($captured[2]);
        $opts = $this->assertMergeShapeAndReturnMergeJob($body, 2)['operations'][0]['options'];
        $this->assertSame('h264', $opts['codec']);
        $this->assertArrayNotHasKey('transition_duration', $opts);
        $this->assertArrayNotHasKey('fps', $opts);
        $this->assertArrayNotHasKey('duration_per_image', $opts);
        $this->assertArrayNotHasKey('loop_count', $opts);
        $this->assertArrayNotHasKey('video_format', $opts);
    }

    public function test_image_merge_without_output_type_raises_config_error_pre_upload(): void
    {
        // Codex R1 medium b53ad6d22f53 — the server requires output_type
        // for image merges. Detect locally so callers don't waste uploads
        // chasing a server-side 422.
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');

        $captured = [];
        $client = self::makeClient(self::stubClient([], $captured));

        $this->expectException(GislConfigError::class);
        $this->expectExceptionMessageMatches('/image merges require an explicit output_type/');

        $client->merge([$pathA, $pathB], new MergeOptions(mediaKind: 'image'))
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        // No uploads happened — assertion would not be reached but the
        // empty captured list is the cheapest proof in case the expected
        // exception class changes upstream.
        $this->assertSame([], $captured);
    }

    public function test_invalid_target_size_string_raises_config_error_before_upload(): void
    {
        // Codex R1 medium a93bd61d39a9 — parseSizeString used to fire
        // from buildPayload() AFTER uploadUniqueAssets, so a garbage
        // targetSize would burn N uploads before throwing. The check
        // now fires inside planSequence, BEFORE any wire traffic.
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');

        $captured = [];
        $client = self::makeClient(self::stubClient([], $captured));

        $this->expectException(GislConfigError::class);
        $this->expectExceptionMessageMatches('/Invalid targetSize/');

        $client->merge([$pathA, $pathB], new MergeOptions(mediaKind: 'video', targetSize: 'garbage'))
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        $this->assertSame([], $captured, 'no uploads should fire before targetSize validation');
    }

    public function test_two_handle_assets_with_same_file_id_dedupe(): void
    {
        // Twin of the PathAsset-identity test, for HandleAsset. Two
        // distinct HandleAsset objects with identical fileId must collapse
        // to a single `inputs[]` source position — but BOTH positions still
        // emit (one per sequence position), pointing at the same fileId.
        $captured = [];
        $http = self::stubClient([
            // ZERO uploads — both assets are pre-uploaded handles.
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-000000000a0e'),
        ], $captured);
        $client = self::makeClient($http);

        $client->merge(
            [
                Merge::handle('01936fb1-7bb3-7000-8000-0000000000e5'),
                Merge::handle('01936fb1-7bb3-7000-8000-0000000000e5'),
            ],
            new MergeOptions(mediaKind: 'video'),
        )->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        // Only ONE outbound request (workflow create) — no uploads.
        $this->assertCount(1, $captured);
        $body = self::decodeJson($captured[0]);
        // Two duplicate handles → ONE unique asset → ONE src job carrying the
        // shared file_id; both merge positions reference src_0.
        $mergeJob = $this->assertMergeShapeAndReturnMergeJob($body, 1);
        $this->assertCount(
            2,
            $mergeJob['inputs'],
            'sequence positions are preserved even when handles dedupe',
        );
        $this->assertSame(
            '01936fb1-7bb3-7000-8000-0000000000e5',
            $body['jobs'][0]['source']['file_id'],
        );
        $this->assertSame(
            $mergeJob['inputs'][0]['source']['from'],
            $mergeJob['inputs'][1]['source']['from'],
        );
    }

    public function test_media_kind_inference_from_image_extension_rejects_per_input_opts(): void
    {
        // .png extension on the first asset infers 'image' kind; image
        // merges with per-input clip options must reject. A typo in the
        // extension list (e.g. swapping `jpe?g`/`png` for incorrect
        // patterns) would silently classify the merge as video and skip
        // the per-input-options rejection.
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');
        $imageA = sys_get_temp_dir() . '/gisl-merge-' . bin2hex(random_bytes(4)) . '.png';
        $imageB = sys_get_temp_dir() . '/gisl-merge-' . bin2hex(random_bytes(4)) . '.png';
        copy($pathA, $imageA);
        copy($pathB, $imageB);

        $captured = [];
        $client = self::makeClient(self::stubClient([], $captured));

        $this->expectException(GislConfigError::class);
        $this->expectExceptionMessageMatches('/image merges do not support per-input/');

        // No explicit mediaKind — relies on extension sniffing.
        $client->merge([$imageA, $imageB])
            ->sequence([
                Merge::asset($imageA),
                Merge::clip($imageB, new ClipOptions(transition: 'fade')),
            ])
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));
    }

    public function test_media_kind_inference_from_first_asset_extension(): void
    {
        // No mediaKind set on MergeOptions; .mp3 extension → audio kind →
        // merge-level gapDuration kept on the wire.
        $pathA = self::writeTempFile('a');
        $pathB = self::writeTempFile('b');
        // Rename to .mp3 paths via temp directory.
        $audioA = sys_get_temp_dir() . '/gisl-merge-' . bin2hex(random_bytes(4)) . '.mp3';
        $audioB = sys_get_temp_dir() . '/gisl-merge-' . bin2hex(random_bytes(4)) . '.mp3';
        copy($pathA, $audioA);
        copy($pathB, $audioB);

        $captured = [];
        $http = self::stubClient([
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000a1', 'a.mp3', 'audio/mpeg', 1),
            self::uploadResponse('01936fb1-7bb3-7000-8000-0000000000b2', 'b.mp3', 'audio/mpeg', 1),
            self::workflowCreatedResponse('01936fb2-0000-7000-8000-000000000a0c'),
        ], $captured);
        $client = self::makeClient($http);

        $client->merge([$audioA, $audioB], new MergeOptions(gapDuration: 1.5))
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        $body = self::decodeJson($captured[2]);
        $opts = $this->assertMergeShapeAndReturnMergeJob($body, 2)['operations'][0]['options'];
        $this->assertSame(1.5, $opts['gap_duration'], 'audio inference keeps gap_duration on the wire');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * p0SuJEeK — the merge payload now emits one single-input `passthrough`
     * source job per UNIQUE asset (first-seen position order) FOLLOWED BY the
     * merge job LAST. So `$body['jobs'][0]` is no longer the merge job. This
     * helper returns the merge job (the last element, id 'merge') and asserts
     * the leading source jobs carry `operations: [{type: passthrough}]`,
     * `source.type === upload`, and that every merge input references a
     * source job via `{type: job_output, from: src_<i>}`.
     *
     * @param array<string, mixed> $body
     * @param int $expectedSourceJobs number of unique assets = source job count
     * @return array<string, mixed> the merge job
     */
    private function assertMergeShapeAndReturnMergeJob(array $body, int $expectedSourceJobs): array
    {
        /** @var list<array<string, mixed>> $jobs */
        $jobs = $body['jobs'];
        $this->assertCount($expectedSourceJobs + 1, $jobs, 'one src job per unique asset + the merge job');

        $srcIds = [];
        for ($i = 0; $i < $expectedSourceJobs; $i++) {
            /** @var array<string, mixed> $job */
            $job = $jobs[$i];
            $this->assertSame("src_{$i}", $job['id']);
            $this->assertSame('upload', $job['source']['type']);
            $this->assertIsString($job['source']['file_id']);
            $this->assertArrayNotHasKey('inputs', $job);
            $this->assertSame([['type' => 'passthrough']], $job['operations']);
            $srcIds[$job['id']] = true;
        }

        /** @var array<string, mixed> $mergeJob */
        $mergeJob = $jobs[$expectedSourceJobs];
        $this->assertSame('merge', $mergeJob['id']);
        $this->assertSame('merge', $mergeJob['operations'][0]['type']);

        /** @var list<array<string, mixed>> $inputs */
        $inputs = $mergeJob['inputs'];
        foreach ($inputs as $input) {
            $this->assertSame('job_output', $input['source']['type']);
            $this->assertArrayHasKey($input['source']['from'], $srcIds, 'merge input references a known src job');
            $this->assertArrayNotHasKey('file_id', $input['source'], 'merge inputs no longer carry upload file_id');
        }

        return $mergeJob;
    }

    private static function writeTempFile(string $bytes): string
    {
        $dir = sys_get_temp_dir() . '/gisl-merge-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        $path = $dir . '/fixture.bin';
        file_put_contents($path, $bytes);
        return $path;
    }

    private static function makeClient(ClientInterface $http): GislErgonomicClient
    {
        $factory = new HttpFactory();
        return new GislErgonomicClient(
            config: new GislClientConfig(
                baseUrl: 'https://api.test.example.com',
                apiKey: 'test-api-key',
                multipartConcurrency: 1,
            ),
            httpClient: $http,
            requestFactory: $factory,
            streamFactory: $factory,
        );
    }

    /**
     * @param list<ResponseInterface> $queue
     * @param-out list<RequestInterface> $captured
     */
    private static function stubClient(array $queue, array &$captured): ClientInterface
    {
        $captured = [];
        return new class ($queue, $captured) implements ClientInterface {
            /** @var list<ResponseInterface> */
            private array $queue;
            /** @var list<RequestInterface> */
            private array $captured;

            /**
             * @param list<ResponseInterface> $queue
             * @param list<RequestInterface>  $captured
             */
            public function __construct(array $queue, array &$captured)
            {
                $this->queue = $queue;
                $this->captured = &$captured;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;
                $next = array_shift($this->queue);
                if ($next === null) {
                    throw new \RuntimeException(
                        'Stub PSR-18 client: response queue exhausted on request #' . count($this->captured),
                    );
                }
                return $next;
            }
        };
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function jsonResponse(int $status, array $body): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    private static function uploadResponse(string $fileId, string $name, string $mime, int $size): ResponseInterface
    {
        return self::jsonResponse(200, [
            'success' => true,
            'data' => [
                'file_id' => $fileId,
                'original_name' => $name,
                'mime_type' => $mime,
                'size_bytes' => $size,
            ],
        ]);
    }

    private static function workflowCreatedResponse(string $workflowId): ResponseInterface
    {
        return self::jsonResponse(201, [
            'success' => true,
            'data' => [
                'workflow_id' => $workflowId,
                'status' => 'pending',
                'webhook_secret' => 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789',
                'created_at' => '2026-05-27T11:00:00Z',
                'jobs' => [],
                'delivery_plan' => [
                    'mode' => 'individual',
                    'selection_type' => 'terminal',
                    'outputs' => [],
                    'hidden_outputs' => [],
                ],
                'processing_plan' => ['jobs' => []],
                'warnings' => [],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeJson(RequestInterface $request): array
    {
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        return $body;
    }
}
