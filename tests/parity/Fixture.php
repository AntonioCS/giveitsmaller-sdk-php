<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Parity;

/**
 * Typed PHP shape of one loaded parity fixture.
 *
 * Mirrors the TS interface at
 * `packages/typescript/tests/parity/fixtures.ts`. Sub-shapes (`requests`,
 * `responses`, `webhook`) are carried as `array<string, mixed>` rather than
 * deep-typed value objects — the comparator already walks them recursively
 * and a separate typed tree per leaf would double the surface area without
 * adding meaningful safety on test code.
 */
final class Fixture
{
    public const MODE_REQUEST_RESPONSE = 'request_response';
    public const MODE_SSE = 'sse';
    public const MODE_WEBHOOK = 'webhook';
    /**
     * F4-A (`C45ogrGx`) — fixtures that assert the SDK throws BEFORE
     * any HTTP call. Permits zero requests + zero responses (sidesteps
     * the length-pair check). Requires a `localValidationError` block.
     */
    public const MODE_LOCAL_VALIDATION_ERROR = 'local_validation_error';

    /**
     * Fixture schema version. v1 = legacy (no v2 blocks); v2 = supports
     * `resolvedOptions` / `omittedFromWire` / `localValidationError`.
     */
    public const SCHEMA_VERSION_V1 = '1.0.0';
    public const SCHEMA_VERSION_V2 = '2.0.0';

    /**
     * @param list<array<string, mixed>>      $requests       Expected outbound requests, in order.
     * @param list<array<string, mixed>>      $responses      Canned responses to return, in order.
     * @param list<mixed>                     $args           Arguments for the SDK method invocation.
     * @param mixed                           $expectedReturn The expected return value (already-camelCased
     *                                                       TS-style) or `null` when the fixture omits it.
     * @param array<string, mixed>|null       $webhook        Webhook block (mode=webhook only).
     * @param string                          $absolutePath   Absolute path to the fixture YAML file.
     * @param string                          $schemaVersion  Fixture schema version (v1 / v2). F4-A.
     * @param array<string, mixed>|null       $resolvedOptions v2 assertion block — expected
     *                                                        `result.resolvedOptions` shape. Null on v1
     *                                                        fixtures + on v2 fixtures that omit it. F4-A.
     * @param list<string>|null               $omittedFromWire v2 assertion block — wire fields that MUST
     *                                                        NOT appear in any captured request body. F4-A.
     * @param array<string, mixed>|null       $localValidationError v2 assertion block — shape of the
     *                                                              GislConfigError the SDK throws (when
     *                                                              `mode === MODE_LOCAL_VALIDATION_ERROR`).
     *                                                              F4-A.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly string $mode,
        public readonly string $sdkMethod,
        public readonly array $args,
        public readonly array $requests,
        public readonly array $responses,
        public readonly mixed $expectedReturn,
        public readonly bool $hasExpectedReturn,
        public readonly ?array $webhook,
        public readonly bool $expectsError,
        public readonly string $absolutePath,
        public readonly string $schemaVersion = self::SCHEMA_VERSION_V1,
        public readonly ?array $resolvedOptions = null,
        public readonly ?array $omittedFromWire = null,
        public readonly ?array $localValidationError = null,
    ) {
    }
}
