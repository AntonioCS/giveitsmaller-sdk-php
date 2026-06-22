<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\FileFirst;

use Gisl\Sdk\Errors\GislError;
use Gisl\Sdk\Errors\GislItemFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AjhOUuqQ — the typed terminal item-failure error stored in
 * {@see \Gisl\Sdk\FileFirst\ItemFailure::$error}. Pins the message composition
 * (state-only / state:message / empty-message-still-colon), the structured
 * fields, and the type hierarchy (a `\Throwable` AND a `GislError`).
 *
 * Mirrors the TS `item-failed-error.test.ts`.
 */
#[CoversClass(GislItemFailedError::class)]
final class ItemFailedErrorTest extends TestCase
{
    #[Test]
    public function message_is_the_bare_state_when_no_error_message(): void
    {
        // cancel / expire / credit-pause carry only the bare $state — no colon.
        $err = new GislItemFailedError(null, 'cancelled');

        self::assertSame('cancelled', $err->getMessage());
    }

    #[Test]
    public function message_appends_error_message_after_a_colon(): void
    {
        $err = new GislItemFailedError('bad', 'failed', 'codec exploded');

        self::assertSame('failed: codec exploded', $err->getMessage());
    }

    #[Test]
    public function empty_string_error_message_still_adds_the_colon(): void
    {
        // The colon is driven by "errorMessage is not null", NOT by truthiness —
        // an EMPTY-string message must still produce "state: " with the colon.
        $err = new GislItemFailedError('bad', 'failed', '');

        self::assertSame('failed: ', $err->getMessage());
    }

    #[Test]
    public function exposes_all_structured_fields(): void
    {
        $err = new GislItemFailedError('hero', 'failed', 'too large', 'output_too_large');

        self::assertSame('hero', $err->key);
        self::assertSame('failed', $err->state);
        self::assertSame('too large', $err->errorMessage);
        self::assertSame('output_too_large', $err->errorCode);
    }

    #[Test]
    public function error_message_and_code_default_to_null(): void
    {
        // The bare-state ctor leaves both optional fields null (the cancel/expire
        // shape) — proving the omit-when-null toArray() path upstream.
        $err = new GislItemFailedError(null, 'expired');

        self::assertNull($err->errorMessage);
        self::assertNull($err->errorCode);
    }

    #[Test]
    public function key_may_be_null(): void
    {
        // A keyless single-input run produces a null-keyed failure.
        $err = new GislItemFailedError(null, 'failed', 'boom');

        self::assertNull($err->key);
    }

    #[Test]
    public function is_a_throwable(): void
    {
        $err = new GislItemFailedError('bad', 'failed', 'boom');

        self::assertInstanceOf(\Throwable::class, $err);
    }

    #[Test]
    public function is_a_gisl_error_so_a_broad_catch_handles_it(): void
    {
        // Subclassing GislError lets a caller catch any SDK-originating failure
        // with one `catch (GislError)`.
        $err = new GislItemFailedError('bad', 'failed', 'boom');

        self::assertInstanceOf(GislError::class, $err);
    }
}
