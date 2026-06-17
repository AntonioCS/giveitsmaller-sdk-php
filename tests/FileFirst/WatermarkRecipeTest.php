<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\FileFirst;

use Gisl\Generated\OpenApi\Model\JobResponse;
use Gisl\Generated\OpenApi\Model\WorkflowStatusResponse;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\FileFirst\FileInput;
use Gisl\Sdk\FileFirst\Recipe;
use Gisl\Sdk\FileFirst\RunResult;
use Gisl\Sdk\FileFirst\WatermarkedRecipe;
use Gisl\Sdk\FileFirst\WatermarkGate;
use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * FF4a (Z7zTr789) — fluent `file($base)->watermark($overlay, $opts)` multi-input
 * verb. Mirrors the TS `file-first-watermark.test.ts`: media routing, the local
 * planned-op gate (throws pre-upload), the multi-input DAG lowering, base/overlay/
 * post steps, overlay validation, immutability, and the `isWatermarkStatus`
 * projection.
 */
#[CoversClass(WatermarkedRecipe::class)]
#[CoversClass(WatermarkGate::class)]
final class WatermarkRecipeTest extends TestCase
{
    private function recipe(string $path): Recipe
    {
        return new Recipe(FileInput::path($path));
    }

    private function overlay(string $path = 'logo.png'): Recipe
    {
        return new Recipe(FileInput::path($path));
    }

    /**
     * @param array<string, mixed> $wire
     * @return array<string, mixed>
     */
    private function watermarkJob(array $wire): array
    {
        /** @var list<array<string, mixed>> $jobs */
        $jobs = $wire['jobs'];
        foreach ($jobs as $job) {
            if (($job['id'] ?? null) === 'watermark') {
                return $job;
            }
        }
        self::fail('no watermark job in payload');
    }

    // ── routing ───────────────────────────────────────────────────────────

    public function test_image_base_routes_to_image_watermark(): void
    {
        $wire = $this->recipe('photo.jpg')->watermark($this->overlay(), ['anchor' => 'bottom_right', 'opacity' => 0.65])
            ->toWorkflowPayload(['base', 'ovl'])->toWire();
        self::assertSame('image_watermark', $this->watermarkJob($wire)['operations'][0]['type']);
    }

    public function test_video_base_routes_to_video_watermark(): void
    {
        $wire = $this->recipe('clip.mp4')->watermark($this->overlay(), ['anchor' => 'top_right'])
            ->toWorkflowPayload(['base', 'ovl'])->toWire();
        self::assertSame('video_watermark', $this->watermarkJob($wire)['operations'][0]['type']);
    }

    public function test_transformed_base_routes_by_output_media_thumbnail_to_image(): void
    {
        $wire = $this->recipe('clip.mp4')->thumbnail(['width' => 640])->watermark($this->overlay())
            ->toWorkflowPayload(['base', 'ovl'])->toWire();
        self::assertSame('image_watermark', $this->watermarkJob($wire)['operations'][0]['type']);
    }

    public function test_base_converted_to_video_routes_to_video_watermark(): void
    {
        $wire = $this->recipe('photo.jpg')->convert('mp4')->watermark($this->overlay())
            ->toWorkflowPayload(['base', 'ovl'])->toWire();
        self::assertSame('video_watermark', $this->watermarkJob($wire)['operations'][0]['type']);
    }

    public function test_named_typeless_resource_base_routes_by_filename(): void
    {
        // A resource with a filename hint but no contentType must route like a path
        // (mime falls back to the filename extension) — parity with the TS blob branch.
        $res = \fopen('php://temp', 'r+b');
        \assert(\is_resource($res));
        $wire = (new Recipe(FileInput::resource($res, 'photo.png')))->watermark($this->overlay())
            ->toWorkflowPayload(['base', 'ovl'])->toWire();
        self::assertSame('image_watermark', $this->watermarkJob($wire)['operations'][0]['type']);
        \fclose($res);
    }

    public function test_generic_content_type_resource_base_falls_back_to_filename(): void
    {
        // A non-media-bearing contentType (application/octet-stream) must NOT be the
        // routing mime — fall back to the filename extension (parity with TS blob).
        $res = \fopen('php://temp', 'r+b');
        \assert(\is_resource($res));
        $wire = (new Recipe(FileInput::resource($res, 'photo.png', 'application/octet-stream')))
            ->watermark($this->overlay())
            ->toWorkflowPayload(['base', 'ovl'])->toWire();
        self::assertSame('image_watermark', $this->watermarkJob($wire)['operations'][0]['type']);
        \fclose($res);
    }

    // ── lowering shape ──────────────────────────────────────────────────────

    public function test_lowers_to_src_passthrough_jobs_plus_role_tagged_watermark_job(): void
    {
        $wire = $this->recipe('photo.jpg')->watermark($this->overlay(), ['anchor' => 'center', 'overlay_width' => '30%'])
            ->toWorkflowPayload(['base_id', 'ovl_id'])->toWire();

        /** @var list<array<string, mixed>> $jobs */
        $jobs = $wire['jobs'];
        self::assertCount(3, $jobs);
        self::assertSame(
            ['id' => 'src_0', 'source' => ['type' => 'upload', 'file_id' => 'base_id'], 'operations' => [['type' => 'passthrough']]],
            $jobs[0],
        );
        self::assertSame(
            ['id' => 'src_1', 'source' => ['type' => 'upload', 'file_id' => 'ovl_id'], 'operations' => [['type' => 'passthrough']]],
            $jobs[1],
        );
        $wm = $this->watermarkJob($wire);
        self::assertSame([
            ['source' => ['type' => 'job_output', 'from' => 'src_0'], 'role' => 'base'],
            ['source' => ['type' => 'job_output', 'from' => 'src_1'], 'role' => 'overlay'],
        ], $wm['inputs']);
        self::assertSame(
            ['type' => 'image_watermark', 'options' => ['anchor' => 'center', 'overlay_width' => '30%']],
            $wm['operations'][0],
        );
    }

    public function test_omits_options_key_when_no_watermark_options(): void
    {
        $wire = $this->recipe('photo.jpg')->watermark($this->overlay())
            ->toWorkflowPayload(['b', 'o'])->toWire();
        self::assertSame(['type' => 'image_watermark'], $this->watermarkJob($wire)['operations'][0]);
    }

    public function test_lowers_base_preceding_steps_into_src_0_and_overlay_steps_into_src_1(): void
    {
        $wire = $this->recipe('hero.jpg')->thumbnail(['width' => 1200])
            ->watermark($this->overlay('logo.png')->convert('png'))
            ->toWorkflowPayload(['b', 'o'])->toWire();
        /** @var list<array<string, mixed>> $jobs */
        $jobs = $wire['jobs'];
        self::assertSame([['type' => 'thumbnail', 'options' => ['width' => 1200]]], $jobs[0]['operations']);
        self::assertSame([['type' => 'convert', 'options' => ['output_format' => 'png']]], $jobs[1]['operations']);
    }

    public function test_appends_post_watermark_steps_after_the_watermark_op(): void
    {
        $wire = $this->recipe('photo.jpg')->watermark($this->overlay())->convert('webp')
            ->toWorkflowPayload(['b', 'o'])->toWire();
        $types = \array_map(static fn (array $op): string => $op['type'], $this->watermarkJob($wire)['operations']);
        self::assertSame(['image_watermark', 'convert'], $types);
    }

    public function test_post_watermark_compress_resolves_against_output_media(): void
    {
        $wire = $this->recipe('photo.jpg')->watermark($this->overlay())->compress(OptimizeFor::Size)
            ->toWorkflowPayload(['b', 'o'])->toWire();
        $ops = $this->watermarkJob($wire)['operations'];
        $compress = null;
        foreach ($ops as $op) {
            if ($op['type'] === 'compress') {
                $compress = $op;
            }
        }
        self::assertNotNull($compress);
        // image presets never carry the video-only crf key.
        self::assertArrayNotHasKey('crf', $compress['options'] ?? []);
    }

    public function test_post_watermark_compress_resolves_against_video_output_media(): void
    {
        // video base -> video_watermark -> synthetic post media is video (mp4 arm),
        // so compress(Size) resolves the VIDEO Size cell (carries crf).
        $wire = $this->recipe('clip.mp4')->watermark($this->overlay())->compress(OptimizeFor::Size)
            ->toWorkflowPayload(['b', 'o'])->toWire();
        $ops = $this->watermarkJob($wire)['operations'];
        self::assertSame('video_watermark', $ops[0]['type']);
        $compress = null;
        foreach ($ops as $op) {
            if ($op['type'] === 'compress') {
                $compress = $op;
            }
        }
        self::assertNotNull($compress);
        self::assertArrayHasKey('crf', $compress['options'] ?? []);
    }

    public function test_wires_callback_url(): void
    {
        $wire = $this->recipe('photo.jpg')->watermark($this->overlay())
            ->toWorkflowPayload(['b', 'o'], 'https://hook.example.com')->toWire();
        self::assertSame('https://hook.example.com', $wire['callback_url'] ?? null);
    }

    // ── planned-op gate (throws pre-upload) ─────────────────────────────────

    public function test_throws_for_audio_base(): void
    {
        $this->expectException(GislConfigError::class);
        $this->recipe('song.mp3')->watermark($this->overlay());
    }

    public function test_throws_for_document_base(): void
    {
        $this->expectException(GislConfigError::class);
        $this->recipe('report.pdf')->watermark($this->overlay());
    }

    public function test_throws_for_gif_base_planned(): void
    {
        $this->expectExceptionMessageMatches('/not yet available|planned/');
        $this->recipe('loop.gif')->watermark($this->overlay());
    }

    public function test_throws_for_unsupported_image_subtype_avif(): void
    {
        $this->expectExceptionMessageMatches('/does not support/');
        $this->recipe('pic.avif')->watermark($this->overlay());
    }

    public function test_throws_for_unsupported_video_subtype_mov(): void
    {
        $this->expectExceptionMessageMatches('/does not support/');
        $this->recipe('clip.mov')->watermark($this->overlay());
    }

    public function test_defers_undetectable_base_then_throws_at_lowering(): void
    {
        // A bare upload id carries no media — the eager gate is deferred (no throw
        // at the verb); the gate fires when lowering resolves the wire op.
        $wr = (new Recipe(FileInput::uploadId('u_base')))->watermark($this->overlay());
        self::assertInstanceOf(WatermarkedRecipe::class, $wr);
        $this->expectExceptionMessageMatches('/detectable base media/');
        $wr->toWorkflowPayload(['u_base', 'o']);
    }

    // ── overlay validation ──────────────────────────────────────────────────

    public function test_throws_for_video_overlay(): void
    {
        $this->expectExceptionMessageMatches('/overlay must be an image/');
        $this->recipe('photo.jpg')->watermark($this->overlay('clip.mp4'));
    }

    public function test_throws_for_audio_overlay(): void
    {
        $this->expectExceptionMessageMatches('/overlay must be an image/');
        $this->recipe('photo.jpg')->watermark($this->overlay('track.mp3'));
    }

    public function test_allows_transformed_overlay_whose_output_is_image(): void
    {
        $wr = $this->recipe('photo.jpg')->watermark($this->overlay('clip.mp4')->thumbnail(['width' => 64]));
        self::assertInstanceOf(WatermarkedRecipe::class, $wr);
    }

    public function test_allows_undetectable_overlay_upload_id(): void
    {
        $wire = $this->recipe('photo.jpg')->watermark(new Recipe(FileInput::uploadId('u_ovl')))
            ->toWorkflowPayload(['base', 'u_ovl'])->toWire();
        /** @var list<array<string, mixed>> $jobs */
        $jobs = $wire['jobs'];
        self::assertSame([['type' => 'passthrough']], $jobs[1]['operations']);
    }

    // ── immutability ────────────────────────────────────────────────────────

    public function test_post_verbs_return_a_new_instance(): void
    {
        $base = $this->recipe('photo.jpg')->watermark($this->overlay());
        $next = $base->compress(OptimizeFor::Size);
        self::assertNotSame($base, $next);
        self::assertSame(0, $base->stepCount());
        self::assertSame(1, $next->stepCount());
    }

    // ── isWatermarkStatus ───────────────────────────────────────────────────

    /**
     * @param list<string> $refs
     */
    private function statusOf(array $refs): WorkflowStatusResponse
    {
        return new WorkflowStatusResponse([
            'jobs' => \array_map(static fn (string $ref): JobResponse => new JobResponse(['ref' => $ref]), $refs),
        ]);
    }

    public function test_is_watermark_status_true_for_src_plus_watermark(): void
    {
        self::assertTrue(RunResult::isWatermarkStatus($this->statusOf(['src_0', 'src_1', 'watermark'])));
    }

    public function test_is_watermark_status_false_without_watermark_job(): void
    {
        self::assertFalse(RunResult::isWatermarkStatus($this->statusOf(['src_0', 'src_1'])));
    }

    public function test_is_watermark_status_false_for_plain_chain(): void
    {
        self::assertFalse(RunResult::isWatermarkStatus($this->statusOf(['op'])));
    }

    public function test_is_watermark_status_false_for_empty_job_list(): void
    {
        self::assertFalse(RunResult::isWatermarkStatus($this->statusOf([])));
    }
}
