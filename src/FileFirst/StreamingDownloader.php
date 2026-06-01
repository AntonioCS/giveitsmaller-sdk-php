<?php

declare(strict_types=1);

namespace Gisl\Sdk\FileFirst;

use Gisl\Sdk\Errors\GislNetworkError;
use Gisl\Sdk\Errors\GislSinkError;

/**
 * Streaming {@see Downloader} implementation.
 *
 * Copies a (typically pre-signed) URL to a local path without buffering the
 * whole body in memory. Pre-signed download URLs require no SDK auth, so this
 * opens the source URL directly via a stream and copies it chunk-by-chunk.
 */
final class StreamingDownloader implements Downloader
{
    public function downloadTo(string $url, string $destPath): void
    {
        $in = @fopen($url, 'rb');
        if ($in === false) {
            throw new GislNetworkError('Failed to open download source: ' . $url);
        }

        $out = @fopen($destPath, 'wb');
        if ($out === false) {
            fclose($in);

            throw new GislSinkError(
                'Failed to open destination for writing: ' . $destPath,
                reason: 'write_failed',
            );
        }

        try {
            if (stream_copy_to_stream($in, $out) === false) {
                throw new GislSinkError(
                    'Failed to stream download to destination: ' . $destPath,
                    reason: 'write_failed',
                );
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }
}
