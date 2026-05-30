<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\FileFirst;

use Gisl\Sdk\Ergonomic\PresetResolver;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\FileFirst\FileInput;
use Gisl\Sdk\FileFirst\Recipe;
use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\GislErgonomicClient;
use Gisl\Sdk\Preset\ImageCompressPresetOptions;
use Gisl\Sdk\PresetDefaults;
use Gisl\Sdk\Tests\Unit\Ergonomic\GislErgonomicClientFactoryTestHelper;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * FF2a — the file-first {@see Recipe} builder: immutability (clone-on-write),
 * single-input op mapping, sequential-chain lowering to ONE job, and compress
 * preset delegation. Network-free: only the pure `toWorkflowPayload()` lowering
 * seam is exercised (no `run()` until FF2b).
 */
final class RecipeTest extends TestCase
{
    private const FILE_ID = 'file_0001';

    private function recipe(string $path, ?string $key = null): Recipe
    {
        return new Recipe(FileInput::path($path), $key);
    }

    /**
     * A no-I/O ergonomic client carrying client-scope preset defaults — the
     * HTTP transport throws if any request is issued (file()/lowering never
     * touch the wire).
     */
    private function clientWithPresetDefaults(PresetDefaults $defaults): GislErgonomicClient
    {
        $factory = new HttpFactory();
        $http = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new \LogicException('RecipeTest: lowering must not perform I/O.');
            }
        };

        return new GislErgonomicClient(
            config: new GislClientConfig(baseUrl: 'https://api.test', apiKey: 'sk_test'),
            httpClient: $http,
            requestFactory: $factory,
            streamFactory: $factory,
            presetDefaults: $defaults,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function loweredJob(Recipe $recipe): array
    {
        $wire = $recipe->toWorkflowPayload(self::FILE_ID)->toWire();
        self::assertIsArray($wire['jobs']);
        self::assertCount(1, $wire['jobs'], 'a single-input chain lowers to exactly one job');
        $job = $wire['jobs'][0];
        self::assertIsArray($job);

        return $job;
    }

    // --- Immutability (the headline AC) -------------------------------------

    #[Test]
    public function chaining_an_op_does_not_mutate_the_original_recipe(): void
    {
        $base = $this->recipe('photo.jpg');
        $compressed = $base->compress(OptimizeFor::Balanced);

        self::assertSame(0, $base->stepCount(), 'the base recipe is untouched');
        self::assertSame(1, $compressed->stepCount());
        self::assertNotSame($base, $compressed, 'each op returns a fresh Recipe');
    }

    #[Test]
    public function two_branches_off_one_base_are_independent(): void
    {
        $base = $this->recipe('clip.mov');
        $branchA = $base->convert('mp4')->compress(OptimizeFor::Size);
        $branchB = $base->thumbnail(width: 320);

        self::assertSame(0, $base->stepCount());
        self::assertSame(2, $branchA->stepCount());
        self::assertSame(1, $branchB->stepCount());

        // Branch A's operations must not leak into branch B (aliasing trap).
        $opsA = $this->operations($branchA);
        $opsB = $this->operations($branchB);
        self::assertSame(['convert', 'compress'], array_column($opsA, 'type'));
        self::assertSame(['thumbnail'], array_column($opsB, 'type'));

        // Re-LOWER the base AFTER both branches are built — its operations[]
        // must still be empty. Asserting on the lowered wire (not just
        // stepCount, which reads the same field) catches a shared-array
        // mutation that corrupts contents without changing the count.
        self::assertSame([], $this->operations($base), 'the base recipe still lowers to zero operations');
    }

    // --- file() entry point -------------------------------------------------

    #[Test]
    public function client_file_returns_a_recipe_carrying_the_key(): void
    {
        $client = GislErgonomicClientFactoryTestHelper::client();
        $recipe = $client->file('photo.jpg', key: 'hero');

        self::assertInstanceOf(Recipe::class, $recipe);
        self::assertSame('hero', $recipe->key());
        self::assertSame(0, $recipe->stepCount());
    }

    #[Test]
    public function client_file_accepts_a_preuploaded_file_input(): void
    {
        $client = GislErgonomicClientFactoryTestHelper::client();
        $recipe = $client->file(FileInput::uploadId('uploaded-123'))->convert('webp');

        $job = $this->loweredJob($recipe);
        self::assertSame(['type' => 'upload', 'file_id' => self::FILE_ID], $job['source']);
    }

    #[Test]
    public function an_op_with_empty_options_omits_the_options_key(): void
    {
        // A compress on an upload-id input has no inferable media, so preset
        // resolution yields empty options — which must omit the `options` wire
        // key (not emit `[]`), matching the TS `undefined` → absent behaviour.
        $ops = $this->operations($this->recipe('photo')->compress());
        self::assertSame([['type' => 'compress']], $ops);
        self::assertArrayNotHasKey('options', $ops[0]);
    }

    // --- Single-op lowering -------------------------------------------------

    #[Test]
    public function convert_lowers_to_a_format_option(): void
    {
        $ops = $this->operations($this->recipe('clip.mov')->convert('mp4'));
        self::assertSame([['type' => 'convert', 'options' => ['format' => 'mp4']]], $ops);
    }

    #[Test]
    public function text_watermark_lowers_to_the_text_watermark_op(): void
    {
        $ops = $this->operations($this->recipe('photo.jpg')->textWatermark('PROOF'));
        self::assertSame([['type' => 'text_watermark', 'options' => ['text' => 'PROOF']]], $ops);
    }

    #[Test]
    public function thumbnail_carries_both_dimensions(): void
    {
        $ops = $this->operations($this->recipe('photo.jpg')->thumbnail(width: 320, height: 240));
        self::assertSame([['type' => 'thumbnail', 'options' => ['width' => 320, 'height' => 240]]], $ops);
    }

    #[Test]
    public function thumbnail_omits_an_unset_dimension(): void
    {
        $ops = $this->operations($this->recipe('photo.jpg')->thumbnail(width: 320));
        self::assertSame([['type' => 'thumbnail', 'options' => ['width' => 320]]], $ops);
        self::assertArrayNotHasKey('height', $ops[0]['options'], 'an omitted dimension is not sent as null');
    }

    #[Test]
    public function thumbnail_carries_height_only(): void
    {
        $ops = $this->operations($this->recipe('photo.jpg')->thumbnail(height: 240));
        self::assertSame([['type' => 'thumbnail', 'options' => ['height' => 240]]], $ops);
        self::assertArrayNotHasKey('width', $ops[0]['options']);
    }

    // --- Job shape ----------------------------------------------------------

    #[Test]
    public function lowering_emits_one_job_with_upload_source_and_no_id(): void
    {
        $job = $this->loweredJob($this->recipe('photo.jpg')->convert('webp'));

        self::assertSame(['type' => 'upload', 'file_id' => self::FILE_ID], $job['source']);
        self::assertArrayNotHasKey('id', $job, 'a single referenced-by-nothing job omits id (server auto-assigns)');
        self::assertSame(['source', 'operations'], array_keys($job), 'wire key order: source before operations');
    }

    #[Test]
    public function a_chain_preserves_operation_order_in_one_job(): void
    {
        $ops = $this->operations($this->recipe('clip.mov')->convert('mp4')->thumbnail(width: 100));
        self::assertSame(['convert', 'thumbnail'], array_column($ops, 'type'));
    }

    // --- compress preset delegation -----------------------------------------

    #[Test]
    public function compress_delegates_to_the_shared_preset_resolver(): void
    {
        // The Recipe must lower compress to EXACTLY what the operation-first
        // resolver produces for the same media + optimize — proving it reuses
        // the resolver rather than re-implementing preset logic.
        $expected = PresetResolver::resolveCompress(
            media: 'image',
            presetDefaults: null,
            scopedDefaults: null,
            presetOverrides: null,
            optimize: OptimizeFor::Balanced,
            explicitOptions: [],
        )['wireOptions'];

        $ops = $this->operations($this->recipe('photo.jpg')->compress(OptimizeFor::Balanced));
        self::assertSame('compress', $ops[0]['type']);
        self::assertSame($expected, $ops[0]['options']);
        self::assertNotEmpty($ops[0]['options'], 'a known-media compress resolves to concrete wire fields');
    }

    #[Test]
    public function compress_accepts_the_optimize_string_value(): void
    {
        $viaEnum = $this->operations($this->recipe('photo.jpg')->compress(OptimizeFor::Size));
        $viaString = $this->operations($this->recipe('photo.jpg')->compress('Size'));
        self::assertSame($viaEnum, $viaString);
    }

    #[Test]
    public function compress_rejects_an_unknown_optimize_string(): void
    {
        $this->expectException(GislConfigError::class);
        $this->recipe('photo.jpg')->compress('Smallest');
    }

    #[Test]
    public function compress_with_optimize_on_unknown_media_fails_fast(): void
    {
        // 'photo' has no extension → media cannot be inferred. An explicit
        // optimize must NOT be silently dropped — lowering throws.
        $this->expectException(GislConfigError::class);
        $this->recipe('photo')->compress(OptimizeFor::Size)->toWorkflowPayload(self::FILE_ID);
    }

    #[Test]
    public function compress_uses_client_preset_defaults_via_file(): void
    {
        // `client->file()` must forward the client's preset defaults into the
        // Recipe so a file-first compress resolves with them — the constructor
        // arm the other tests (which use the bare helper) never exercise.
        $defaults = PresetDefaults::create()->imageCompress(
            OptimizeFor::Size,
            new ImageCompressPresetOptions(quality: 50),
        );
        $client = $this->clientWithPresetDefaults($defaults);

        $expected = PresetResolver::resolveCompress(
            media: 'image',
            presetDefaults: $defaults,
            scopedDefaults: null,
            presetOverrides: null,
            optimize: OptimizeFor::Size,
            explicitOptions: [],
        )['wireOptions'];

        $ops = $this->operations($client->file('photo.jpg')->compress(OptimizeFor::Size));
        self::assertSame('compress', $ops[0]['type']);
        self::assertSame($expected, $ops[0]['options']);
        self::assertSame(50, $ops[0]['options']['quality'] ?? null, 'the client default quality (50) won, not the shipped 65');
    }

    #[Test]
    public function lowering_serialises_to_a_byte_stable_json_string(): void
    {
        // Cross-language byte parity: this MUST equal the literal string the TS
        // test pins (file-first-recipe.test.ts) — same FILE_ID, same chain.
        $json = \json_encode($this->recipe('clip.mov')->convert('mp4')->toWorkflowPayload(self::FILE_ID)->toWire());
        self::assertSame(
            '{"jobs":[{"source":{"type":"upload","file_id":"file_0001"},"operations":[{"type":"convert","options":{"format":"mp4"}}]}]}',
            $json,
        );
    }

    #[Test]
    public function example_02_chain_lowers_convert_then_resolved_compress(): void
    {
        // examples/php/02-chain.php: clip.mov → convert(mp4) → compress(Size).
        $expectedCompress = PresetResolver::resolveCompress(
            media: 'video',
            presetDefaults: null,
            scopedDefaults: null,
            presetOverrides: null,
            optimize: OptimizeFor::Size,
            explicitOptions: [],
        )['wireOptions'];

        $ops = $this->operations(
            $this->recipe('clip.mov')->convert('mp4')->compress(OptimizeFor::Size),
        );

        self::assertSame(['convert', 'compress'], array_column($ops, 'type'));
        self::assertSame(['format' => 'mp4'], $ops[0]['options']);
        self::assertSame($expectedCompress, $ops[1]['options']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function operations(Recipe $recipe): array
    {
        $job = $this->loweredJob($recipe);
        self::assertIsArray($job['operations']);

        /** @var list<array<string, mixed>> $operations */
        $operations = $job['operations'];

        return $operations;
    }
}
