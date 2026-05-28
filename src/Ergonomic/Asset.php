<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

/**
 * Sealed marker for the two-variant union {@see PathAsset} | {@see HandleAsset}.
 *
 * No methods: the variants are pattern-matched via `instanceof` at the
 * boundary ({@see MergeBuilder}'s `assetIdentity()` / upload loop) — adding
 * a method here would force every variant to implement it and erase the
 * sealed-discriminator clarity the TS reference's tagged union provides.
 *
 * PHP cannot truly seal an interface (any package could declare a new
 * implementor), but the {@see Merge} factory is the only sanctioned
 * constructor for merge inputs, and the SDK treats unknown variants as
 * a programmer error.
 */
interface Asset
{
}
