<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

/**
 * Archive output format. Mirrors the `format` enum on the wire archive op
 * schema (`generated/typescript/operations/schemas/archive.yaml`
 * `options.format.values`): `zip` and `tar.gz`.
 *
 * **Shipped in PHP P4 (`OMuSCt7y`)** as a forward-declaration for the
 * upcoming `.bundle(ArchiveFormat $format)` sugar landing in `wpHoJhuo`
 * (P4b — single-workflow `.mapEach().bundle()` via terminal archive job
 * per `docs/plans/sdk-ergonomics/lowering.md:381-394, 506-508`). The
 * builders do NOT consume this enum yet — it lands today so the future
 * `.bundle()` PR is a pure addition.
 */
enum ArchiveFormat: string
{
    case Zip = 'zip';
    case TarGz = 'tar.gz';
}
