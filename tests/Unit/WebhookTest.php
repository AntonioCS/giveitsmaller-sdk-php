<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Generated\OpenApi\Model\CallbackEventType;
use Gisl\Generated\OpenApi\Model\WebhookOperationContext;
use Gisl\Generated\OpenApi\Model\WebhookPayload;
use Gisl\Generated\OpenApi\Model\WorkflowStatus;
use Gisl\Generated\OpenApi\Model\WorkflowStatusResponse;
use Gisl\Generated\OpenApi\ObjectSerializer;
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

    /**
     * 8yqUXLCS — the typed-deserialization path a consumer follows once a
     * webhook body has been signature-verified: `json_decode` the raw bytes,
     * then `ObjectSerializer::deserialize($decoded, WebhookPayload::class)`
     * (the same mechanism `GislClient::hydrate` uses for envelope `data`).
     *
     * Pins that every field comes back typed — not left as a raw array —
     * including the nested `workflow` (full `WorkflowStatusResponse`), the
     * `\DateTime` timestamp, and the enum-valued `event_type`.
     */
    public function testWebhookPayloadDeserializesIntoTypedFields(): void
    {
        $raw = \json_encode([
            'event_type' => 'workflow.completed',
            'delivery_id' => '0190aaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa',
            'timestamp' => '2026-06-22T10:15:30Z',
            'workflow' => [
                'workflow_id' => '0190bbbb-bbbb-7bbb-8bbb-bbbbbbbbbbbb',
                'status' => 'completed',
                'created_at' => '2026-06-22T10:00:00Z',
                'updated_at' => '2026-06-22T10:15:00Z',
                'jobs' => [],
            ],
            'operation' => [
                'job_ref' => 'job-1',
                'operation_id' => '0190cccc-cccc-7ccc-8ccc-cccccccccccc',
            ],
        ], JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $payload = ObjectSerializer::deserialize($decoded, WebhookPayload::class, []);

        self::assertInstanceOf(WebhookPayload::class, $payload);

        // Enum-valued field comes through as the canonical wire string and
        // matches the generated enum constant.
        self::assertSame(CallbackEventType::WORKFLOW_COMPLETED, $payload->getEventType());
        self::assertSame('0190aaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa', $payload->getDeliveryId());

        // `timestamp` (format: date-time) is hydrated into a \DateTime.
        self::assertInstanceOf(\DateTime::class, $payload->getTimestamp());
        self::assertSame('2026-06-22T10:15:30+00:00', $payload->getTimestamp()->format(\DATE_ATOM));

        // Nested `workflow` is a fully-typed WorkflowStatusResponse, NOT a raw
        // array — recursive deserialize. This is the property `hydrate`'s
        // docblock calls out as broken under shallow construction.
        $workflow = $payload->getWorkflow();
        self::assertInstanceOf(WorkflowStatusResponse::class, $workflow);
        self::assertSame('0190bbbb-bbbb-7bbb-8bbb-bbbbbbbbbbbb', $workflow->getWorkflowId());
        self::assertSame(WorkflowStatus::COMPLETED, $workflow->getStatus());
        self::assertInstanceOf(\DateTime::class, $workflow->getCreatedAt());
        self::assertSame([], $workflow->getJobs());

        // Optional `operation` context is present + typed for this body.
        $operation = $payload->getOperation();
        self::assertInstanceOf(WebhookOperationContext::class, $operation);
        self::assertSame('job-1', $operation->getJobRef());
        self::assertSame('0190cccc-cccc-7ccc-8ccc-cccccccccccc', $operation->getOperationId());

        self::assertTrue($payload->valid(), 'Deserialized payload satisfies all required-field constraints.');
    }

    /**
     * The `operation` field is opt-in (only `operation.completed` events carry
     * it). When absent from the body the deserialized payload exposes it as
     * null rather than fabricating an empty context.
     */
    public function testWebhookPayloadDeserializesWithoutOptionalOperation(): void
    {
        $raw = \json_encode([
            'event_type' => 'workflow.failed',
            'delivery_id' => '0190dddd-dddd-7ddd-8ddd-dddddddddddd',
            'timestamp' => '2026-06-22T11:00:00Z',
            'workflow' => [
                'workflow_id' => '0190eeee-eeee-7eee-8eee-eeeeeeeeeeee',
                'status' => 'failed',
                'created_at' => '2026-06-22T10:50:00Z',
                'updated_at' => '2026-06-22T11:00:00Z',
                'jobs' => [],
            ],
        ], JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $payload = ObjectSerializer::deserialize($decoded, WebhookPayload::class, []);

        self::assertInstanceOf(WebhookPayload::class, $payload);
        self::assertSame(CallbackEventType::WORKFLOW_FAILED, $payload->getEventType());
        self::assertNull($payload->getOperation());
        self::assertSame(WorkflowStatus::FAILED, $payload->getWorkflow()->getStatus());
        self::assertTrue($payload->valid());
    }

    private function signBody(string $secret, string $body): string
    {
        return 'sha256=' . \hash_hmac('sha256', $body, $secret);
    }
}
