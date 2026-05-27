<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Generated\OpenApi\Model\AuthErrorResponse;
use Gisl\Generated\OpenApi\Model\BalanceExhaustedResponse;
use Gisl\Generated\OpenApi\Model\FeatureNotAvailableResponse;
use Gisl\Generated\OpenApi\Model\FeatureTierRestrictedResponse;
use Gisl\Generated\OpenApi\Model\FeatureViolation;
use Gisl\Generated\OpenApi\Model\ProbePendingResponse;
use Gisl\Generated\OpenApi\Model\TierRestrictionResponse;
use Gisl\Generated\OpenApi\Model\UploadDurationExceedsTierResponse;
use Gisl\Generated\OpenApi\Model\UploadSizeExceedsTierResponse;
use Gisl\Generated\OpenApi\Model\ValidationErrorEnvelope;
use Gisl\Generated\OpenApi\Model\ValidationErrorEnvelopeDetailsInner;
use Gisl\Generated\OpenApi\Model\WorkflowExpiredResponse;
use Gisl\Sdk\Errors\GislApiError;
use Gisl\Sdk\Errors\GislAuthError;
use Gisl\Sdk\Errors\GislBalanceExhaustedError;
use Gisl\Sdk\Errors\GislFeatureNotAvailableError;
use Gisl\Sdk\Errors\GislFeatureTierRestrictedError;
use Gisl\Sdk\Errors\GislProbePendingError;
use Gisl\Sdk\Errors\GislTierRestrictedError;
use Gisl\Sdk\Errors\GislUploadCapExceededError;
use Gisl\Sdk\Errors\GislValidationError;
use Gisl\Sdk\Errors\GislWorkflowExpiredError;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislClientConfig;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Unit coverage for ticket B2.1 — typed error subclasses + i18n triple +
 * validation envelope split. Mirrors `packages/typescript/src/client.ts:490-635`
 * dispatch order. Each test pins one branch of the GislClient::unwrapEnvelope
 * dispatch tree so a regression that shuffles the order or drops a typed
 * subclass surfaces here.
 */
#[CoversClass(GislClient::class)]
final class GislClientTypedErrorsTest extends TestCase
{
    private HttpFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new HttpFactory();
    }

    /**
     * @param list<ResponseInterface|\Throwable> $queue
     * @param-out list<RequestInterface>          $captured
     */
    private function stubClient(array $queue, array &$captured = []): ClientInterface
    {
        $captured = [];
        return new class ($queue, $captured) implements ClientInterface {
            /** @var list<ResponseInterface|\Throwable> */
            private array $queue;
            /** @var list<RequestInterface> */
            private array $captured;

            /**
             * @param list<ResponseInterface|\Throwable> $queue
             * @param list<RequestInterface>             $captured
             */
            public function __construct(array $queue, array &$captured)
            {
                $this->queue = $queue;
                $this->captured = &$captured;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;
                $next = \array_shift($this->queue);
                if ($next === null) {
                    throw new \RuntimeException('Stub PSR-18 client: response queue exhausted');
                }
                if ($next instanceof \Throwable) {
                    if (!$next instanceof ClientExceptionInterface) {
                        throw new \LogicException(
                            'Queued throwables must implement ClientExceptionInterface; got ' . \get_class($next),
                        );
                    }
                    throw $next;
                }
                return $next;
            }
        };
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonResponse(int $status, array $body): ResponseInterface
    {
        $encoded = \json_encode($body, JSON_THROW_ON_ERROR);
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            $encoded,
        );
    }

    private function makeClient(ClientInterface $http, string $baseUrl = 'https://api.example.com'): GislClient
    {
        return new GislClient(
            config: new GislClientConfig(baseUrl: $baseUrl, apiKey: 'sk_test'),
            httpClient: $http,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
    }

    /**
     * Drive the dispatch via getWorkflowStatus — it is the simplest method
     * that hits sendAndUnwrap and surfaces the typed error to the caller.
     */
    private const HARNESS_WORKFLOW_ID = '01936fb2-0000-7000-8000-0000000000ff';

    // ---------------------------------------------------------------------
    // Wire-shape note:
    //
    // Real server envelopes carry `success: false` (bool). The generated
    // typed-error DTOs (`BalanceExhaustedResponse`, `AuthErrorResponse`, …)
    // emit `getSuccessAllowableValues() === ['false']` (the openapi-generator
    // representation of `const: false` — string-enum, not bool). Without
    // intervention, `ObjectSerializer::deserialize` would coerce the wire
    // bool to PHP `bool`, then reject it via the strict enum check, and the
    // entire typed-dispatch tree would silently fall through to base
    // `GislApiError` against real production envelopes.
    //
    // `GislClient::tryDeserialize` strips `success` from the array before
    // deserializing to defuse this. Tests below carry `success: false` —
    // proving the fix works against realistic wire shapes.
    //
    // Tracked as a follow-up contracts-generator ticket (the generator
    // template should emit a bool literal const, not a string-enum).
    // ---------------------------------------------------------------------

    // ---------------------------------------------------------------------
    // 1-7: typed dispatch happy paths
    // ---------------------------------------------------------------------

    public function testBalanceExhaustedDispatch(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(402, [
                'success' => false,
                'error' => 'balance_exhausted',
                'message' => 'You have run out of credits.',
                'message_key' => 'errors.balance.exhausted',
                'locale' => 'en-GB',
                'message_params' => ['available' => 0, 'required' => 5],
                'error_type' => 'balance_exhausted',
                'required_action' => 'add_credits',
                'links' => ['top_up_url' => 'https://example.com/billing/top-up'],
            ]),
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->getWorkflowStatus(self::HARNESS_WORKFLOW_ID);
            self::fail('Expected GislBalanceExhaustedError');
        } catch (GislBalanceExhaustedError $e) {
            self::assertInstanceOf(GislApiError::class, $e);
            self::assertSame(402, $e->statusCode);
            self::assertSame('balance_exhausted', $e->errorCode);
            self::assertInstanceOf(BalanceExhaustedResponse::class, $e->typedPayload);
            self::assertSame('add_credits', $e->typedPayload->getRequiredAction());
            // i18n triple round-trips on the exception readonly props.
            self::assertSame('errors.balance.exhausted', $e->messageKey);
            self::assertSame('en-GB', $e->locale);
            self::assertSame(['available' => 0, 'required' => 5], $e->messageParams);
        }
    }

    public function testTierRestrictedDispatch(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(403, [
                'success' => false,
                'error' => 'tier_restriction',
                'message' => 'Your tier does not permit videos.',
                'message_key' => 'errors.tier.mime_blocked',
                'locale' => 'en-GB',
                'message_params' => ['mime' => 'video/mp4'],
                'error_type' => 'tier_restriction',
                'restriction_kind' => 'mime_type',
                'current_tier' => 'free',
                'required_tier' => 'pro',
            ]),
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->getWorkflowStatus(self::HARNESS_WORKFLOW_ID);
            self::fail('Expected GislTierRestrictedError');
        } catch (GislTierRestrictedError $e) {
            self::assertInstanceOf(GislApiError::class, $e);
            self::assertSame(403, $e->statusCode);
            self::assertSame('tier_restriction', $e->errorCode);
            self::assertInstanceOf(TierRestrictionResponse::class, $e->typedPayload);
            self::assertSame('mime_type', $e->typedPayload->getRestrictionKind());
            self::assertSame('free', $e->typedPayload->getCurrentTier());
            self::assertSame('errors.tier.mime_blocked', $e->messageKey);
            self::assertSame(['mime' => 'video/mp4'], $e->messageParams);
        }
    }

    public function testFeatureTierRestrictedDispatch(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(403, [
                'success' => false,
                'error' => 'feature_tier_restricted',
                'message' => 'Watermark requires the pro tier.',
                'message_key' => 'errors.feature.tier_restricted',
                'locale' => 'en-GB',
                'message_params' => ['feature' => 'image_watermark'],
                'error_type' => 'feature_tier_restricted',
                'violations' => [
                    [
                        'feature' => 'image_watermark',
                        'availability' => 'stable',
                        'required_tier' => 'pro',
                    ],
                ],
            ]),
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->getWorkflowStatus(self::HARNESS_WORKFLOW_ID);
            self::fail('Expected GislFeatureTierRestrictedError');
        } catch (GislFeatureTierRestrictedError $e) {
            self::assertInstanceOf(GislApiError::class, $e);
            self::assertSame(403, $e->statusCode);
            self::assertSame('feature_tier_restricted', $e->errorCode);
            self::assertInstanceOf(FeatureTierRestrictedResponse::class, $e->typedPayload);
            $violations = $e->typedPayload->getViolations();
            self::assertIsArray($violations);
            self::assertCount(1, $violations);
            self::assertInstanceOf(FeatureViolation::class, $violations[0]);
            self::assertSame('image_watermark', $violations[0]->getFeature());
            self::assertSame('errors.feature.tier_restricted', $e->messageKey);
        }
    }

    public function testFeatureNotAvailableDispatch(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(422, [
                'success' => false,
                'error' => 'feature_not_available',
                'message' => 'Audio watermark is planned, not yet available.',
                'message_key' => 'errors.feature.not_available',
                'locale' => 'en-GB',
                'message_params' => ['feature' => 'audio_watermark'],
                'error_type' => 'feature_not_available',
                'violations' => [
                    [
                        'feature' => 'audio_watermark',
                        'availability' => 'planned',
                        'eta' => '2026-Q3',
                    ],
                ],
            ]),
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->getWorkflowStatus(self::HARNESS_WORKFLOW_ID);
            self::fail('Expected GislFeatureNotAvailableError');
        } catch (GislFeatureNotAvailableError $e) {
            self::assertInstanceOf(GislApiError::class, $e);
            self::assertSame(422, $e->statusCode);
            self::assertSame('feature_not_available', $e->errorCode);
            self::assertInstanceOf(FeatureNotAvailableResponse::class, $e->typedPayload);
            $violations = $e->typedPayload->getViolations();
            self::assertIsArray($violations);
            self::assertCount(1, $violations);
            self::assertSame('audio_watermark', $violations[0]->getFeature());
            self::assertSame('errors.feature.not_available', $e->messageKey);
        }
    }

    public function testProbePendingDispatch(): void
    {
        $captured = [];
        // 422 with error_type: probe_pending — referenced upload's
        // server-side probe hasn't completed; caller should poll
        // /api/uploads/{id}/probe and retry. Production trigger is on
        // POST /api/workflows but the SDK error-dispatcher runs on every
        // response, so getWorkflowStatus + same envelope drives the same
        // dispatch path. j2sukTDl typed error.
        $http = $this->stubClient([
            $this->jsonResponse(422, [
                'success' => false,
                'error' => 'probe_pending',
                'message' => 'The upload for job_compress is still being probed.',
                'message_key' => 'errors.workflow.probe_pending',
                'locale' => 'en-GB',
                'error_type' => 'probe_pending',
                'job_ref' => 'job_compress',
            ]),
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->getWorkflowStatus(self::HARNESS_WORKFLOW_ID);
            self::fail('Expected GislProbePendingError');
        } catch (GislProbePendingError $e) {
            self::assertInstanceOf(GislApiError::class, $e);
            self::assertSame(422, $e->statusCode);
            self::assertSame('probe_pending', $e->errorCode);
            self::assertInstanceOf(ProbePendingResponse::class, $e->typedPayload);
            self::assertSame('job_compress', $e->typedPayload->getJobRef());
            self::assertSame('errors.workflow.probe_pending', $e->messageKey);
        }
    }

    public function testWorkflowExpiredDispatch(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(422, [
                'success' => false,
                'error' => 'workflow_expired',
                'message' => 'This workflow has expired.',
                'message_key' => 'errors.workflow.expired',
                'locale' => 'en-GB',
                'error_type' => 'workflow_expired',
                'expired_at' => '2026-04-20T12:34:56Z',
            ]),
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->getWorkflowStatus(self::HARNESS_WORKFLOW_ID);
            self::fail('Expected GislWorkflowExpiredError');
        } catch (GislWorkflowExpiredError $e) {
            self::assertInstanceOf(GislApiError::class, $e);
            self::assertSame(422, $e->statusCode);
            self::assertSame('workflow_expired', $e->errorCode);
            self::assertInstanceOf(WorkflowExpiredResponse::class, $e->typedPayload);
            self::assertInstanceOf(\DateTimeInterface::class, $e->typedPayload->getExpiredAt());
            self::assertSame(
                '2026-04-20T12:34:56+00:00',
                $e->typedPayload->getExpiredAt()->format(\DateTimeInterface::ATOM),
            );
            self::assertSame('errors.workflow.expired', $e->messageKey);
        }
    }

    /**
     * 422 upload_size_exceeds_tier -> GislUploadCapExceededError kind
     * `size_tier` with the typed UploadSizeExceedsTierResponse payload.
     * Mirrors `packages/typescript/src/client.ts` handleResponse tryThrowCap.
     */
    public function testUploadSizeExceedsTierDispatch(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(422, [
                'success' => false,
                'error' => 'Upload exceeds the size cap for your tier',
                'error_type' => 'upload_size_exceeds_tier',
                'current_tier' => 'free',
                'max_size_bytes' => 10485760,
                'required_tier' => 'pro',
                'message' => 'This file is larger than your tier permits.',
                'message_key' => 'error.upload_size_exceeds_tier',
                'locale' => 'en-GB',
            ]),
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->getWorkflowStatus(self::HARNESS_WORKFLOW_ID);
            self::fail('Expected GislUploadCapExceededError');
        } catch (GislUploadCapExceededError $e) {
            self::assertInstanceOf(GislApiError::class, $e);
            self::assertSame(422, $e->statusCode);
            self::assertSame(GislUploadCapExceededError::KIND_SIZE_TIER, $e->kind);
            self::assertInstanceOf(UploadSizeExceedsTierResponse::class, $e->typedPayload);
            self::assertSame(10485760, $e->typedPayload->getMaxSizeBytes());
            self::assertSame('error.upload_size_exceeds_tier', $e->messageKey);
        }
    }

    /**
     * 422 upload_duration_exceeds_tier -> kind `duration_tier` with the
     * typed UploadDurationExceedsTierResponse payload.
     */
    public function testUploadDurationExceedsTierDispatch(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(422, [
                'success' => false,
                'error' => 'Upload exceeds the duration cap for your tier',
                'error_type' => 'upload_duration_exceeds_tier',
                'current_tier' => 'free',
                'max_duration_seconds' => 300,
                'required_tier' => 'pro',
            ]),
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->getWorkflowStatus(self::HARNESS_WORKFLOW_ID);
            self::fail('Expected GislUploadCapExceededError');
        } catch (GislUploadCapExceededError $e) {
            self::assertSame(GislUploadCapExceededError::KIND_DURATION_TIER, $e->kind);
            self::assertInstanceOf(UploadDurationExceedsTierResponse::class, $e->typedPayload);
            self::assertSame(300, $e->typedPayload->getMaxDurationSeconds());
        }
    }

    /**
     * 413 absolute across-tier cap. The contract models 413 as a plain
     * ErrorEnvelope (no error_type discriminator, no typed payload), so the
     * SDK dispatches purely on status with kind `absolute_413` and a null
     * typed payload.
     */
    public function testUploadAbsolute413Dispatch(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(413, [
                'success' => false,
                'error' => 'File size exceeds maximum allowed (500MB)',
            ]),
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->getWorkflowStatus(self::HARNESS_WORKFLOW_ID);
            self::fail('Expected GislUploadCapExceededError');
        } catch (GislUploadCapExceededError $e) {
            self::assertInstanceOf(GislApiError::class, $e);
            self::assertSame(413, $e->statusCode);
            self::assertSame(GislUploadCapExceededError::KIND_ABSOLUTE_413, $e->kind);
            self::assertNull($e->typedPayload);
        }
    }

    public function testTypedAuthErrorDispatch(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(401, [
                'success' => false,
                'error' => 'invalid_credentials',
                'message' => 'Invalid email or password.',
                'message_key' => 'errors.auth.invalid_credentials',
                'locale' => 'en-GB',
                // 'invalid_credentials' is in the AuthErrorType enum (login mechanism).
                'error_type' => 'invalid_credentials',
            ]),
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->getWorkflowStatus(self::HARNESS_WORKFLOW_ID);
            self::fail('Expected GislAuthError');
        } catch (GislAuthError $e) {
            self::assertInstanceOf(GislApiError::class, $e);
            self::assertSame(401, $e->statusCode);
            self::assertSame('invalid_credentials', $e->errorCode);
            self::assertNotNull($e->typedPayload);
            self::assertInstanceOf(AuthErrorResponse::class, $e->typedPayload);
            self::assertSame('invalid_credentials', $e->typedPayload->getErrorType());
            self::assertSame('errors.auth.invalid_credentials', $e->messageKey);
        }
    }

    public function testServerValidationErrorDispatch(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(422, [
                'success' => false,
                'error' => 'validation_failed',
                'message' => 'Validation failed for one or more fields.',
                'details' => [
                    [
                        'message' => 'quality must be between 1 and 100',
                        'field' => 'quality',
                        'operation' => 'compress',
                        'option' => 'quality',
                    ],
                    [
                        'message' => 'output_format is required',
                        'field' => 'output_format',
                    ],
                ],
            ]),
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->getWorkflowStatus(self::HARNESS_WORKFLOW_ID);
            self::fail('Expected GislValidationError');
        } catch (GislValidationError $e) {
            self::assertInstanceOf(GislApiError::class, $e);
            self::assertSame(422, $e->statusCode);
            self::assertSame('validation_failed', $e->errorCode);
            self::assertInstanceOf(ValidationErrorEnvelope::class, $e->typedPayload);
            $details = $e->typedPayload->getDetails();
            self::assertIsArray($details);
            self::assertCount(2, $details);
            self::assertInstanceOf(ValidationErrorEnvelopeDetailsInner::class, $details[0]);
            self::assertSame('quality must be between 1 and 100', $details[0]->getMessage());
            self::assertSame('quality', $details[0]->getField());
            self::assertSame('compress', $details[0]->getOperation());
            self::assertSame('quality', $details[0]->getOption());
            self::assertSame('output_format is required', $details[1]->getMessage());
        }
    }

    // ---------------------------------------------------------------------
    // 8-11: defense-in-depth — malformed envelopes fall through to base
    // ---------------------------------------------------------------------

    public function testMalformedBalanceExhaustedFallsThroughToBase(): void
    {
        // 402 + balance_exhausted but the contract-required `required_action`
        // discriminator is missing. The dispatch's typed-validity check
        // (\is_string($typed->getRequiredAction())) must fail and let the
        // generic GislApiError fall through.
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(402, [
                'success' => false,
                'error' => 'balance_exhausted',
                'message' => 'You have run out of credits.',
                'error_type' => 'balance_exhausted',
                // required_action intentionally omitted.
            ]),
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->getWorkflowStatus(self::HARNESS_WORKFLOW_ID);
            self::fail('Expected GislApiError');
        } catch (GislApiError $e) {
            self::assertNotInstanceOf(GislBalanceExhaustedError::class, $e);
            self::assertSame(402, $e->statusCode);
            self::assertSame('balance_exhausted', $e->errorCode);
        }
    }

    public function testWorkflowExpiredWithMissingDateFallsThroughToBase(): void
    {
        // 422 + workflow_expired without `expired_at` — the typed-validity
        // check (`getExpiredAt() instanceof DateTimeInterface`) fails and the
        // base GislApiError takes over.
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(422, [
                'success' => false,
                'error' => 'workflow_expired',
                'message' => 'This workflow has expired.',
                'error_type' => 'workflow_expired',
                // expired_at intentionally omitted.
            ]),
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->getWorkflowStatus(self::HARNESS_WORKFLOW_ID);
            self::fail('Expected GislApiError');
        } catch (GislApiError $e) {
            self::assertNotInstanceOf(GislWorkflowExpiredError::class, $e);
            self::assertSame(422, $e->statusCode);
            self::assertSame('workflow_expired', $e->errorCode);
        }
    }

    public function testFeatureViolationsMissingFeatureFallsThroughToBase(): void
    {
        // 403 + feature_tier_restricted with violations that lack the required
        // `feature` discriminator — areFeatureViolations() must reject and the
        // base GislApiError takes over.
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(403, [
                'success' => false,
                'error' => 'feature_tier_restricted',
                'message' => 'Feature requires upgrade.',
                'error_type' => 'feature_tier_restricted',
                'violations' => [
                    // Violation entry without `feature`.
                    ['required_tier' => 'pro'],
                ],
            ]),
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->getWorkflowStatus(self::HARNESS_WORKFLOW_ID);
            self::fail('Expected GislApiError');
        } catch (GislApiError $e) {
            self::assertNotInstanceOf(GislFeatureTierRestrictedError::class, $e);
            self::assertSame(403, $e->statusCode);
            self::assertSame('feature_tier_restricted', $e->errorCode);
        }
    }

    public function test401WithUnknownErrorTypeFallsToGenericGislAuthError(): void
    {
        // `invalid_api_key` is NOT in the AuthErrorType enum — the actual
        // value is `api_key_invalid`. The typed-auth branch is therefore
        // skipped, and the 401 fallback throws a GislAuthError with
        // typedPayload === null. Guards the existing GislClientTest's
        // testFailureEnvelope401ThrowsGislAuthError fallback path.
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(401, [
                'success' => false,
                'error' => 'invalid_api_key',
                'message' => 'API key is missing or invalid.',
                'error_type' => 'invalid_api_key',
            ]),
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->getWorkflowStatus(self::HARNESS_WORKFLOW_ID);
            self::fail('Expected GislAuthError');
        } catch (GislAuthError $e) {
            self::assertSame(401, $e->statusCode);
            self::assertSame('invalid_api_key', $e->errorCode);
            self::assertNull(
                $e->typedPayload,
                'Fallback 401 path must leave typedPayload null so callers can '
                . 'distinguish a contract-pinned auth subtype from the generic 401.',
            );
        }
    }

    // ---------------------------------------------------------------------
    // 12-15: i18n triple round-trips
    // ---------------------------------------------------------------------

    public function testI18nTripleRoundTripsThroughGenericGislApiError(): void
    {
        // 503 with no error_type is the simplest "no typed branch matched"
        // path. The triple still has to round-trip onto the base class.
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(503, [
                'success' => false,
                'error' => 'service_unavailable',
                'message' => 'Backend overloaded.',
                'message_key' => 'errors.service.unavailable',
                'locale' => 'en-GB',
                'message_params' => ['retry_after_seconds' => 30],
            ]),
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->getWorkflowStatus(self::HARNESS_WORKFLOW_ID);
            self::fail('Expected GislApiError');
        } catch (GislApiError $e) {
            // Must not be any subclass — this is the generic fallback.
            self::assertNotInstanceOf(GislAuthError::class, $e);
            self::assertNotInstanceOf(GislValidationError::class, $e);
            self::assertSame(503, $e->statusCode);
            self::assertSame('errors.service.unavailable', $e->messageKey);
            self::assertSame('en-GB', $e->locale);
            self::assertSame(['retry_after_seconds' => 30], $e->messageParams);
        }
    }

    public function testI18nTripleRoundTripsThroughTypedSubclass(): void
    {
        // The triple is read once at the top of unwrapEnvelope and threaded
        // through every typed-throw path. Pin that the typed subclass path
        // does NOT drop any of the three fields when it constructs the
        // typed error — both the typed payload AND the triple have to
        // arrive on the exception.
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(402, [
                'success' => false,
                'error' => 'balance_exhausted',
                'message' => 'You have run out of credits.',
                'message_key' => 'errors.balance.exhausted',
                'locale' => 'fr-FR',
                'message_params' => ['available' => 0],
                'error_type' => 'balance_exhausted',
                'required_action' => 'upgrade_plan',
            ]),
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->getWorkflowStatus(self::HARNESS_WORKFLOW_ID);
            self::fail('Expected GislBalanceExhaustedError');
        } catch (GislBalanceExhaustedError $e) {
            // Triple round-trips alongside the typed payload.
            self::assertSame('errors.balance.exhausted', $e->messageKey);
            self::assertSame('fr-FR', $e->locale);
            self::assertSame(['available' => 0], $e->messageParams);
            // Typed payload still present and narrowable.
            self::assertSame('upgrade_plan', $e->typedPayload->getRequiredAction());
        }
    }

    public function testI18nTripleAbsenceProducesNullProperties(): void
    {
        // Pin the contract: when the wire envelope omits all three i18n
        // fields, the readonly props are null (not "" / [] / unset). A
        // future regression that defaults messageParams to [] would silently
        // hide whether the server actually emitted the field; this test
        // makes that loud.
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(500, [
                'success' => false,
                'error' => 'internal_error',
                'message' => 'Something went wrong.',
            ]),
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->getWorkflowStatus(self::HARNESS_WORKFLOW_ID);
            self::fail('Expected GislApiError');
        } catch (GislApiError $e) {
            self::assertNull($e->messageKey);
            self::assertNull($e->locale);
            self::assertNull($e->messageParams);
        }
    }

    public function testI18nMessageParamsRejectsNonArray(): void
    {
        // Wire-corruption: `message_params` arrives as a string rather than
        // an object/array. The unwrap path must coerce to null silently
        // rather than crash — wire-typed mismatches on optional fields
        // shouldn't take down the whole error path. The defensive check is
        // `isset(...) && \is_array(...)` per
        // `packages/php/src/GislClient.php::unwrapEnvelope`.
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(500, [
                'success' => false,
                'error' => 'internal_error',
                'message' => 'Something went wrong.',
                'message_key' => 'errors.internal',
                'locale' => 'en-GB',
                'message_params' => 'not_an_array',
            ]),
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->getWorkflowStatus(self::HARNESS_WORKFLOW_ID);
            self::fail('Expected GislApiError');
        } catch (GislApiError $e) {
            self::assertSame('errors.internal', $e->messageKey);
            self::assertSame('en-GB', $e->locale);
            self::assertNull(
                $e->messageParams,
                'Non-array message_params must coerce to null silently.',
            );
        }
    }

    // ---------------------------------------------------------------------
    // 16-18: validation-shape edge cases (preserve existing test expectations)
    // ---------------------------------------------------------------------

    public function testV1StyleDetailsWithReasonKeyDoesNotTriggerValidationDispatch(): void
    {
        // Critical regression guard: the existing
        // `testFailureEnvelope4xxThrowsGislApiErrorWithPayload` in
        // GislClientTest.php:373 emits `details: [{field, reason}]` (no
        // `message`). The shape check must reject this shape so the dispatch
        // falls through to GislApiError, NOT the new server-side
        // GislValidationError — otherwise the existing test breaks.
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(422, [
                'success' => false,
                'error' => 'validation_failed',
                'message' => 'Bad input.',
                'details' => [['field' => 'quality', 'reason' => 'out_of_range']],
            ]),
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->getWorkflowStatus(self::HARNESS_WORKFLOW_ID);
            self::fail('Expected GislApiError');
        } catch (GislApiError $e) {
            self::assertNotInstanceOf(GislValidationError::class, $e);
            self::assertSame(422, $e->statusCode);
            self::assertSame('validation_failed', $e->errorCode);
            // The raw details still land on $payload for legacy callers.
            self::assertSame(
                [['field' => 'quality', 'reason' => 'out_of_range']],
                $e->payload['details'],
            );
        }
    }

    public function testEmptyDetailsArrayDoesNotTriggerValidationDispatch(): void
    {
        // Empty `details: []` is not a validation envelope — fall through to
        // the generic GislApiError. The dispatch's `$details !== []` guard
        // pins this.
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(422, [
                'success' => false,
                'error' => 'validation_failed',
                'message' => 'Bad input.',
                'details' => [],
            ]),
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->getWorkflowStatus(self::HARNESS_WORKFLOW_ID);
            self::fail('Expected GislApiError');
        } catch (GislApiError $e) {
            self::assertNotInstanceOf(GislValidationError::class, $e);
            self::assertSame(422, $e->statusCode);
        }
    }

    public function testAssociativeDetailsArrayDoesNotTriggerValidationDispatch(): void
    {
        // Associative-shaped `details: { ... }` (not a list) is not a
        // validation envelope — fall through. Pinned by the
        // `\array_is_list()` guard in isValidationDetails().
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(422, [
                'success' => false,
                'error' => 'validation_failed',
                'message' => 'Bad input.',
                'details' => ['some_key' => 'value'],
            ]),
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->getWorkflowStatus(self::HARNESS_WORKFLOW_ID);
            self::fail('Expected GislApiError');
        } catch (GislApiError $e) {
            self::assertNotInstanceOf(GislValidationError::class, $e);
            self::assertSame(422, $e->statusCode);
        }
    }

    // ---------------------------------------------------------------------
    // 19: per-entry i18n on validation envelope
    // ---------------------------------------------------------------------

    public function testServerValidationErrorCarriesPerEntryI18nFromValidationEnvelope(): void
    {
        // Each `details[]` entry on the v2 ValidationErrorEnvelope can carry
        // its own i18n triple alongside the envelope-level triple. The typed
        // ValidationErrorEnvelopeDetailsInner exposes them via
        // getMessageKey / getMessageParams. Pin that ObjectSerializer wires
        // them through correctly so callers can render per-field i18n
        // catalogs without re-parsing the raw payload.
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(422, [
                'success' => false,
                'error' => 'validation_failed',
                'message' => 'Validation failed.',
                'message_key' => 'errors.validation',
                'locale' => 'en-GB',
                'details' => [
                    [
                        'message' => 'quality must be between 1 and 100',
                        'field' => 'quality',
                        'message_key' => 'errors.validation.range',
                        'message_params' => ['min' => 1, 'max' => 100],
                    ],
                ],
            ]),
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->getWorkflowStatus(self::HARNESS_WORKFLOW_ID);
            self::fail('Expected GislValidationError');
        } catch (GislValidationError $e) {
            self::assertInstanceOf(ValidationErrorEnvelope::class, $e->typedPayload);
            // Envelope-level triple still on the exception.
            self::assertSame('errors.validation', $e->messageKey);
            self::assertSame('en-GB', $e->locale);
            $details = $e->typedPayload->getDetails();
            self::assertIsArray($details);
            self::assertCount(1, $details);
            $detail = $details[0];
            self::assertInstanceOf(ValidationErrorEnvelopeDetailsInner::class, $detail);
            self::assertSame('errors.validation.range', $detail->getMessageKey());
            self::assertSame(['min' => 1, 'max' => 100], $detail->getMessageParams());
        }
    }

    /**
     * Pin the documented PHP-side divergence from TS for `violations: []`.
     * The generated `FeatureTierRestrictedResponse::setViolations` throws on
     * `count < 1` (contract pins `minItems: 1`), so `tryDeserialize` returns
     * null and dispatch falls through to base `GislApiError`. TS's
     * `[].every(...)` makes the empty case route to the typed class.
     *
     * The malformed-envelope scenario is the only realistic trigger; the
     * server contract guarantees at least one violation. Pin this so a
     * regression that "fixes" the divergence in one direction without
     * touching the other surfaces here.
     */
    public function testEmptyFeatureViolationsFallsThroughToBaseGislApiError(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(403, [
                'success' => false,
                'error' => 'feature_tier_restricted',
                'message' => 'Feature is gated.',
                'error_type' => 'feature_tier_restricted',
                'violations' => [],
            ]),
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->getWorkflowStatus(self::HARNESS_WORKFLOW_ID);
            self::fail('Expected GislApiError');
        } catch (GislFeatureTierRestrictedError $e) {
            self::fail('Empty violations should fall through to base GislApiError, got typed: ' . $e::class);
        } catch (GislApiError $e) {
            // Expected — the DTO's `minItems: 1` rejects empty violations,
            // so dispatch falls through.
            self::assertSame(403, $e->statusCode);
            self::assertSame('feature_tier_restricted', $e->errorCode);
        }
    }

    /**
     * Back-compat pin: positional construction of `GislAuthError` with an
     * `array` 4th argument (the inherited `GislApiError` payload slot) must
     * keep working. Codex round 3 caught a regression where the typed payload
     * was inserted at position 4, causing a TypeError on the legacy form.
     * Don't let that regress — external callers DO construct exceptions
     * directly (mocks, ports of the SDK to other transports, etc.).
     */
    public function testGislAuthErrorAcceptsArrayPayloadAt4thPositionalArg(): void
    {
        $payload = ['error' => 'invalid_api_key', 'message' => 'bad key'];
        $error = new GislAuthError('API error 401: bad key', 401, 'invalid_api_key', $payload);

        self::assertSame(401, $error->statusCode);
        self::assertSame('invalid_api_key', $error->errorCode);
        self::assertSame($payload, $error->payload);
        self::assertNull($error->typedPayload);
    }

    /**
     * Removal-trigger pin for the `unset($data['success'])` workaround in
     * `GislClient::tryDeserialize` (linked to contracts-generator card
     * `09eNib6R`).
     *
     * The generated typed-error DTOs currently emit
     * `getSuccessAllowableValues() === ['false']` (string-enum) instead of a
     * bool literal. `ObjectSerializer::deserialize` on an envelope carrying
     * `success: false` (the realistic wire shape) throws
     * `\InvalidArgumentException` because `in_array(false, ['false'], true)`
     * is false.
     *
     * When the contracts-generator fix lands and this test starts FAILING
     * (because deserialize stops throwing), the `unset($data['success'])`
     * line in `GislClient::tryDeserialize` is no longer needed and should be
     * removed in the same change. The test failure is the forcing function.
     */
    public function testTypedDtoStillRejectsBoolSuccessWithoutWorkaround(): void
    {
        $envelope = [
            'success' => false,
            'error' => 'balance_exhausted',
            'message' => 'No credits.',
            'error_type' => 'balance_exhausted',
            'required_action' => 'add_credits',
            'links' => ['top_up_url' => 'https://example.com/billing/top-up'],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/success/i');

        // Direct deserialize, bypassing GislClient::tryDeserialize. Pins the
        // generator-side bug; once the bug is fixed this throw goes away and
        // the test must be updated alongside removing the unset workaround.
        \Gisl\Generated\OpenApi\ObjectSerializer::deserialize(
            $envelope,
            BalanceExhaustedResponse::class,
            [],
        );
    }
}
