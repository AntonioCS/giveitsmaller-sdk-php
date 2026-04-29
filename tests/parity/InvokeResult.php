<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Parity;

/**
 * Outcome of one fixture invocation. Mirrors the TS `invokeFixture` return
 * envelope (`{returnValue, sseEvents?, thrown?}`) plus a list of temp files
 * tracked for cleanup so a fixture that materialised binary upload bytes
 * doesn't leak `/tmp` entries across the test run.
 */
final class InvokeResult
{
    /**
     * @param mixed                         $returnValue The SDK method's return value (`null` for void/SSE).
     * @param list<array<string, mixed>>|null $sseEvents Captured SSE events (set when mode=sse), each
     *                                                  with keys `event` + `data`.
     * @param \Throwable|null               $thrown      Exception raised by the SDK call, captured so
     *                                                  request-level parity can still be asserted.
     * @param list<string>                  $tempFiles   Filesystem paths to clean up after the test.
     */
    public function __construct(
        public readonly mixed $returnValue,
        public readonly ?array $sseEvents,
        public readonly ?\Throwable $thrown,
        public readonly array $tempFiles,
    ) {
    }

    public function cleanup(): void
    {
        foreach ($this->tempFiles as $path) {
            if (\is_file($path)) {
                @\unlink($path);
                $dir = \dirname($path);
                // Remove the fixture-private temp dir if Invoke created
                // one; @rmdir is a no-op when the dir is shared or absent.
                if (\str_starts_with($dir, \sys_get_temp_dir() . '/gisl-parity-')) {
                    @\rmdir($dir);
                }
            }
        }
    }
}
