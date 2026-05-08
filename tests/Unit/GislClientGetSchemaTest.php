<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\Errors\GislApiError;
use Gisl\Sdk\Errors\GislValidationError;
use Gisl\Sdk\GetSchemaHitResult;
use Gisl\Sdk\GetSchemaNotModifiedResult;
use Gisl\Sdk\GetSchemaOptions;
use Gisl\Sdk\GetSchemaResult;
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
 * Unit coverage for `GislClient::getSchema()` — the only method on the
 * surface that does NOT route through `unwrapEnvelope` for its 2xx /
 * 304 path. Tests pin: query-param composition, conditional-revalidation
 * header forwarding, the 304 sentinel shape, body parsing on 200, and
 * the typed-error fall-through for non-2xx / non-304 responses.
 */
#[CoversClass(GislClient::class)]
final class GislClientGetSchemaTest extends TestCase
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
     * Build a minimum-viable schema response body. Required fields per the
     * v2 contract: `schema_version`, `capabilities_version`, `generated_at`,
     * `operations` (the latter as an empty map is fine — the SDK doesn't
     * inspect the contents).
     *
     * @return array<string, mixed>
     */
    private function minimalSchemaBody(): array
    {
        return [
            'schema_version' => '2.6.0',
            'capabilities_version' => 25,
            'generated_at' => '2026-04-29T12:00:00Z',
            'operations' => new \stdClass(),
        ];
    }

    public function testGetSchemaHappyPath(): void
    {
        $body = \json_encode($this->minimalSchemaBody(), JSON_THROW_ON_ERROR);
        $response = new Response(
            200,
            [
                'Content-Type' => 'application/json',
                'ETag' => '"abc123"',
                'Last-Modified' => 'Wed, 29 Apr 2026 12:00:00 GMT',
            ],
            $body,
        );

        $captured = [];
        $http = $this->stubClient([$response], $captured);
        $client = $this->makeClient($http);

        $result = $client->getSchema();

        self::assertInstanceOf(GetSchemaResult::class, $result);
        self::assertInstanceOf(GetSchemaHitResult::class, $result);
        /** @var GetSchemaHitResult $result */
        self::assertSame('2.6.0', $result->schema->getSchemaVersion());
        self::assertSame('"abc123"', $result->etag);
        self::assertSame('Wed, 29 Apr 2026 12:00:00 GMT', $result->lastModified);

        self::assertCount(1, $captured);
        self::assertSame('GET', $captured[0]->getMethod());
        self::assertSame('/api/operations/schema', $captured[0]->getUri()->getPath());
    }

    public function testGetSchema304ReturnsNotModifiedResult(): void
    {
        // 304 carries headers but no body. The SDK must not try to parse the
        // empty body as JSON.
        $response = new Response(
            304,
            [
                'ETag' => '"abc123"',
                'Last-Modified' => 'Wed, 29 Apr 2026 12:00:00 GMT',
            ],
            '',
        );

        $captured = [];
        $http = $this->stubClient([$response], $captured);
        $client = $this->makeClient($http);

        $result = $client->getSchema(new GetSchemaOptions(ifNoneMatch: '"abc123"'));

        self::assertInstanceOf(GetSchemaNotModifiedResult::class, $result);
        /** @var GetSchemaNotModifiedResult $result */
        self::assertSame('"abc123"', $result->etag);
        self::assertSame('Wed, 29 Apr 2026 12:00:00 GMT', $result->lastModified);
    }

    public function testGetSchemaWithMimeTypeFilter(): void
    {
        $body = \json_encode($this->minimalSchemaBody(), JSON_THROW_ON_ERROR);
        $response = new Response(200, ['Content-Type' => 'application/json'], $body);

        $captured = [];
        $http = $this->stubClient([$response], $captured);
        $client = $this->makeClient($http);

        $client->getSchema(new GetSchemaOptions(mimeType: 'image/jpeg'));

        self::assertCount(1, $captured);
        $uri = $captured[0]->getUri();
        self::assertSame('/api/operations/schema', $uri->getPath());
        // RFC 3986 percent-encoding: `/` in `image/jpeg` → `%2F`.
        self::assertSame('mime_type=image%2Fjpeg', $uri->getQuery());
    }

    public function testGetSchemaWithOperationFilter(): void
    {
        $body = \json_encode($this->minimalSchemaBody(), JSON_THROW_ON_ERROR);
        $response = new Response(200, ['Content-Type' => 'application/json'], $body);

        $captured = [];
        $http = $this->stubClient([$response], $captured);
        $client = $this->makeClient($http);

        $client->getSchema(new GetSchemaOptions(operation: 'compress'));

        self::assertCount(1, $captured);
        self::assertSame('operation=compress', $captured[0]->getUri()->getQuery());
    }

    public function testGetSchemaForwardsIfNoneMatchHeader(): void
    {
        $body = \json_encode($this->minimalSchemaBody(), JSON_THROW_ON_ERROR);
        $response = new Response(200, ['Content-Type' => 'application/json'], $body);

        $captured = [];
        $http = $this->stubClient([$response], $captured);
        $client = $this->makeClient($http);

        $client->getSchema(new GetSchemaOptions(
            ifNoneMatch: '"abc"',
            ifModifiedSince: 'Wed, 29 Apr 2026 12:00:00 GMT',
        ));

        self::assertCount(1, $captured);
        $request = $captured[0];
        self::assertSame('"abc"', $request->getHeaderLine('If-None-Match'));
        self::assertSame('Wed, 29 Apr 2026 12:00:00 GMT', $request->getHeaderLine('If-Modified-Since'));
    }

    public function testGetSchemaErrorRoutesThroughUnwrapEnvelope(): void
    {
        // 422 + a `details[].message` shape triggers `GislValidationError`.
        // This is the load-bearing assertion: the schema endpoint MUST flow
        // through the typed-error dispatcher so consumers can branch on
        // `instanceof GislValidationError` for getSchema the same way they
        // do for createWorkflow / probeUpload / etc.
        $envelope = [
            'success' => false,
            'error' => 'validation_failed',
            'message' => 'Invalid query parameters.',
            'details' => [
                ['field' => 'mime_type', 'message' => 'Unknown MIME type: foo/bar'],
            ],
        ];
        $response = new Response(
            422,
            ['Content-Type' => 'application/json'],
            \json_encode($envelope, JSON_THROW_ON_ERROR),
        );

        $captured = [];
        $http = $this->stubClient([$response], $captured);
        $client = $this->makeClient($http);

        try {
            $client->getSchema(new GetSchemaOptions(mimeType: 'foo/bar'));
            self::fail('Expected typed error to surface');
        } catch (GislValidationError $e) {
            self::assertSame(422, $e->statusCode);
            self::assertSame('validation_failed', $e->errorCode);
        } catch (GislApiError $e) {
            // If the validation-detail shape detection ever drifts, surface
            // base GislApiError as a soft fail with informative message.
            self::fail(
                'getSchema 422 dispatched to base GislApiError instead of GislValidationError: '
                . $e->getMessage(),
            );
        }
    }

    public function testGetSchemaResultIsSealed(): void
    {
        // PHP 8.1 has no `sealed` keyword. The pattern is encoded as an
        // abstract base + final subclasses — this test pins that the two
        // documented variants both extend `GetSchemaResult`, which is the
        // contract callers narrow against.
        $hit = new GetSchemaHitResult(
            schema: new \Gisl\Generated\OpenApi\Model\OperationsSchemaResponse([
                'schema_version' => '2.6.0',
                'capabilities_version' => 25,
                'generated_at' => new \DateTime('2026-04-29T12:00:00Z'),
                'operations' => [],
            ]),
            etag: '"abc"',
            lastModified: null,
        );
        $notMod = new GetSchemaNotModifiedResult(etag: '"abc"', lastModified: null);

        self::assertInstanceOf(GetSchemaResult::class, $hit);
        self::assertInstanceOf(GetSchemaResult::class, $notMod);

        // Both subclasses MUST be `final` — narrowing on `instanceof
        // GetSchemaHitResult` would otherwise be unsafe in the face of an
        // arbitrary further subclass.
        $hitReflect = new \ReflectionClass(GetSchemaHitResult::class);
        $notModReflect = new \ReflectionClass(GetSchemaNotModifiedResult::class);
        self::assertTrue($hitReflect->isFinal(), 'GetSchemaHitResult must be final');
        self::assertTrue($notModReflect->isFinal(), 'GetSchemaNotModifiedResult must be final');

        // The base must be abstract — disable direct construction.
        $baseReflect = new \ReflectionClass(GetSchemaResult::class);
        self::assertTrue($baseReflect->isAbstract(), 'GetSchemaResult must be abstract');
    }
}
