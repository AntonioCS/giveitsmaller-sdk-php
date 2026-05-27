<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Errors\GislError;
use Gisl\Sdk\Errors\NotYetImplementedDispatch;
use Gisl\Sdk\Tests\Parity\Fixture;
use Gisl\Sdk\Tests\Parity\Invoke;
use Gisl\Sdk\Tests\Parity\StubPsr18Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the PHP P0 parity-adapter ergonomic-dispatch seam (Bljva8nj).
 *
 * The seam itself lives in {@see Invoke::run()} → `dispatch()` (parity dir,
 * not src/). The error class under test ({@see NotYetImplementedDispatch})
 * is in src/Errors/ so any future LocalError emitter that filters on
 * `instanceof GislError` picks it up — covered by the hierarchy assertion
 * in {@see ParityErgonomicSeamTest::test_extends_gisl_error_hierarchy()}.
 *
 * Fixtures are SYNTHESIZED here directly rather than loaded from YAML —
 * {@see \Gisl\Sdk\Tests\Parity\FixtureLoader} deliberately rejects
 * ergonomic verbs on its allowlist (parity with TS) until parity-infra v2
 * / F4 lands fixture-side ergonomic admittance. The seam itself is loader-
 * independent so synthesizing covers the P0 contract end-to-end through
 * `Invoke::run()`.
 */
#[CoversClass(NotYetImplementedDispatch::class)]
final class ParityErgonomicSeamTest extends TestCase
{
    /**
     * Every ergonomic verb listed in `docs/plans/sdk-cross-language-foundation.md`
     * §4.10 short-circuits the dispatch with a structured error. If a new
     * verb lands in `Invoke::ERGONOMIC_METHODS`, add a row here so the
     * surface is exhaustively asserted.
     *
     * @return array<string, array{string}>
     */
    public static function ergonomicMethodProvider(): array
    {
        return [
            'compress' => ['compress'],
            'thumbnail' => ['thumbnail'],
            'convert' => ['convert'],
            'merge' => ['merge'],
            'watermark' => ['watermark'],
            'archive' => ['archive'],
            'mapEach' => ['mapEach'],
            'bundle' => ['bundle'],
        ];
    }

    #[DataProvider('ergonomicMethodProvider')]
    public function test_ergonomic_method_short_circuits_with_structured_error(string $method): void
    {
        $fixture = self::synthesizeFixture("ergonomic_seam_{$method}", $method);
        $stub = new StubPsr18Client([], null);

        $result = Invoke::run($fixture, $stub);

        $this->assertInstanceOf(
            NotYetImplementedDispatch::class,
            $result->thrown,
            "Ergonomic verb \"{$method}\" must short-circuit with NotYetImplementedDispatch.",
        );

        /** @var NotYetImplementedDispatch $err */
        $err = $result->thrown;
        $this->assertSame('not_yet_implemented', $err->code());
        $this->assertSame('config', $err->category());
        $this->assertSame($method, $err->method);
        $this->assertSame(['fixture' => "ergonomic_seam_{$method}"], $err->metadata);
        $this->assertNotNull($err->hint, "Hint should point at the P-card that ships {$method}.");

        // Seam must NOT touch the wire — no captured requests, no
        // transport calls, no temp files.
        $this->assertSame([], $stub->captured());
        $this->assertSame([], $result->tempFiles);
        $this->assertNull($result->returnValue);
        $this->assertNull($result->sseEvents);
    }

    public function test_extends_gisl_error_hierarchy(): void
    {
        $err = new NotYetImplementedDispatch(method: 'compress');

        $this->assertInstanceOf(
            GislConfigError::class,
            $err,
            'NotYetImplementedDispatch must extend GislConfigError so callers catching '
            . 'GislConfigError pick up the pre-I/O capability gap alongside missing-credentials etc.',
        );
        $this->assertInstanceOf(
            GislError::class,
            $err,
            'Future LocalError emitter filters on instanceof GislError; the seam error must satisfy it.',
        );
    }

    public function test_low_level_method_falls_through_to_switch(): void
    {
        // A low-level method that is NOT in ERGONOMIC_METHODS must reach
        // the dispatch switch verbatim. `cancelWorkflow` exercises the
        // POST + JSON-envelope hydration path; the seam not firing AND a
        // request reaching the stub is the assertion.
        $fixture = self::synthesizeFixture(
            name: 'low_level_smoke',
            method: 'cancelWorkflow',
            args: ['019539ac-2222-7000-8000-000000000001'],
        );
        $stub = new StubPsr18Client(
            [[
                'status' => 200,
                'headers' => ['content-type' => 'application/json'],
                'body' => [
                    'type' => 'json',
                    'value' => [
                        'success' => true,
                        'data' => [
                            'workflow_id' => '019539ac-2222-7000-8000-000000000001',
                            'status' => 'cancelled',
                            'cancelled_at' => '2026-04-26T14:00:00Z',
                            'billing_effect' => 'unspent_reservation_released',
                        ],
                    ],
                ],
            ]],
            null,
        );

        $result = Invoke::run($fixture, $stub);

        $this->assertNotInstanceOf(
            NotYetImplementedDispatch::class,
            $result->thrown,
            'Low-level methods must NOT route through the ergonomic seam.',
        );
        $this->assertCount(
            1,
            $stub->captured(),
            'cancelWorkflow must reach the wire — one HTTP request captured.',
        );
    }

    public function test_error_metadata_carries_fixture_name(): void
    {
        $fixture = self::synthesizeFixture('custom_fixture_label', 'compress');
        $result = Invoke::run($fixture, new StubPsr18Client([], null));

        $this->assertInstanceOf(NotYetImplementedDispatch::class, $result->thrown);
        /** @var NotYetImplementedDispatch $err */
        $err = $result->thrown;
        $this->assertSame(['fixture' => 'custom_fixture_label'], $err->metadata);
    }

    public function test_error_message_names_method_and_hint(): void
    {
        $err = new NotYetImplementedDispatch(
            method: 'merge',
            hint: 'lands in P3 (merge compose model)',
        );

        $this->assertStringContainsString('merge', $err->getMessage());
        // Don't pin the literal P-card number — `ERGONOMIC_METHODS` may
        // renumber as the plan shifts. The hint substring is stable.
        $this->assertStringContainsString('merge compose model', $err->getMessage());
    }

    /**
     * Pins the current `Invoke::run()` ordering: `mode=webhook` routes to
     * `runWebhook()` BEFORE the ergonomic guard in `dispatch()` runs. A
     * fixture with a webhook mode + an ergonomic method name is malformed
     * (FixtureLoader rejects `mode=webhook + method!=verifyWebhook` at
     * load) — this test exists so any future ordering flip (seam-first,
     * webhook-second) is a conscious choice that fails this assertion.
     *
     * `runWebhook` throws synchronously (no surrounding try/catch in
     * `Invoke::run`) when the webhook block is missing, so the exception
     * escapes directly rather than landing on `InvokeResult::$thrown`.
     */
    public function test_webhook_mode_short_circuits_before_ergonomic_guard(): void
    {
        $fixture = new Fixture(
            name: 'webhook_ordering_pin',
            description: 'pins webhook short-circuit precedes ergonomic guard',
            mode: Fixture::MODE_WEBHOOK,
            sdkMethod: 'compress',
            args: [],
            requests: [],
            responses: [],
            expectedReturn: null,
            hasExpectedReturn: false,
            webhook: null,
            expectsError: true,
            absolutePath: '/dev/null',
        );

        try {
            Invoke::run($fixture, new StubPsr18Client([], null));
            $this->fail('Expected webhook short-circuit to throw a RuntimeException, not reach the seam.');
        } catch (NotYetImplementedDispatch $e) {
            $this->fail(
                'When mode=webhook, runWebhook() must short-circuit BEFORE dispatch(); '
                . 'a malformed webhook fixture should surface runWebhook errors, not the seam.',
            );
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('missing webhook block', $e->getMessage());
        }
    }

    /**
     * Malformed `args` (non-list, garbage object) must not crash the
     * ergonomic guard. The seam keys on `$method` alone — `$args` is
     * inspected only inside the low-level switch cases that follow, none
     * of which run for ergonomic verbs. A future refactor that inlines
     * arg validation above the guard would regress this.
     */
    public function test_ergonomic_seam_robust_to_malformed_args(): void
    {
        $fixture = new Fixture(
            name: 'ergonomic_with_garbage_args',
            description: 'malformed args do not bypass the seam',
            mode: Fixture::MODE_REQUEST_RESPONSE,
            sdkMethod: 'compress',
            args: [new \stdClass(), ['nested' => ['junk' => null]], 'a string in the wrong slot'],
            requests: [],
            responses: [],
            expectedReturn: null,
            hasExpectedReturn: false,
            webhook: null,
            expectsError: true,
            absolutePath: '/dev/null',
        );

        $result = Invoke::run($fixture, new StubPsr18Client([], null));

        $this->assertInstanceOf(
            NotYetImplementedDispatch::class,
            $result->thrown,
            'Ergonomic seam must throw the structured error regardless of args shape.',
        );
        /** @var NotYetImplementedDispatch $err */
        $err = $result->thrown;
        $this->assertSame('compress', $err->method);
    }

    /**
     * Pins the `ERGONOMIC_METHODS` keyset so a typo (e.g. accidentally
     * registering a low-level method like `cancelWorkflow` in the map)
     * fails loud. The map is private; reflection is the cheap way to
     * read it without widening surface.
     */
    public function test_ergonomic_methods_keyset_is_stable(): void
    {
        $reflection = new \ReflectionClass(Invoke::class);
        /** @var array<string, string> $methods */
        $methods = $reflection->getReflectionConstant('ERGONOMIC_METHODS')->getValue();
        $this->assertSame(
            ['compress', 'thumbnail', 'convert', 'merge', 'watermark', 'archive', 'mapEach', 'bundle'],
            \array_keys($methods),
            'ERGONOMIC_METHODS keyset drifted — update or revert if intentional.',
        );
    }

    public function test_default_metadata_is_empty_array(): void
    {
        $err = new NotYetImplementedDispatch(method: 'compress');
        $this->assertSame([], $err->metadata);
        $this->assertNull($err->hint);
    }

    /**
     * @param list<mixed> $args
     */
    private static function synthesizeFixture(string $name, string $method, array $args = []): Fixture
    {
        return new Fixture(
            name: $name,
            description: "synthesized for {$method} seam test",
            mode: Fixture::MODE_REQUEST_RESPONSE,
            sdkMethod: $method,
            args: $args,
            requests: [],
            responses: [],
            expectedReturn: null,
            hasExpectedReturn: false,
            webhook: null,
            expectsError: true,
            absolutePath: '/dev/null',
        );
    }
}
