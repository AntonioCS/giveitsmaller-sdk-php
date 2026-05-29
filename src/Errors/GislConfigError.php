<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * Client-side / SDK input or config validation. Distinct from server-side
 * {@see GislValidationError} (server's validation envelope).
 *
 * Raised by the SDK before reaching the wire — unreadable file path, oversized
 * argument, illegal config value, callable that's not callable, etc. The TS
 * reference does not separate these two cases (TS reuses
 * `GislValidationError` for both); PHP needs a distinct class so the
 * server-side typed-payload subclass below can inherit from
 * {@see GislApiError} without colliding with the client-side guard.
 *
 * Subclassable: {@see GislMissingCredentialsError} and
 * {@see GislFeatureRequiresAuthError} extend this class so callers
 * catching `GislConfigError` for local configuration failures also catch
 * those two specialised cases. Mirrors the TS hierarchy
 * (`packages/typescript/src/errors.ts:386-417`).
 *
 * Optional structured metadata (P6 / koMKJjLY — the preset resolver, TS
 * T4b `27rE1fZn`) is attached via the constructor's trailing optional
 * arguments so callers can branch on machine-readable fields instead of
 * parsing the human message. Every metadata argument defaults to absent;
 * existing call sites that throw `new GislConfigError($message)` — and
 * subclasses that call `parent::__construct($message)` — keep working
 * unchanged. Mirrors the TS `GislConfigErrorMetadata` bag
 * (`packages/typescript/src/errors.ts:386-449`).
 */
class GislConfigError extends GislError
{
    /**
     * @param string                    $message           Human-readable message.
     * @param string|null               $reason            Machine-readable code, e.g.
     *                                                      `invalid_combination`, `missing_dependency`,
     *                                                      `type_mismatch`, `unknown_field`,
     *                                                      `invalid_target_size`. Open union.
     * @param list<string>|null         $conflictingFields camelCase field names that participated in
     *                                                      the rejection (e.g. the conflicting pair).
     * @param array<string, mixed>|null $resolvedSnapshot  The merged wire-shape snapshot the resolver
     *                                                      computed before the validation rejected it.
     * @param string|null               $suggestion        Short human-readable remediation hint.
     * @param \Throwable|null            $previous          Underlying cause, preserved for exception
     *                                                      chaining (the inherited Exception surface).
     */
    public function __construct(
        string $message,
        public readonly ?string $reason = null,
        public readonly ?array $conflictingFields = null,
        public readonly ?array $resolvedSnapshot = null,
        public readonly ?string $suggestion = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Machine-readable error code, or null. Accessor mirror of {@see $reason}
     * — the parity local-validation-error projector
     * ({@see \Gisl\Sdk\Tests\Parity\ParityTest}) reads it via `getReason()`.
     */
    public function getReason(): ?string
    {
        return $this->reason;
    }

    /**
     * camelCase fields that participated in the rejection, or null. Accessor
     * mirror of {@see $conflictingFields} for the parity projector.
     *
     * @return list<string>|null
     */
    public function getConflictingFields(): ?array
    {
        return $this->conflictingFields;
    }

    /**
     * The merged wire-shape snapshot computed before validation rejected it,
     * or null. Accessor mirror of {@see $resolvedSnapshot}.
     *
     * @return array<string, mixed>|null
     */
    public function getResolvedSnapshot(): ?array
    {
        return $this->resolvedSnapshot;
    }

    /**
     * Short remediation hint, or null. Accessor mirror of {@see $suggestion}.
     */
    public function getSuggestion(): ?string
    {
        return $this->suggestion;
    }
}
