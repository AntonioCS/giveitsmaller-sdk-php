<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Parity;

/**
 * Resolves filesystem paths to the shared parity-fixture artefacts.
 *
 * Mirrors the TS reference at
 * `packages/typescript/tests/parity/_fixture-paths.ts`. The fixtures dir is
 * the SAME directory across every language runner — exactly one canonical
 * location at `tests/parity/fixtures/` under the sdks repo root.
 *
 * Path math: this file lives at
 *   packages/php/tests/parity/FixturePaths.php
 * so the repo root is `dirname(__FILE__, 5)` (parity -> tests -> php ->
 * packages -> sdks-root).
 */
final class FixturePaths
{
    /**
     * Absolute path to the giveitsmaller-sdks repo root.
     */
    public static function repoRoot(): string
    {
        return \dirname(__FILE__, 5);
    }

    /**
     * Absolute path to the shared parity-fixture directory.
     *
     * @throws \RuntimeException when the directory cannot be located
     */
    public static function fixturesDir(): string
    {
        $candidate = self::repoRoot() . '/tests/parity/fixtures';
        if (!\is_dir($candidate)) {
            throw new \RuntimeException(
                "Could not locate tests/parity/fixtures/. Tried: {$candidate}.\n"
                . 'Ensure the PHP package is checked out inside the giveitsmaller-sdks repo.',
            );
        }
        return $candidate;
    }

    /**
     * Absolute path to the shared parity-fixture JSON Schema. The schema is
     * advisory — runtime validation in `FixtureLoader` enforces a stricter
     * subset (typo guards, mode/method enums) rather than going through a
     * generic JSON-Schema validator (no portable JSON Schema validator ships
     * in the SDK's dev dependencies).
     */
    public static function schemaPath(): string
    {
        return self::repoRoot() . '/tests/parity/fixture.schema.json';
    }
}
