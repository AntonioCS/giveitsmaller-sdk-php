<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\FileFirst;

use Gisl\Sdk\Errors\GislNetworkError;
use Gisl\Sdk\Errors\GislSinkError;
use Gisl\Sdk\FileFirst\StreamingDownloader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * FF2b — the streaming {@see StreamingDownloader}: copy a (pre-signed) URL to
 * a local path via fopen/stream_copy, chunk-by-chunk. A bad source URL throws
 * {@see GislNetworkError}; an unwritable destination throws {@see GislSinkError}.
 * Network-free: a `file://` source URL exercises the streaming copy. Mirrors
 * the TS `http-downloader.test.ts`.
 */
final class StreamingDownloaderTest extends TestCase
{
    #[Test]
    public function streams_the_source_url_to_the_destination_path(): void
    {
        $source = \tempnam(\sys_get_temp_dir(), 'gisl_dl_src_');
        self::assertIsString($source);
        \file_put_contents($source, 'streamed-bytes');

        $dest = \tempnam(\sys_get_temp_dir(), 'gisl_dl_dst_');
        self::assertIsString($dest);

        try {
            (new StreamingDownloader())->downloadTo('file://' . $source, $dest);
            self::assertSame('streamed-bytes', \file_get_contents($dest));
        } finally {
            @\unlink($source);
            @\unlink($dest);
        }
    }

    #[Test]
    public function throws_network_error_when_the_source_cannot_be_opened(): void
    {
        $dest = \tempnam(\sys_get_temp_dir(), 'gisl_dl_dst_');
        self::assertIsString($dest);
        try {
            $this->expectException(GislNetworkError::class);
            (new StreamingDownloader())->downloadTo('file:///no/such/source/file.bin', $dest);
        } finally {
            @\unlink($dest);
        }
    }

    #[Test]
    public function throws_sink_error_when_the_destination_cannot_be_opened(): void
    {
        $source = \tempnam(\sys_get_temp_dir(), 'gisl_dl_src_');
        self::assertIsString($source);
        \file_put_contents($source, 'bytes');

        try {
            // A destination inside a non-existent directory cannot be opened
            // for writing — the sink-side failure surfaces as GislSinkError
            // carrying the machine-readable reason 'write_failed'.
            (new StreamingDownloader())->downloadTo(
                'file://' . $source,
                \sys_get_temp_dir() . '/gisl-no-such-dir-' . \uniqid() . '/out.bin',
            );
            self::fail('expected GislSinkError');
        } catch (GislSinkError $e) {
            self::assertSame('write_failed', $e->getReason());
        } finally {
            @\unlink($source);
        }
    }
}
