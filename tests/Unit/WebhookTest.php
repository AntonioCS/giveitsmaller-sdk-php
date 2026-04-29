<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\Errors\GislError;
use Gisl\Sdk\Webhook;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Webhook::class)]
final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_test_super_long_random_string_for_testing';

    public function testRoundTripValidSignature(): void
    {
        $body = '{"event":"workflow.completed","workflow_id":"abc-123"}';
        $signature = $this->signBody(self::SECRET, $body);

        self::assertTrue(Webhook::verify(self::SECRET, $signature, $body));
    }

    public function testRoundTripWithEmptyBody(): void
    {
        // 204 No Content webhook (hypothetical) — server still signs, SDK
        // still verifies. hash_hmac on '' is well-defined.
        $signature = $this->signBody(self::SECRET, '');

        self::assertTrue(Webhook::verify(self::SECRET, $signature, ''));
    }

    public function testRejectsMissingPrefix(): void
    {
        $body = 'payload';
        $signature = \hash_hmac('sha256', $body, self::SECRET);

        $this->expectException(GislError::class);
        $this->expectExceptionMessageMatches('/Invalid signature format/');

        Webhook::verify(self::SECRET, $signature, $body);
    }

    public function testRejectsWrongPrefix(): void
    {
        $body = 'payload';
        $signature = 'sha1=' . \hash_hmac('sha1', $body, self::SECRET);

        $this->expectException(GislError::class);
        $this->expectExceptionMessageMatches('/Invalid signature format/');

        Webhook::verify(self::SECRET, $signature, $body);
    }

    public function testRejectsShortHex(): void
    {
        $body = 'payload';
        // 32 chars instead of 64 — well-formed hex but wrong length.
        $signature = 'sha256=' . \str_repeat('a', 32);

        $this->expectException(GislError::class);
        $this->expectExceptionMessage('Webhook signature verification failed');

        Webhook::verify(self::SECRET, $signature, $body);
    }

    public function testRejectsNonHexCharacters(): void
    {
        $body = 'payload';
        // Right length, contains 'g' (not a hex digit).
        $signature = 'sha256=' . \str_repeat('g', 64);

        $this->expectException(GislError::class);
        $this->expectExceptionMessage('Webhook signature verification failed');

        Webhook::verify(self::SECRET, $signature, $body);
    }

    public function testRejectsTamperedSignature(): void
    {
        $body = 'payload';
        $valid = $this->signBody(self::SECRET, $body);
        // Flip the last hex digit — XOR-style flip rather than just '0' so we
        // hit a different byte even if the last char already happened to be 0.
        $tampered = \substr($valid, 0, -1) . ((\substr($valid, -1) === '0') ? '1' : '0');

        $this->expectException(GislError::class);
        $this->expectExceptionMessage('Webhook signature verification failed');

        Webhook::verify(self::SECRET, $tampered, $body);
    }

    public function testRejectsTamperedBody(): void
    {
        $body = '{"id":"orig"}';
        $signature = $this->signBody(self::SECRET, $body);

        $this->expectException(GislError::class);
        $this->expectExceptionMessage('Webhook signature verification failed');

        Webhook::verify(self::SECRET, $signature, '{"id":"forg"}');
    }

    public function testRejectsWrongSecret(): void
    {
        $body = 'payload';
        $signature = $this->signBody('different_secret', $body);

        $this->expectException(GislError::class);
        $this->expectExceptionMessage('Webhook signature verification failed');

        Webhook::verify(self::SECRET, $signature, $body);
    }

    public function testAcceptsUppercaseHex(): void
    {
        // Treating the regex as case-insensitive — uppercase hex is valid per
        // RFC 4648; some HTTP middlewares normalise. Verify still passes.
        $body = 'payload';
        $hex = \hash_hmac('sha256', $body, self::SECRET);
        $signature = 'sha256=' . \strtoupper($hex);

        self::assertTrue(Webhook::verify(self::SECRET, $signature, $body));
    }

    private function signBody(string $secret, string $body): string
    {
        return 'sha256=' . \hash_hmac('sha256', $body, $secret);
    }
}
