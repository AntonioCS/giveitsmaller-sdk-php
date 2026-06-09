<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\Errors\GislApiError;
use Gisl\Sdk\Errors\GislAuthError;
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
 * Unit coverage for ticket fcRk8LX1 — PHP parity for the TS SDK's
 * `responseHeaders` + `contentLanguage` on `GislApiError` (and subclasses),
 * plus the `locale` client-config option that injects `Accept-Language` on
 * outbound requests.
 *
 * Mirrors the patterns in GislClientTypedErrorsTest and GislClientTest.
 */
#[CoversClass(GislClient::class)]
#[CoversClass(GislClientConfig::class)]
#[CoversClass(GislApiError::class)]
final class GislClientResponseHeadersTest extends TestCase
{
    private HttpFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new HttpFactory();
    }

    // ---------------------------------------------------------------
    // Stub helpers (same pattern as GislClientTest / GislClientTypedErrorsTest)
    // ---------------------------------------------------------------

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
     * @param array<string, mixed>               $body
     * @param array<string, string|list<string>> $extraHeaders
     */
    private function jsonResponse(int $status, array $body, array $extraHeaders = []): ResponseInterface
    {
        $encoded = \json_encode($body, JSON_THROW_ON_ERROR);
        $headers = ['Content-Type' => 'application/json'];
        foreach ($extraHeaders as $name => $value) {
            $headers[$name] = $value;
        }
        return new Response($status, $headers, $encoded);
    }

    private function makeClient(ClientInterface $http, GislClientConfig $config): GislClient
    {
        return new GislClient(
            config: $config,
            httpClient: $http,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
    }

    private function makeDefaultClient(ClientInterface $http): GislClient
    {
        return $this->makeClient(
            $http,
            new GislClientConfig(baseUrl: 'https://api.example.com', apiKey: 'sk_test'),
        );
    }

    /** Drive the dispatch via getWorkflowStatus — simplest method that surfaces errors. */
    private const HARNESS_WORKFLOW_ID = '01936fb2-0000-7000-8000-0000000000ff';

    // ---------------------------------------------------------------
    // Section A — GislApiError direct construction: responseHeaders + contentLanguage
    // ---------------------------------------------------------------

    /**
     * Direct construction: responseHeaders and contentLanguage round-trip on
     * GislApiError when constructed positionally with the new trailing params.
     */
    public function testGislApiErrorExposesResponseHeadersAndContentLanguage(): void
    {
        $headers = ['x-request-id' => 'abc-123', 'retry-after' => '30'];
        $error = new GislApiError(
            'Bad request',
            400,
            'bad_request',
            [],       // payload
            null,     // messageKey
            null,     // locale
            null,     // messageParams
            $headers, // responseHeaders
            'fr-FR',  // contentLanguage
        );

        self::assertSame($headers, $error->responseHeaders);
        self::assertSame('fr-FR', $error->contentLanguage);
    }

    /**
     * Default construction: both new params default to their zero-values
     * (empty array + null) when omitted.
     */
    public function testGislApiErrorDefaultsForNewParams(): void
    {
        $error = new GislApiError('Something failed', 500, 'server_error');

        self::assertSame([], $error->responseHeaders);
        self::assertNull($error->contentLanguage);
    }

    /**
     * GislAuthError subclass: the new pair is forwarded through to the parent
     * via the standard param order.
     */
    public function testGislAuthErrorExposesResponseHeadersAndContentLanguage(): void
    {
        $headers = ['x-request-id' => 'xyz-999'];
        $error = new GislAuthError(
            'Unauthorized',
            401,
            'invalid_api_key',
            [],      // payload
            null,    // messageKey
            null,    // locale
            null,    // messageParams
            null,    // typedPayload
            $headers,
            'de-DE',
        );

        // responseHeaders and contentLanguage live on GislApiError (parent)
        self::assertSame($headers, $error->responseHeaders);
        self::assertSame('de-DE', $error->contentLanguage);
        // typedPayload at position 8 is still null
        self::assertNull($error->typedPayload);
    }

    /**
     * GislAuthError: defaults match: empty responseHeaders, null contentLanguage.
     */
    public function testGislAuthErrorDefaultsForNewParams(): void
    {
        $error = new GislAuthError('Unauthorized', 401, 'invalid_api_key');

        self::assertSame([], $error->responseHeaders);
        self::assertNull($error->contentLanguage);
    }

    // ---------------------------------------------------------------
    // Section B — GislClient error path: headers forwarded from HTTP response
    // ---------------------------------------------------------------

    /**
     * When the server failure response carries mixed-case headers
     * (X-Request-Id, Retry-After) + Content-Language, the thrown error must:
     *   - have responseHeaders with LOWERCASED keys
     *   - have contentLanguage === 'fr-FR'
     *   - have the exact value for 'retry-after' present
     *   - NOT have the original mixed-case key 'Retry-After' as a key
     */
    public function testErrorPathExposesMixedCaseResponseHeadersLowercased(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(
                500,
                [
                    'success' => false,
                    'error' => 'internal_error',
                    'message' => 'Server error.',
                ],
                [
                    'X-Request-Id'    => 'req-abc-123',
                    'Retry-After'     => '30',
                    'Content-Language' => 'fr-FR',
                ],
            ),
        ], $captured);

        $client = $this->makeDefaultClient($http);

        try {
            $client->getWorkflowStatus(self::HARNESS_WORKFLOW_ID);
            self::fail('Expected GislApiError');
        } catch (GislApiError $e) {
            // Keys must be lowercased.
            self::assertArrayHasKey('x-request-id', $e->responseHeaders);
            self::assertArrayHasKey('retry-after', $e->responseHeaders);

            // The mixed-case originals must NOT appear as keys.
            self::assertArrayNotHasKey('X-Request-Id', $e->responseHeaders);
            self::assertArrayNotHasKey('Retry-After', $e->responseHeaders);

            // Values are preserved.
            self::assertSame('req-abc-123', $e->responseHeaders['x-request-id']);
            self::assertSame('30', $e->responseHeaders['retry-after']);

            // Content-Language routed to its dedicated property.
            self::assertSame('fr-FR', $e->contentLanguage);
        }
    }

    /**
     * Multi-value headers (multiple values for the same header name) are
     * comma-joined into a single string on the error's responseHeaders map.
     *
     * PSR-7 Response::withHeader(['v1', 'v2']) models multi-value natively;
     * GuzzleHttp\Psr7\Response accepts an array of values per header.
     */
    public function testErrorPathJoinsMultiValueHeadersWithComma(): void
    {
        $captured = [];
        $http = $this->stubClient([
            new Response(
                503,
                [
                    'Content-Type'  => 'application/json',
                    'X-Custom-List' => ['alpha', 'beta', 'gamma'],
                ],
                \json_encode([
                    'success' => false,
                    'error' => 'service_unavailable',
                    'message' => 'Down.',
                ], JSON_THROW_ON_ERROR),
            ),
        ], $captured);

        $client = $this->makeDefaultClient($http);

        try {
            $client->getWorkflowStatus(self::HARNESS_WORKFLOW_ID);
            self::fail('Expected GislApiError');
        } catch (GislApiError $e) {
            self::assertArrayHasKey('x-custom-list', $e->responseHeaders);
            self::assertSame('alpha, beta, gamma', $e->responseHeaders['x-custom-list']);
        }
    }

    /**
     * When the failure response has NO Content-Language header, the thrown
     * error's contentLanguage must be null, not an empty string.
     */
    public function testErrorPathContentLanguageIsNullWhenHeaderAbsent(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(500, [
                'success' => false,
                'error' => 'internal_error',
                'message' => 'Server error.',
            ]),
        ], $captured);

        $client = $this->makeDefaultClient($http);

        try {
            $client->getWorkflowStatus(self::HARNESS_WORKFLOW_ID);
            self::fail('Expected GislApiError');
        } catch (GislApiError $e) {
            self::assertNull(
                $e->contentLanguage,
                'contentLanguage must be null when the response carries no Content-Language header.',
            );
        }
    }

    /**
     * 401 path (GislAuthError subclass): responseHeaders + contentLanguage are
     * still forwarded correctly through the typed-dispatch branch.
     */
    public function testAuthErrorPathForwardsResponseHeadersAndContentLanguage(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(
                401,
                [
                    'success' => false,
                    'error' => 'invalid_api_key',
                    'message' => 'API key is missing or invalid.',
                ],
                [
                    'X-Request-Id'    => 'req-xyz',
                    'Retry-After'     => '60',
                    'Content-Language' => 'fr-FR',
                ],
            ),
        ], $captured);

        $client = $this->makeDefaultClient($http);

        try {
            $client->getWorkflowStatus(self::HARNESS_WORKFLOW_ID);
            self::fail('Expected GislAuthError');
        } catch (GislAuthError $e) {
            self::assertInstanceOf(GislApiError::class, $e);

            // Keys lowercased.
            self::assertArrayHasKey('x-request-id', $e->responseHeaders);
            self::assertArrayNotHasKey('X-Request-Id', $e->responseHeaders);
            self::assertSame('req-xyz', $e->responseHeaders['x-request-id']);
            self::assertSame('60', $e->responseHeaders['retry-after']);

            self::assertSame('fr-FR', $e->contentLanguage);
        }
    }

    /**
     * A response without any response headers (just Content-Type from the
     * test helper) should produce a non-empty responseHeaders map that at
     * minimum contains 'content-type', with no unexpected keys.
     * The load-bearing assertion is that the map IS a valid array and the
     * Content-Language is absent (null).
     */
    public function testErrorPathWithNoExtraHeadersProducesValidHeaderMap(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(422, [
                'success' => false,
                'error' => 'validation_failed',
                'message' => 'Bad.',
            ]),
        ], $captured);

        $client = $this->makeDefaultClient($http);

        try {
            $client->getWorkflowStatus(self::HARNESS_WORKFLOW_ID);
            self::fail('Expected GislApiError');
        } catch (GislApiError $e) {
            // The map is an array (not null, not a non-array).
            self::assertIsArray($e->responseHeaders);

            // content-type comes from the test helper and must be lowercased.
            self::assertArrayHasKey('content-type', $e->responseHeaders);

            // No Content-Language header was sent, so the property is null.
            self::assertNull($e->contentLanguage);
        }
    }

    // ---------------------------------------------------------------
    // Section C — GislClientConfig: locale property
    // ---------------------------------------------------------------

    /**
     * locale defaults to null when omitted.
     */
    public function testGislClientConfigLocaleDefaultsToNull(): void
    {
        $config = new GislClientConfig(baseUrl: 'https://api.example.com');

        self::assertNull($config->locale);
    }

    /**
     * locale stores the supplied BCP-47 tag verbatim.
     */
    public function testGislClientConfigLocaleIsStored(): void
    {
        $config = new GislClientConfig(
            baseUrl: 'https://api.example.com',
            locale: 'fr-FR',
        );

        self::assertSame('fr-FR', $config->locale);
    }

    /**
     * locale can be any BCP-47-shaped string (e.g. 'de', 'zh-Hant-TW').
     */
    public function testGislClientConfigLocaleAcceptsVariousBcp47Tags(): void
    {
        $configSimple = new GislClientConfig(
            baseUrl: 'https://api.example.com',
            locale: 'de',
        );
        self::assertSame('de', $configSimple->locale);

        $configComplex = new GislClientConfig(
            baseUrl: 'https://api.example.com',
            locale: 'zh-Hant-TW',
        );
        self::assertSame('zh-Hant-TW', $configComplex->locale);
    }

    /**
     * locale=null is explicitly accepted (mirrors the nullable type).
     */
    public function testGislClientConfigLocaleAcceptsExplicitNull(): void
    {
        $config = new GislClientConfig(
            baseUrl: 'https://api.example.com',
            locale: null,
        );

        self::assertNull($config->locale);
    }

    /**
     * An empty-string locale normalises to null so it reads as "unset" —
     * parity with the TS target's `if (config.locale)` falsy check (codex
     * round 2, 3001e717c66a). Without this, an empty locale would later
     * overwrite a caller's Accept-Language with an empty value.
     */
    public function testGislClientConfigEmptyLocaleNormalisesToNull(): void
    {
        $config = new GislClientConfig(
            baseUrl: 'https://api.example.com',
            locale: '',
        );

        self::assertNull($config->locale);
    }

    // ---------------------------------------------------------------
    // Section D — GislClient request path: Accept-Language injection
    // ---------------------------------------------------------------

    /**
     * When config has locale='fr-FR', the outgoing request must carry
     * Accept-Language: fr-FR.
     */
    public function testLocaleInjectsAcceptLanguageHeader(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'workflow_id' => self::HARNESS_WORKFLOW_ID,
                    'status' => 'pending',
                    'jobs' => [],
                ],
            ]),
        ], $captured);

        $client = $this->makeClient(
            $http,
            new GislClientConfig(
                baseUrl: 'https://api.example.com',
                apiKey: 'sk_test',
                locale: 'fr-FR',
            ),
        );

        $client->getWorkflowStatus(self::HARNESS_WORKFLOW_ID);

        self::assertCount(1, $captured);
        $request = $captured[0];
        self::assertSame('fr-FR', $request->getHeaderLine('Accept-Language'));
    }

    /**
     * When locale is null, no Accept-Language header must be injected.
     */
    public function testNullLocaleDoesNotInjectAcceptLanguageHeader(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'workflow_id' => self::HARNESS_WORKFLOW_ID,
                    'status' => 'pending',
                    'jobs' => [],
                ],
            ]),
        ], $captured);

        $client = $this->makeClient(
            $http,
            new GislClientConfig(
                baseUrl: 'https://api.example.com',
                apiKey: 'sk_test',
                // locale intentionally omitted — defaults to null
            ),
        );

        $client->getWorkflowStatus(self::HARNESS_WORKFLOW_ID);

        self::assertCount(1, $captured);
        self::assertFalse(
            $captured[0]->hasHeader('Accept-Language'),
            'No Accept-Language header expected when locale is null.',
        );
    }

    /**
     * An empty-string locale must NOT overwrite a caller-supplied
     * Accept-Language — it normalises to null (unset), so the caller's
     * header survives. Parity with TS's falsy `if (config.locale)` guard
     * (codex round 2, 3001e717c66a).
     */
    public function testEmptyLocalePreservesCallerSuppliedAcceptLanguageHeader(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'workflow_id' => self::HARNESS_WORKFLOW_ID,
                    'status' => 'pending',
                    'jobs' => [],
                ],
            ]),
        ], $captured);

        $client = $this->makeClient(
            $http,
            new GislClientConfig(
                baseUrl: 'https://api.example.com',
                apiKey: 'sk_test',
                headers: ['Accept-Language' => 'en-US'],
                locale: '',
            ),
        );

        $client->getWorkflowStatus(self::HARNESS_WORKFLOW_ID);

        self::assertCount(1, $captured);
        self::assertSame('en-US', $captured[0]->getHeaderLine('Accept-Language'));
    }

    /**
     * locale wins over a caller-supplied Accept-Language passed via config
     * headers with a MATCHING case (Accept-Language).
     *
     * Per buildRequest: config headers are applied first, then locale is
     * applied via PSR-7 withHeader which replaces case-insensitively.
     * The final value must be the locale, not the caller's header.
     */
    public function testLocaleWinsOverCallerSuppliedAcceptLanguageInConfigHeaders(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'workflow_id' => self::HARNESS_WORKFLOW_ID,
                    'status' => 'pending',
                    'jobs' => [],
                ],
            ]),
        ], $captured);

        $client = $this->makeClient(
            $http,
            new GislClientConfig(
                baseUrl: 'https://api.example.com',
                apiKey: 'sk_test',
                // Caller passes a config-level Accept-Language that should be overridden.
                headers: ['Accept-Language' => 'en-US'],
                locale: 'fr-FR',
            ),
        );

        $client->getWorkflowStatus(self::HARNESS_WORKFLOW_ID);

        self::assertCount(1, $captured);
        $request = $captured[0];

        // Only fr-FR must be present — en-US must have been replaced.
        self::assertSame(
            'fr-FR',
            $request->getHeaderLine('Accept-Language'),
            'locale must overwrite the caller-supplied Accept-Language from config headers.',
        );
    }

    /**
     * locale wins over a case-DIFFERENT Accept-Language passed via config
     * headers (e.g. 'accept-language' lowercase).
     *
     * PSR-7 withHeader replaces the header case-insensitively, so even a
     * differently-cased header name set earlier in the loop must be replaced.
     */
    public function testLocaleWinsOverLowercasedAcceptLanguageInConfigHeaders(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'workflow_id' => self::HARNESS_WORKFLOW_ID,
                    'status' => 'pending',
                    'jobs' => [],
                ],
            ]),
        ], $captured);

        $client = $this->makeClient(
            $http,
            new GislClientConfig(
                baseUrl: 'https://api.example.com',
                apiKey: 'sk_test',
                headers: ['accept-language' => 'en-US'],
                locale: 'fr-FR',
            ),
        );

        $client->getWorkflowStatus(self::HARNESS_WORKFLOW_ID);

        self::assertCount(1, $captured);
        $request = $captured[0];

        // PSR-7 getHeaderLine is case-insensitive; only fr-FR must appear.
        self::assertSame(
            'fr-FR',
            $request->getHeaderLine('Accept-Language'),
            'locale must overwrite even a lowercase accept-language key from config headers.',
        );
    }

    /**
     * locale does NOT interfere with per-call extraHeaders. The call-site
     * may supply its own headers that are applied AFTER locale — per buildRequest
     * ordering — so an extraHeader Accept-Language should override locale.
     *
     * This test drives uploadFile (which passes extraHeaders internally) to
     * confirm locale is applied before extraHeaders, leaving the call-site as
     * the final authority on that specific request when it explicitly sets one.
     *
     * NOTE: This test only verifies that locale is sent when NO extraHeaders
     * collision is present. The per-call extraHeaders override of locale is an
     * implementation-internal concern (uploadFile uses Content-Type, not
     * Accept-Language), so we assert locale is present after a normal call.
     */
    public function testLocaleIsPresentOnRequestEvenWithOtherExtraHeaders(): void
    {
        // Use a custom config header that doesn't collide with Accept-Language.
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'workflow_id' => self::HARNESS_WORKFLOW_ID,
                    'status' => 'pending',
                    'jobs' => [],
                ],
            ]),
        ], $captured);

        $client = $this->makeClient(
            $http,
            new GislClientConfig(
                baseUrl: 'https://api.example.com',
                apiKey: 'sk_test',
                headers: ['X-Trace-Id' => 'trace-abc'],
                locale: 'de-DE',
            ),
        );

        $client->getWorkflowStatus(self::HARNESS_WORKFLOW_ID);

        self::assertCount(1, $captured);
        $request = $captured[0];
        self::assertSame('de-DE', $request->getHeaderLine('Accept-Language'));
        // The extra config header is also present.
        self::assertSame('trace-abc', $request->getHeaderLine('X-Trace-Id'));
    }
}
