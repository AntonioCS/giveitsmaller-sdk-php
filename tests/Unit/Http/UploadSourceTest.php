<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Http;

use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Errors\GislError;
use Gisl\Sdk\Http\UploadSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UploadSource::class)]
final class UploadSourceTest extends TestCase
{
    public function testFromPathReportsSizeNameAndReadsRanges(): void
    {
        $path = self::writeTempFile('abcdefghij'); // 10 bytes
        try {
            $source = UploadSource::fromPath($path);
            self::assertSame(10, $source->size());
            self::assertSame(\basename($path), $source->name());
            self::assertTrue($source->isConcurrencySafe(), 'a path reopens per read → concurrency-safe');
            self::assertSame($path, $source->path());
            self::assertSame('abcde', $source->readRange(0, 5));
            self::assertSame('fghij', $source->readRange(5, 5));
            self::assertSame('cd', $source->readRange(2, 2));
            self::assertSame('abcdefghij', $source->readAll());
        } finally {
            @\unlink($path);
        }
    }

    public function testFromSeekableStreamReadsRangesAndReportsSize(): void
    {
        $stream = \fopen('php://temp', 'r+b');
        self::assertNotFalse($stream);
        \fwrite($stream, 'abcdefghij');
        // Deliberately leave the cursor at EOF — fromStream must size + rewind.
        try {
            $source = UploadSource::fromStream($stream);
            self::assertSame(10, $source->size());
            self::assertSame('upload.bin', $source->name(), 'php://temp has no real URI → default name');
            self::assertFalse($source->isConcurrencySafe(), 'one stream cursor → sequential only');
            self::assertSame('abcde', $source->readRange(0, 5));
            self::assertSame('fghij', $source->readRange(5, 5));
            // Re-reading an earlier range proves the seek works on the held handle.
            self::assertSame('abc', $source->readRange(0, 3));
            self::assertSame('abcdefghij', $source->readAll());
        } finally {
            \fclose($stream);
        }
    }

    public function testFromStreamRejectsNonSeekable(): void
    {
        $pipe = \popen('printf hello', 'r');
        self::assertNotFalse($pipe);
        try {
            $this->expectException(GislConfigError::class);
            $this->expectExceptionMessageMatches('/non-seekable stream/');
            UploadSource::fromStream($pipe);
        } finally {
            \pclose($pipe);
        }
    }

    public function testFromStreamRejectsWriteOnlySeekableStream(): void
    {
        // A write-only handle is seekable but has no readable bytes for the
        // upload body — reject up front (codex VOxtu0RZ-B4 r2).
        $path = self::writeTempFile('');
        try {
            $writeOnly = \fopen($path, 'wb');
            self::assertNotFalse($writeOnly);
            try {
                $this->expectException(GislConfigError::class);
                $this->expectExceptionMessageMatches('/non-readable stream/');
                UploadSource::fromStream($writeOnly);
            } finally {
                \fclose($writeOnly);
            }
        } finally {
            @\unlink($path);
        }
    }

    public function testFromStreamRejectsNonResource(): void
    {
        $this->expectException(GislConfigError::class);
        $this->expectExceptionMessageMatches('/string filesystem path or a stream resource/');
        /** @phpstan-ignore-next-line — deliberately passing a non-resource to assert the guard. */
        UploadSource::fromStream('not a resource');
    }

    public function testPathAccessorThrowsOnStreamSource(): void
    {
        $stream = \fopen('php://temp', 'r+b');
        self::assertNotFalse($stream);
        try {
            $source = UploadSource::fromStream($stream);
            $this->expectException(GislError::class);
            $this->expectExceptionMessageMatches('/stream source/');
            $source->path();
        } finally {
            \fclose($stream);
        }
    }

    public function testReadRangeShortReadThrows(): void
    {
        $stream = \fopen('php://temp', 'r+b');
        self::assertNotFalse($stream);
        \fwrite($stream, 'abc');
        try {
            $source = UploadSource::fromStream($stream);
            $this->expectException(GislError::class);
            $this->expectExceptionMessageMatches('/Short read/');
            $source->readRange(0, 99); // only 3 bytes available
        } finally {
            \fclose($stream);
        }
    }

    private static function writeTempFile(string $bytes): string
    {
        $dir = \sys_get_temp_dir() . '/gisl-uploadsource-' . \bin2hex(\random_bytes(6));
        \mkdir($dir, 0700, true);
        $path = $dir . '/fixture.bin';
        \file_put_contents($path, $bytes);
        return $path;
    }
}
