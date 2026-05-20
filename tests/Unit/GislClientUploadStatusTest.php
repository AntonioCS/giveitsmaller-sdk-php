<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\Errors\GislError;
use Gisl\Sdk\Errors\GislMultipartSessionNotFoundError;
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
 * SDK-3 (Wb6ebOMM) — getUploadStatus() walk-pagination tests. Mirrors
 * `packages/typescript/tests/unit/multipart-status.test.ts`.
 */
#[CoversClass(GislClient::class)]
final class GislClientUploadStatusTest extends TestCase
{
    private HttpFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new HttpFactory();
    }

    public function testSinglePageAggregatesToOneResult(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->statusEnvelope(
                uploadId: 'mp-1',
                totalParts: 3,
                isTruncated: false,
                nextMarker: 3,
                parts: [1, 2, 3],
            )),
        ], $captured);

        $client = $this->makeClient($http);
        $result = $client->getUploadStatus('mp-1');

        self::assertSame('mp-1', $result['uploadId']);
        self::assertSame(3, $result['totalParts']);
        self::assertCount(3, $result['uploadedParts']);
        self::assertSame([1, 2, 3], \array_map(fn ($p) => $p['partNumber'], $result['uploadedParts']));
        self::assertCount(1, $captured);
    }

    public function testTwoPageWalkOver1000Parts(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->statusEnvelope(
                uploadId: 'mp-2',
                totalParts: 1500,
                isTruncated: true,
                nextMarker: 1000,
                parts: \range(1, 1000),
            )),
            $this->jsonResponse(200, $this->statusEnvelope(
                uploadId: 'mp-2',
                totalParts: 1500,
                isTruncated: false,
                nextMarker: 1500,
                parts: \range(1001, 1500),
            )),
        ], $captured);

        $client = $this->makeClient($http);
        $result = $client->getUploadStatus('mp-2');

        self::assertSame(1500, $result['totalParts']);
        self::assertCount(1500, $result['uploadedParts']);
        self::assertSame(1, $result['uploadedParts'][0]['partNumber']);
        self::assertSame(1500, $result['uploadedParts'][1499]['partNumber']);
        self::assertCount(2, $captured);
        $page2 = (string) $captured[1]->getUri();
        self::assertStringContainsString('cursor=1000', $page2);
        self::assertStringContainsString('limit=1000', $page2);
    }

    public function testThrowsWhenServerDoesNotAdvanceMarker(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->statusEnvelope(
                uploadId: 'mp-stuck',
                totalParts: 1500,
                isTruncated: true,
                nextMarker: 0,
                parts: [1],
            )),
        ], $captured);

        $client = $this->makeClient($http);
        $this->expectException(GislError::class);
        $this->expectExceptionMessageMatches('/did not advance/');
        $client->getUploadStatus('mp-stuck');
    }

    public function testRejectsEmptyUploadId(): void
    {
        $captured = [];
        $http = $this->stubClient([], $captured);
        $client = $this->makeClient($http);
        $this->expectException(GislError::class);
        $client->getUploadStatus('');
        self::assertCount(0, $captured);
    }

    public function testSurfaces404AsTypedSessionNotFound(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(404, [
                'success' => false,
                'error' => 'session expired',
                'error_type' => 'MULTIPART_SESSION_NOT_FOUND',
            ]),
        ], $captured);

        $client = $this->makeClient($http);
        $this->expectException(GislMultipartSessionNotFoundError::class);
        $client->getUploadStatus('gone');
    }

    public function testRejectsMissingTotalParts(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'upload_id' => 'mp-bad',
                    'multipart_upload_id' => 'srv',
                    'cloud_key' => 'k',
                    // total_parts missing
                    'uploaded_parts' => [],
                    'next_part_number_marker' => 0,
                    'is_truncated' => false,
                    'manifest_expires_at' => '2026-05-21T12:00:00Z',
                    'recommended_chunk_size' => 16_777_216,
                ],
            ]),
        ], $captured);

        $client = $this->makeClient($http);
        $this->expectException(GislError::class);
        $this->expectExceptionMessageMatches('/total_parts/');
        $client->getUploadStatus('mp-bad');
    }

    /**
     * @param list<ResponseInterface|\Throwable> $queue
     * @param-out list<RequestInterface> $captured
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

    private function makeClient(ClientInterface $http): GislClient
    {
        return new GislClient(
            config: new GislClientConfig(
                baseUrl: 'https://api.example.com',
                apiKey: 'sk_test',
            ),
            httpClient: $http,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonResponse(int $status, array $body): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            \json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param list<int> $parts
     * @return array<string, mixed>
     */
    private function statusEnvelope(
        string $uploadId,
        int $totalParts,
        bool $isTruncated,
        int $nextMarker,
        array $parts,
    ): array {
        return [
            'success' => true,
            'data' => [
                'upload_id' => $uploadId,
                'multipart_upload_id' => 'srv-' . $uploadId,
                'cloud_key' => 'uploads/' . $uploadId,
                'total_parts' => $totalParts,
                'uploaded_parts' => \array_map(
                    fn (int $n) => [
                        'part_number' => $n,
                        'etag' => "\"etag-{$n}\"",
                        'size_bytes' => 16_777_216,
                        'last_modified' => '2026-05-19T12:00:00Z',
                    ],
                    $parts,
                ),
                'next_part_number_marker' => $nextMarker,
                'is_truncated' => $isTruncated,
                'manifest_expires_at' => '2026-05-21T12:00:00Z',
                'recommended_chunk_size' => 16_777_216,
            ],
        ];
    }
}
