<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Generated\OpenApi\Model\AudioWatermarkDecodeRequest;
use Gisl\Generated\OpenApi\Model\AudioWatermarkDecodeResponse;
use Gisl\Generated\OpenApi\Model\ExternalImportCreatedResponse;
use Gisl\Generated\OpenApi\Model\ExternalImportRequest;
use Gisl\Generated\OpenApi\Model\UploadProbeResponse;
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
 * Unit coverage for ticket pxJ1Gal9 (VOxtu0RZ-B2.5) — planned-tier ops:
 * `probeUpload`, `decodeAudioWatermark`, `createExternalImport`. The
 * client-side `preflightClips` aggregator lives in
 * {@see GislClientPreflightClipsTest}; `getSchema` lives in
 * {@see GislClientGetSchemaTest}.
 */
#[CoversClass(GislClient::class)]
final class GislClientPlannedOpsTest extends TestCase
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
        return new Response($status, ['Content-Type' => 'application/json'], $encoded);
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

    public function testProbeUploadPath(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'file_id' => '01936fb2-0000-7000-8000-000000000001',
                    'probe_status' => 'ok',
                ],
            ]),
        ], $captured);

        $client = $this->makeClient($http);
        // Use a fileId with a slash so a stray non-encoded path-template would
        // alter the route silently — `rawurlencode` should produce `%2F`.
        $result = $client->probeUpload('file/with slash');

        self::assertInstanceOf(UploadProbeResponse::class, $result);
        self::assertSame('01936fb2-0000-7000-8000-000000000001', $result->getFileId());

        self::assertCount(1, $captured);
        $request = $captured[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/uploads/file%2Fwith%20slash/probe', $request->getUri()->getPath());
        self::assertSame('Bearer sk_test', $request->getHeaderLine('Authorization'));
    }

    public function testDecodeAudioWatermarkPath(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'decoded' => true,
                    'payload_hex' => 'deadbeef',
                ],
            ]),
        ], $captured);

        $client = $this->makeClient($http);

        $payload = new AudioWatermarkDecodeRequest([
            'file_id' => '01936fb2-0000-7000-8000-000000000002',
            'method_hint' => 'psychoacoustic',
        ]);
        $result = $client->decodeAudioWatermark($payload);

        self::assertInstanceOf(AudioWatermarkDecodeResponse::class, $result);

        self::assertCount(1, $captured);
        $request = $captured[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/audio-watermark/decode', $request->getUri()->getPath());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));

        // The wire shape must be snake_case (`method_hint`), not the camelCase
        // PHP getter name. `ObjectSerializer::sanitizeForSerialization` is
        // what enforces that mapping.
        $body = (string) $request->getBody();
        $decoded = \json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('01936fb2-0000-7000-8000-000000000002', $decoded['file_id'] ?? null);
        self::assertSame('psychoacoustic', $decoded['method_hint'] ?? null);
        self::assertArrayNotHasKey('methodHint', $decoded);
    }

    /**
     * `09eNib6R` Issue 3: the generated `AudioWatermarkDecodeRequest` ctor now
     * defaults `method_hint` to `null` (was `'auto'`), so an omitted
     * `method_hint` is dropped from the serialized wire body — matching the TS
     * reference, which leaves the field `undefined` and `JSON.stringify` drops
     * the key. Before the fix the body always carried `method_hint: "auto"`.
     */
    public function testDecodeAudioWatermarkOmitsMethodHintWhenNotProvided(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'decoded' => true,
                    'payload_hex' => 'deadbeef',
                ],
            ]),
        ], $captured);

        $client = $this->makeClient($http);

        // method_hint deliberately omitted.
        $payload = new AudioWatermarkDecodeRequest([
            'file_id' => '01936fb2-0000-7000-8000-000000000002',
        ]);
        $result = $client->decodeAudioWatermark($payload);

        self::assertInstanceOf(AudioWatermarkDecodeResponse::class, $result);

        self::assertCount(1, $captured);
        $body = (string) $captured[0]->getBody();
        $decoded = \json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('01936fb2-0000-7000-8000-000000000002', $decoded['file_id'] ?? null);
        // The crux: no spurious default on the wire.
        self::assertArrayNotHasKey('method_hint', $decoded);
        self::assertArrayNotHasKey('methodHint', $decoded);
    }

    public function testCreateExternalImportPath(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(201, [
                'success' => true,
                'data' => [
                    'external_source_id' => '01936fb2-0000-7000-8000-000000000003',
                    'expires_at' => '2026-12-31T23:59:59Z',
                ],
            ]),
        ], $captured);

        $client = $this->makeClient($http);

        $payload = new ExternalImportRequest([
            'url' => 'https://example.com/clip.mp4',
            'provider_hint' => 's3_presigned',
        ]);
        $result = $client->createExternalImport($payload);

        self::assertInstanceOf(ExternalImportCreatedResponse::class, $result);
        self::assertSame('01936fb2-0000-7000-8000-000000000003', $result->getExternalSourceId());

        self::assertCount(1, $captured);
        $request = $captured[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/external-imports', $request->getUri()->getPath());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));

        $body = (string) $request->getBody();
        $decoded = \json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('https://example.com/clip.mp4', $decoded['url'] ?? null);
        self::assertSame('s3_presigned', $decoded['provider_hint'] ?? null);
        self::assertArrayNotHasKey('providerHint', $decoded);
    }
}
