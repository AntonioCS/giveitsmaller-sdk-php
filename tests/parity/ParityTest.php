<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Parity;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cross-SDK parity test driver.
 *
 * Mirrors `packages/typescript/tests/parity/parity.test.ts`. One PHPUnit
 * test method runs once per fixture (dataProvider). The contract:
 *
 *   - mode=request_response or mode=sse:
 *       1. Install StubPsr18Client with the fixture's canned responses.
 *       2. Drive the SDK method via {@see Invoke}.
 *       3. Compare captured outbound requests vs `fixture.requests`.
 *       4. Compare return value vs `fixture.expected_return` (or, for sse,
 *          the collected event sequence vs `fixture.expected_return`).
 *       5. If `expects_error: true`, require a thrown exception and SKIP
 *          the return-value comparison; request parity still runs.
 *
 *   - mode=webhook:
 *       1. Independently compute HMAC-SHA256(secret, body).
 *       2. Assert it equals `webhook.expected_signature_hex` (parity check).
 *       3. Round-trip through Webhook::verify and assert it returns true.
 */
final class ParityTest extends TestCase
{
    /**
     * @return array<string, array{Fixture}>
     */
    public static function fixtureProvider(): array
    {
        $cases = [];
        foreach (FixtureLoader::loadAll() as $fixture) {
            $cases[$fixture->name] = [$fixture];
        }
        return $cases;
    }

    /**
     * Fixtures whose failure is a known PHP-SDK divergence from the
     * cross-language parity spec, NOT a bug in the runner. Tracked as
     * karen-style observations for follow-up cards rather than masked by
     * runner-side comparator hacks. Each entry carries the divergence
     * summary so a future SDK fix lands at the right place.
     *
     * @var array<string, string>
     */
    private const KNOWN_DIVERGENCES = [
        // The PHP SDK hardcodes `Content-Type: application/octet-stream` for
        // the multipart `file` part in `singleShotUpload` / `multipartUpload`
        // (`packages/php/src/GislClient.php:2864`, :2904). The TS reference
        // forwards the caller's Blob.type. Fixture pins `text/plain`.
        // See packages/php/tests/parity/KNOWN_DIVERGENCES.md (Trello RWWBYklu).
        'upload_small' =>
            'PHP SDK hardcodes Content-Type=application/octet-stream on the multipart file part; '
            . 'TS forwards Blob.type. Real divergence — file follow-up card to forward caller type.',

        // Generated `MultipartInitiateResponse::setRecommendedChunkSize`
        // rejects values below the 16 MiB minimum (raised 5 MiB -> 16 MiB by
        // CON-1 / contracts z4GDTUMx, ADR-0011). The fixtures pin small chunk
        // sizes (~2 MB) so the binary payload stays compact — a sub-contract
        // value BOTH SDKs reject (the TS runner now enforces the same
        // contract-range guard and skips the same 3 fixtures).
        // See packages/php/tests/parity/KNOWN_DIVERGENCES.md (Trello 09eNib6R).
        'upload_multipart' =>
            'Generated MultipartInitiateResponse setter rejects recommendedChunkSize<16MiB '
            . '(CON-1/ADR-0011 raised the floor 5MiB->16MiB); '
            . 'fixture pins 2MB to keep payload compact. TS does not validate. '
            . 'Follow-up: relax/remove generator-level wire-response constraints.',
        'upload_metadata_hint' =>
            'Same recommendedChunkSize<16MiB validation as upload_multipart.',
        'upload_boundary_multipart' =>
            'Same recommendedChunkSize<16MiB validation as upload_multipart.',

        // `AudioWatermarkDecodeRequest::__construct` defaults `method_hint`
        // to `'auto'` via setIfExists when the caller omits it, so the wire
        // body always carries `method_hint`. TS leaves the field undefined
        // and json-stringify drops it. Real divergence visible only on the
        // omit-methodHint fixtures (the happy path passes both).
        'error_403_audio_watermark_decode_tier' =>
            'PHP `AudioWatermarkDecodeRequest` defaults method_hint=auto when omitted; '
            . 'TS leaves it undefined and JSON drops the key. Follow-up: drop the default '
            . 'in the generated PHP model OR strip null/default fields in the SDK before send.',
        'error_422_audio_watermark_decode_planned' =>
            'Same method_hint default as error_403_audio_watermark_decode_tier.',
    ];

    #[DataProvider('fixtureProvider')]
    public function testFixture(Fixture $fixture): void
    {
        if (\array_key_exists($fixture->name, self::KNOWN_DIVERGENCES)) {
            $this->markTestSkipped(
                "[{$fixture->name}] " . self::KNOWN_DIVERGENCES[$fixture->name],
            );
        }

        if ($fixture->mode === Fixture::MODE_WEBHOOK) {
            $this->runWebhook($fixture);
            return;
        }
        if ($fixture->mode === Fixture::MODE_LOCAL_VALIDATION_ERROR) {
            $this->runLocalValidationError($fixture);
            return;
        }
        if ($fixture->mode === Fixture::MODE_LOWERING) {
            $this->runLowering($fixture);
            return;
        }
        if ($fixture->mode === Fixture::MODE_RUN) {
            $this->runRunMode($fixture);
            return;
        }
        if ($fixture->mode === Fixture::MODE_FILES) {
            $this->runFilesMode($fixture);
            return;
        }
        if ($fixture->submit !== null) {
            $this->runSubmit($fixture);
            return;
        }

        $stub = new StubPsr18Client($fixture->responses, $fixture->absolutePath);
        $result = Invoke::run($fixture, $stub);
        try {
            $this->assertOutcome($fixture, $stub, $result);
        } finally {
            $result->cleanup();
        }
    }

    /**
     * F4-A — mode=local_validation_error. The SDK is expected to throw
     * BEFORE any HTTP call. Stub installed with empty response queue so
     * a regression that DOES leak a request fails loudly.
     */
    private function runLocalValidationError(Fixture $fixture): void
    {
        $stub = new StubPsr18Client([], $fixture->absolutePath);
        $result = Invoke::run($fixture, $stub);
        try {
            $captured = $stub->captured();
            $this->assertCount(
                0,
                $captured,
                "[{$fixture->name}] mode=local_validation_error must not issue any HTTP calls",
            );
            $this->assertNotNull(
                $result->thrown,
                "[{$fixture->name}] mode=local_validation_error expected the SDK to throw, but no error was caught",
            );
            if ($fixture->localValidationError !== null && $result->thrown !== null) {
                $issues = Comparator::compareLocalValidationError(
                    $fixture->localValidationError,
                    $this->projectLocalValidationError($result->thrown),
                );
                $this->assertSame(
                    [],
                    $issues,
                    "[{$fixture->name}] localValidationError parity failure:\n  - " . \implode("\n  - ", $issues),
                );
            }
        } finally {
            $result->cleanup();
        }
    }

    /**
     * Project a caught Throwable into the comparable
     * `{category, code, conflictingFields, message}` shape T4b's
     * GislConfigError exposes. Unknown error types fall back to
     * category=unknown so the comparator can surface the mismatch
     * cleanly.
     *
     * @return array<string, mixed>
     */
    private function projectLocalValidationError(\Throwable $thrown): array
    {
        // GislConfigError + subclasses are the only validation-category
        // local errors today (T4b — packages/php/src/Errors/).
        if ($thrown instanceof \Gisl\Sdk\Errors\GislConfigError) {
            $out = [
                'category' => 'validation',
                'message' => $thrown->getMessage(),
            ];
            $reason = \method_exists($thrown, 'getReason') ? $thrown->getReason() : null;
            if ($reason !== null) {
                $out['code'] = $reason;
            }
            $fields = \method_exists($thrown, 'getConflictingFields') ? $thrown->getConflictingFields() : null;
            if ($fields !== null) {
                $out['conflictingFields'] = $fields;
            }
            return $out;
        }
        return [
            'category' => 'unknown',
            'message' => $thrown->getMessage(),
        ];
    }

    /**
     * FF2a — mode=lowering. Build the file-first Recipe from the fixture's
     * chain spec, lower it (network-free), and deep-compare the wire payload
     * to `expected_payload`. Tokens are a no-op here (lowering is fully
     * deterministic), so the standard value comparator applies.
     */
    private function runLowering(Fixture $fixture): void
    {
        $lowered = Invoke::lower($fixture);
        $issues = Comparator::compareReturn($fixture->expectedPayload, $lowered, 'expected_payload');
        $this->assertSame(
            [],
            $issues,
            "[{$fixture->name}] lowering parity failure:\n  - " . \implode("\n  - ", $issues),
        );
    }

    /**
     * FF2b (tywwynmN) — mode=run. Drive `client->file(...)->op()...->run()`
     * against the fixture's mocked upload/create/terminal/downloads responses
     * and deep-compare the hydrated RunResult DATA shape (RunResult::toArray)
     * to the top-level `expected_run_result`. The Downloader is NOT exercised —
     * the download URLs are canned strings.
     *
     * HARNESS NOTE: depends on {@see StubPsr18Client} serving the canned
     * responses in call order; the run-mode assertion lights up with the rest
     * of the harness build-out (F4-B / cEUWPgKW).
     */
    private function runRunMode(Fixture $fixture): void
    {
        $stub = new StubPsr18Client($fixture->responses, $fixture->absolutePath);
        $actual = Invoke::runRecipe($fixture, $stub);

        $issues = Comparator::compareReturn(
            $fixture->expectedRunResult,
            $actual,
            'expected_run_result',
        );
        $this->assertSame(
            [],
            $issues,
            "[{$fixture->name}] run parity failure:\n  - " . \implode("\n  - ", $issues),
        );
    }

    /**
     * FF3a (u0hBt6fl) — mode=files homogeneous fan-out. Two variants share the
     * one `files` block, discriminated by which assertion key the fixture
     * declares:
     *
     *  - lowering variant (`expected_payload`, zero responses): build the
     *    FilesRecipe, lower it against `files.resolvedFileIds`, and deep-compare
     *    the multi-job wire payload (network-free).
     *  - run variant (`expected_run_result`): drive `->run()` against the canned
     *    upload/create/terminal/downloads responses and deep-compare the
     *    PARTITIONED RunResult (succeeded/failed keyed by input index).
     *
     * HARNESS NOTE: the run variant depends on {@see StubPsr18Client} serving
     * the canned responses in call order (F4-B / cEUWPgKW), same as mode=run.
     */
    private function runFilesMode(Fixture $fixture): void
    {
        // uUnCtVAr (FF3a-submit) — a `files.webhook` selects the SUBMIT variant:
        // drive FilesRecipe->submit() against the canned create response, assert
        // the captured create request (multi-job callback_url) AND the returned
        // Handle (expected_return). Mirrors the TS files-submit arm.
        $filesSpec = $fixture->files;
        if ($filesSpec !== null && isset($filesSpec['webhook'])) {
            $stub = new StubPsr18Client($fixture->responses, $fixture->absolutePath);
            $actual = Invoke::submitFiles($fixture, $stub);

            $requestIssues = Comparator::compareRequests($fixture, $stub);
            $this->assertSame(
                [],
                $requestIssues,
                "[{$fixture->name}] files submit request parity failure:\n  - " . \implode("\n  - ", $requestIssues),
            );

            if ($fixture->hasExpectedReturn) {
                $returnIssues = Comparator::compareReturn(
                    $fixture->expectedReturn,
                    $actual,
                    'expected_return',
                );
                $this->assertSame(
                    [],
                    $returnIssues,
                    "[{$fixture->name}] files submit return parity failure:\n  - " . \implode("\n  - ", $returnIssues),
                );
            }
            return;
        }

        // Discriminate by which assertion the fixture pinned. The loader sets
        // hasExpectedRunResult when expected_run_result is present; otherwise
        // the lowering variant's expected_payload drives the assertion.
        if ($fixture->hasExpectedRunResult) {
            $stub = new StubPsr18Client($fixture->responses, $fixture->absolutePath);
            $actual = Invoke::runFiles($fixture, $stub);

            $issues = Comparator::compareReturn(
                $fixture->expectedRunResult,
                $actual,
                'expected_run_result',
            );
            $this->assertSame(
                [],
                $issues,
                "[{$fixture->name}] files run parity failure:\n  - " . \implode("\n  - ", $issues),
            );
            return;
        }

        $lowered = Invoke::lowerFiles($fixture);
        $issues = Comparator::compareReturn($fixture->expectedPayload, $lowered, 'expected_payload');
        $this->assertSame(
            [],
            $issues,
            "[{$fixture->name}] files lowering parity failure:\n  - " . \implode("\n  - ", $issues),
        );
    }

    /**
     * FF5b (u8M49LU2) — submit dispatch. Drive
     * `client->file(...)->op()...->submit(webhook?)` against the fixture's
     * mocked create response through the STANDARD request_response flow, then
     * assert BOTH the captured create request (`callback_url`, via
     * {@see Comparator::compareRequests}) AND the returned Handle DATA shape
     * (`{workflowId, webhookSecret}` — the recipe key is NOT serialised) against
     * `expected_return`. Mirrors the TS submit arm in `parity.test.ts`.
     */
    private function runSubmit(Fixture $fixture): void
    {
        $stub = new StubPsr18Client($fixture->responses, $fixture->absolutePath);
        $actual = Invoke::submitRecipe($fixture, $stub);

        $requestIssues = Comparator::compareRequests($fixture, $stub);
        $this->assertSame(
            [],
            $requestIssues,
            "[{$fixture->name}] request parity failure:\n  - " . \implode("\n  - ", $requestIssues),
        );

        if ($fixture->hasExpectedReturn) {
            $returnIssues = Comparator::compareReturn(
                $fixture->expectedReturn,
                $actual,
                'expected_return',
            );
            $this->assertSame(
                [],
                $returnIssues,
                "[{$fixture->name}] return parity failure:\n  - " . \implode("\n  - ", $returnIssues),
            );
        }
    }

    private function runWebhook(Fixture $fixture): void
    {
        $stub = new StubPsr18Client([], null);
        $result = Invoke::run($fixture, $stub);
        if ($result->thrown !== null) {
            throw $result->thrown;
        }
        $this->assertSame(
            true,
            $result->returnValue,
            "[{$fixture->name}] verifyWebhook returned non-true",
        );
    }

    private function assertOutcome(
        Fixture $fixture,
        StubPsr18Client $stub,
        InvokeResult $result,
    ): void {
        // Surface unexpected exceptions BEFORE the request comparator so the
        // root-cause stack trace isn't masked by a noisy diff.
        if ($result->thrown !== null && !$fixture->expectsError) {
            throw $result->thrown;
        }
        if ($result->thrown === null && $fixture->expectsError) {
            $this->fail("[{$fixture->name}] expects_error=true but the SDK did not throw");
        }

        $requestIssues = Comparator::compareRequests($fixture, $stub);
        $this->assertSame(
            [],
            $requestIssues,
            "[{$fixture->name}] request parity failure:\n  - " . \implode("\n  - ", $requestIssues),
        );

        if ($fixture->expectsError) {
            // Error fixtures do not pin a return shape — the typed-error
            // dispatch is asserted by the unit suite, parity only pins the
            // outbound request shape on this path.
            return;
        }

        // Return-value comparison is conditional, NOT an early return — a
        // fixture may pin only the v2 assertion blocks below (resolvedOptions
        // / omittedFromWire) without an expected_return. Mirrors the TS
        // reference (parity.test.ts), where absent expected_return skips the
        // return compare but still runs the v2 checks.
        if ($fixture->hasExpectedReturn) {
            $observed = $fixture->mode === Fixture::MODE_SSE
                ? $result->returnValue
                : ReturnSerialiser::serialise($result->returnValue);

            $returnIssues = Comparator::compareReturn(
                $fixture->expectedReturn,
                $observed,
                'expected_return',
            );
            $this->assertSame(
                [],
                $returnIssues,
                "[{$fixture->name}] return parity failure:\n  - " . \implode("\n  - ", $returnIssues),
            );
        }

        // F4-A — v2 assertion blocks. Additive on top of the wire+return
        // comparisons, and run regardless of hasExpectedReturn. Skipped for
        // v1 fixtures (loader rejects these blocks on v1).
        if ($fixture->resolvedOptions !== null) {
            $resolvedIssues = Comparator::compareResolvedOptions(
                $fixture->resolvedOptions,
                $this->projectResolvedOptions($result->returnValue),
            );
            $this->assertSame(
                [],
                $resolvedIssues,
                "[{$fixture->name}] resolvedOptions parity failure:\n  - " . \implode("\n  - ", $resolvedIssues),
            );
        }
        if ($fixture->omittedFromWire !== null && \count($fixture->omittedFromWire) > 0) {
            $omittedIssues = Comparator::compareOmittedFromWire(
                $fixture->omittedFromWire,
                $stub->captured(),
            );
            $this->assertSame(
                [],
                $omittedIssues,
                "[{$fixture->name}] omittedFromWire parity failure:\n  - " . \implode("\n  - ", $omittedIssues),
            );
        }
    }

    /**
     * Project the PHP ergonomic `Result` shape into the comparator's
     * expected `{preset, applied, sources, presetVersion, presetConfigHash}`
     * mapping. Returns null when the return value carries no
     * resolvedOptions (non-ergonomic methods, error paths).
     *
     * @return array<string, mixed>|null
     */
    private function projectResolvedOptions(mixed $returnValue): ?array
    {
        if (!\is_object($returnValue)) {
            return null;
        }
        if (!\property_exists($returnValue, 'resolvedOptions')) {
            return null;
        }
        $resolved = $returnValue->resolvedOptions;
        if ($resolved === null) {
            return null;
        }
        if (\is_array($resolved)) {
            return $resolved;
        }
        if (!\is_object($resolved)) {
            return null;
        }
        // PHP ResolvedOptions DTO → array via get_object_vars; nested
        // sources object similarly projected so the comparator sees a
        // pure mapping shape.
        $arr = \get_object_vars($resolved);
        if (isset($arr['sources']) && \is_object($arr['sources'])) {
            $arr['sources'] = \get_object_vars($arr['sources']);
        }
        return $arr;
    }
}
