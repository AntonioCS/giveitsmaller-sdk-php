<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\Sources;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Sources::class)]
final class SourcesTest extends TestCase
{
    public function testUploadSourceShape(): void
    {
        self::assertSame(
            ['type' => 'upload', 'file_id' => 'file_abc123'],
            Sources::upload('file_abc123'),
        );
    }

    public function testJobOutputSourceWithoutOperation(): void
    {
        self::assertSame(
            ['type' => 'job_output', 'from' => 'compressed'],
            Sources::jobOutput('compressed'),
        );
    }

    public function testJobOutputSourceWithOperation(): void
    {
        self::assertSame(
            ['type' => 'job_output', 'from' => 'compressed', 'operation' => 'thumbnail'],
            Sources::jobOutput('compressed', 'thumbnail'),
        );
    }

    public function testExternalImportSourceShape(): void
    {
        self::assertSame(
            ['type' => 'external_import', 'external_source_id' => 'ext_xyz'],
            Sources::externalImport('ext_xyz'),
        );
    }

    public function testConnectionSourceShape(): void
    {
        self::assertSame(
            ['type' => 'connection', 'connection_id' => 'conn_1', 'path' => 'folder/file.jpg'],
            Sources::connection('conn_1', 'folder/file.jpg'),
        );
    }
}
