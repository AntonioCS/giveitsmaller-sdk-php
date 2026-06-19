<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\Tests\Parity\FixtureLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Vgg8yITh — guards on the cross-SDK `expected_error_message` parity field.
 *
 * The reject branches are otherwise unexercised (no real fixture sets a bad
 * value), and the positive case pins that the field is actually THREADED into
 * the Fixture — a dropped thread would silently disable the PHP half of the
 * cross-SDK message assertion while the TS half still passed (the false-green
 * the harness change exists to prevent). Mirrors the TS loader guards in
 * `packages/typescript/tests/parity/fixtures.ts`, which are CI-only (the repo
 * bans local vitest); phpunit is runnable in Docker, so the PHP side is pinned
 * here.
 */
#[CoversClass(FixtureLoader::class)]
final class FixtureLoaderExpectedErrorMessageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/eem_loader_' . \bin2hex(\random_bytes(6));
        \mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->dir . '/*') ?: [] as $file) {
            \unlink($file);
        }
        @\rmdir($this->dir);
    }

    /**
     * Write a minimal valid error fixture named `$stem.yaml` (the loader
     * requires name === filename stem). `$expectsErrorLine` and
     * `$expectedErrorMessageLine` are spliced in verbatim so each test can vary
     * just those two top-level keys.
     */
    private function writeFixture(
        string $stem,
        string $expectsErrorLine,
        string $expectedErrorMessageLine,
    ): string {
        $path = "{$this->dir}/{$stem}.yaml";
        $yaml = <<<YAML
name: {$stem}
{$expectsErrorLine}
{$expectedErrorMessageLine}
sdk:
  method: getWorkflowStatus
  args:
    - 01936fb2-0000-0000-0000-000000000401
requests:
  - method: GET
    path: /api/workflows/01936fb2-0000-0000-0000-000000000401/status
responses:
  - status: 401
    body:
      type: json
      value:
        success: false
        error: API_KEY_INVALID
        error_type: api_key_invalid
        message: The provided API key is invalid.
YAML;
        \file_put_contents($path, $yaml);

        return $path;
    }

    public function test_threads_expected_error_message_into_fixture(): void
    {
        $path = $this->writeFixture(
            'eem_positive',
            'expects_error: true',
            'expected_error_message: The provided API key is invalid.',
        );

        $fixture = FixtureLoader::loadByPath($path);

        self::assertSame('The provided API key is invalid.', $fixture->expectedErrorMessage);
    }

    public function test_rejects_non_string_expected_error_message(): void
    {
        $path = $this->writeFixture(
            'eem_non_string',
            'expects_error: true',
            'expected_error_message: 123',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/expected_error_message must be a string/');

        FixtureLoader::loadByPath($path);
    }

    public function test_rejects_expected_error_message_without_expects_error(): void
    {
        $path = $this->writeFixture(
            'eem_no_expects',
            'expects_error: false',
            'expected_error_message: Should not be allowed.',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/expected_error_message requires expects_error/');

        FixtureLoader::loadByPath($path);
    }
}
