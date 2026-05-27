<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\Tests\Parity\Fixture;
use Gisl\Sdk\Tests\Parity\Invoke;
use Gisl\Sdk\Tests\Parity\StubPsr18Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the explicit error branches of
 * {@see Invoke::dispatchErgonomic()} (PHP P2 / 7QXkzoIi parity-adapter
 * wiring). Parity adapters cross runner boundaries, so a silently-
 * coerced bad input becomes painful to diagnose at fixture-debug time;
 * each guard fail-loud branch needs an asserted negative path.
 *
 * Test-reviewer flagged this as a confidence-9 gap pre-merge.
 */
#[CoversClass(Invoke::class)]
final class InvokeDispatchErgonomicGuardsTest extends TestCase
{
    public function test_missing_bytes_value_in_first_arg_throws(): void
    {
        $result = Invoke::run(
            self::ergonomicFixture('ergo_bad_args_no_bytes', 'compress', [['kind' => 'not_bytes']]),
            new StubPsr18Client([], null),
        );

        $this->assertInstanceOf(\RuntimeException::class, $result->thrown);
        $this->assertStringContainsString('must be a bytes value', (string) $result->thrown?->getMessage());
    }

    public function test_options_must_be_a_mapping_when_present(): void
    {
        $result = Invoke::run(
            self::ergonomicFixture(
                'ergo_bad_args_options_not_array',
                'compress',
                [self::bytes(), 'this-is-not-an-array'],
            ),
            new StubPsr18Client([], null),
        );

        $this->assertInstanceOf(\RuntimeException::class, $result->thrown);
        $this->assertStringContainsString('args[1] (options) must be a mapping', (string) $result->thrown?->getMessage());
    }

    public function test_terminal_must_be_a_mapping_when_present(): void
    {
        $result = Invoke::run(
            self::ergonomicFixture(
                'ergo_bad_args_terminal_not_array',
                'compress',
                [self::bytes(), ['quality' => 80], 'not-an-array'],
            ),
            new StubPsr18Client([], null),
        );

        $this->assertInstanceOf(\RuntimeException::class, $result->thrown);
        $this->assertStringContainsString('args[2] (terminal) must be a mapping', (string) $result->thrown?->getMessage());
    }

    public function test_submit_webhook_must_be_non_empty(): void
    {
        $result = Invoke::run(
            self::ergonomicFixture(
                'ergo_bad_args_empty_webhook',
                'compress',
                [self::bytes(), [], ['submit' => ['webhook' => '']]],
            ),
            new StubPsr18Client([], null),
        );

        $this->assertInstanceOf(\RuntimeException::class, $result->thrown);
        $this->assertStringContainsString('terminal.submit.webhook must be a non-empty string', (string) $result->thrown?->getMessage());
    }

    public function test_terminal_must_declare_run_or_submit(): void
    {
        $result = Invoke::run(
            self::ergonomicFixture(
                'ergo_bad_args_terminal_neither',
                'compress',
                [self::bytes(), [], ['neither' => 'run-nor-submit']],
            ),
            new StubPsr18Client([], null),
        );

        $this->assertInstanceOf(\RuntimeException::class, $result->thrown);
        $this->assertStringContainsString("must declare exactly one of 'run' or 'submit'", (string) $result->thrown?->getMessage());
    }

    public function test_run_maxwait_must_be_string_or_int(): void
    {
        $result = Invoke::run(
            self::ergonomicFixture(
                'ergo_bad_args_run_maxwait_wrong_type',
                'compress',
                [self::bytes(), [], ['run' => ['maxWait' => ['not' => 'string-or-int']]]],
            ),
            new StubPsr18Client([], null),
        );

        $this->assertInstanceOf(\RuntimeException::class, $result->thrown);
        $this->assertStringContainsString('terminal.run.maxWait must be a string or int', (string) $result->thrown?->getMessage());
    }

    public function test_submit_must_be_a_mapping(): void
    {
        $result = Invoke::run(
            self::ergonomicFixture(
                'ergo_bad_args_submit_not_array',
                'compress',
                [self::bytes(), [], ['submit' => 'not-a-mapping']],
            ),
            new StubPsr18Client([], null),
        );

        $this->assertInstanceOf(\RuntimeException::class, $result->thrown);
        $this->assertStringContainsString('terminal.submit must be a mapping', (string) $result->thrown?->getMessage());
    }

    public function test_run_must_be_a_mapping(): void
    {
        $result = Invoke::run(
            self::ergonomicFixture(
                'ergo_bad_args_run_not_array',
                'compress',
                [self::bytes(), [], ['run' => 'not-a-mapping']],
            ),
            new StubPsr18Client([], null),
        );

        $this->assertInstanceOf(\RuntimeException::class, $result->thrown);
        $this->assertStringContainsString('terminal.run must be a mapping', (string) $result->thrown?->getMessage());
    }

    /**
     * @return array<string, mixed>
     */
    private static function bytes(): array
    {
        return [
            'kind' => 'bytes',
            'source' => 'zeros',
            'value' => '32',
            'filename' => 'fixture.bin',
            'content-type' => 'application/octet-stream',
        ];
    }

    /**
     * @param list<mixed> $args
     */
    private static function ergonomicFixture(string $name, string $method, array $args): Fixture
    {
        return new Fixture(
            name: $name,
            description: "synthesised guard test for {$method}",
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
