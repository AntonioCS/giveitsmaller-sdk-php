<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Ergonomic;

use Gisl\Sdk\Ergonomic\ArchiveFormat;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the {@see ArchiveFormat} backing values against the wire enum on
 * `generated/typescript/operations/schemas/archive.yaml`
 * `options.format.values` (`zip` + `tar.gz`). Forward-declared in PHP P4
 * (`OMuSCt7y`) for the future `wpHoJhuo` (P4b) `.bundle(ArchiveFormat)`
 * sugar — without these assertions a well-intentioned rename (e.g.
 * `'tar.gz'` -> `'targz'`, or `TarGz` -> `Tarball`) wouldn't surface
 * until weeks later when the .bundle() PR breaks cross-runner parity.
 */
#[CoversClass(ArchiveFormat::class)]
final class ArchiveFormatTest extends TestCase
{
    public function test_backing_values_match_wire_contract(): void
    {
        $this->assertSame('zip', ArchiveFormat::Zip->value);
        $this->assertSame('tar.gz', ArchiveFormat::TarGz->value);
    }

    public function test_cases_are_exhaustive(): void
    {
        $this->assertSame(
            [ArchiveFormat::Zip, ArchiveFormat::TarGz],
            ArchiveFormat::cases(),
            'ArchiveFormat cases drifted — if a new format is added, '
            . 'update wire parity + this assertion together.',
        );
    }
}
