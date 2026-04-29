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
     * @param list<array<string, mixed>>      $requests       Expected outbound requests, in order.
     * @param list<array<string, mixed>>      $responses      Canned responses to return, in order.
     * @param list<mixed>                     $args           Arguments for the SDK method invocation.
     * @param mixed                           $expectedReturn The expected return value (already-camelCased
     *                                                       TS-style) or `null` when the fixture omits it.
     * @param array<string, mixed>|null       $webhook        Webhook block (mode=webhook only).
     * @param string                          $absolutePath   Absolute path to the fixture YAML file.
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
    ) {
    }
}
