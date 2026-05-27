<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * Raised by the parity-adapter ergonomic-dispatch seam (PHP P0 / Bljva8nj)
 * for any ergonomic-facade method whose runtime implementation has not yet
 * landed in this SDK build. P1–P7 fill the surface incrementally; until each
 * verb's `case` lands, the seam short-circuits to this structured throw
 * ahead of the low-level dispatch switch in
 * {@see \Gisl\Sdk\Tests\Parity\Invoke::dispatch()}.
 *
 * Conforms to the F5 LocalError contract (`docs/sdks/parity-adapter-contract.md`
 * §4): the parity comparator can assert on `(code, category, metadata)`
 * irrespective of the language-native message text. Categories are the locked
 * six — `config` is the closest match ("pre-I/O client config / capability
 * setup problem"), distinct from `validation` (payload rejection) and `chain`
 * (cardinality / asset-graph). Subject to F1 (`error-taxonomy.yaml`)
 * alignment when F1 lands; the code/category here MUST be re-validated against
 * the registered taxonomy at that point.
 *
 * Extends {@see GislConfigError} so any caller catching `GislError` (or
 * `GislConfigError` specifically) picks this up — keeps parity with how the
 * rest of the SDK's client-side pre-I/O failures surface
 * ({@see GislMissingCredentialsError}, {@see GislFeatureRequiresAuthError}).
 */
final class NotYetImplementedDispatch extends GislConfigError
{
    public const CODE = 'not_yet_implemented';
    public const CATEGORY = 'config';

    /**
     * @param string                $method   Ergonomic method name requested (e.g. `compress`, `merge`).
     * @param string|null           $hint     Optional pointer to the P-card that will fill the surface.
     * @param array<string, mixed>  $metadata Structured fields the comparator may later assert against.
     */
    public function __construct(
        public readonly string $method,
        public readonly ?string $hint = null,
        public readonly array $metadata = [],
    ) {
        $suffix = $hint !== null ? " ({$hint})" : '';
        parent::__construct(
            "Ergonomic-dispatch method \"{$method}\" is not yet implemented in this SDK build{$suffix}. "
            . 'PHP ergonomic facade lands incrementally via P1–P7 sub-cards; this seam (P0) short-circuits '
            . 'with a structured error per the F5 LocalError contract.',
        );
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function category(): string
    {
        return self::CATEGORY;
    }
}
