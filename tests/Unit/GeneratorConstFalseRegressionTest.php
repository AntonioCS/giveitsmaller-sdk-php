<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Generated\OpenApi\Model\AuthErrorResponse;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Regression alarm for `09eNib6R` (PHP) — mirror of Python `UVNR8tnb`.
 *
 * The PHP openapi-generator emits `public const SUCCESS_FALSE = 'false';`
 * (string) as the allowable enum value for the `success` discriminator on
 * structured error envelopes. `listInvalidProperties()` does strict
 * `in_array($success, $allowedValues, true)` against this string set, so a
 * model whose `success` was constructed with the boolean `false` (the real
 * wire shape) reports as `invalid` even though it is, per OpenAPI 3.1, a
 * valid `enum: [false]` value.
 *
 * Constructor `setIfExists` bypasses the setter (writes straight to
 * `$container`), so direct construction with bool `false` succeeds silently.
 * The bug surfaces on validation: `valid()` returns false,
 * `listInvalidProperties()` lists the success field.
 *
 * Contracts ADR-0010 (PR #88) fixed the source-spec shape `const: false`
 * to `enum: [false]` to reduce generator-template ambiguity. Empirical
 * verification during the v2.7.1 pin bump (`nSVMV5Jp`) confirmed the
 * generator-emission bug is INDEPENDENT of the source shape — both
 * `const: false` and `enum: [false]` produce the same broken string-typed
 * allowable-values list.
 *
 * This test pins the **current broken behaviour**. When the upstream
 * openapi-generator PHP template is fixed (e.g. emits a typed `bool`
 * const or relaxes the strict-mode comparison), this test will FAIL —
 * which is the intended trip-wire to:
 *
 *   1. Re-evaluate the SDK's typed-error-hydration paths.
 *   2. Close `09eNib6R` (PHP) + `UVNR8tnb` (Python).
 *   3. Drop the ad-hoc string handlers downstream.
 *
 * Do NOT delete this test as a "passing-test cleanup" without going
 * through the upstream-fix detection workflow above.
 */
#[CoversNothing]
final class GeneratorConstFalseRegressionTest extends TestCase
{
    public function testAuthErrorResponseSuccessFalseRejectedByListInvalidProperties(): void
    {
        // Construct with the real wire shape — bool false.
        $obj = new AuthErrorResponse([
            'success' => false,
            'error' => 'INVALID_CREDENTIALS',
            'error_type' => 'invalid_credentials',
        ]);

        // Today: listInvalidProperties() reports success as invalid because
        // the allowable-values set is `['false']` (string) and the strict
        // in_array against bool false fails.
        $invalid = $obj->listInvalidProperties();
        $matchingMessages = array_values(array_filter(
            $invalid,
            static fn (string $msg): bool => stripos($msg, "'success'") !== false,
        ));

        $this->assertNotEmpty(
            $matchingMessages,
            "Expected success-field validation to fail on bool false today; "
                . "if this empty list means 09eNib6R is auto-cleared, "
                . "remove the workaround and close the ticket."
        );

        $this->assertStringContainsString(
            "must be one of 'false'",
            $matchingMessages[0],
            "Failure message shape changed — re-check 09eNib6R pin."
        );
    }
}
