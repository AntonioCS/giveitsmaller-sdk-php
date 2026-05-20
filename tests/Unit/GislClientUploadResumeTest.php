<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\Errors\GislError;
use Gisl\Sdk\Errors\GislMultipartPartCountError;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\MultipartCheckpointState;
use Gisl\Sdk\UploadOptions;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * SDK-3 (Wb6ebOMM) — uploadFile($filePath, resumeUploadId=$id) resume path
 * tests. Mirrors `packages/typescript/tests/unit/upload-resume.test.ts`.
 */
#[CoversClass(GislClient::class)]
final class GislClientUploadResumeTest extends TestCase
{
    private const CHUNK = 16_777_216; // 16 MiB

    private HttpFactory $factory;
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->factory = new HttpFactory();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (\file_exists($path)) {
                @\unlink($path);
            }
        }
    }

    public function testRejectsSubThresholdFile(): void
    {
        $filePath = $this->writeBigFile(1 * 1024 * 1024); // 1 MB, below 10 MB threshold
        $captured = [];
        $client = $this->makeClient($this->stubClient([], $captured));

        $this->expectException(GislError::class);
        $this->expectExceptionMessageMatches('/at-or-below the multipart threshold/');
        $client->uploadFile($filePath, new UploadOptions(resumeUploadId: '01936fb2-0000-7000-8000-000000000aaa'));
    }

    public function testHappyPathResumePutsOnlyMissingPart(): void
    {
        $totalParts = 3;
        $fileSize = ($totalParts - 1) * self::CHUNK + 4 * 1024 * 1024;
        $filePath = $this->writeBigFile($fileSize);

        $captured = [];
        // Queue: status (parts 1,2 uploaded) -> presign batch -> S3 PUT part 3 -> complete
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->statusEnvelope(
                uploadId: '01936fb2-0000-7000-8000-000000000aaa',
                totalParts: $totalParts,
                isTruncated: false,
                nextMarker: 2,
                parts: [1, 2],
            )),
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'upload_id' => '01936fb2-0000-7000-8000-000000000aaa',
                    'presigned_urls' => [
                        ['part_number' => 3, 'url' => 'https://s3.example.com/p3', 'expires_at' => '2026-05-21T12:00:00Z'],
                    ],
                ],
            ]),
            $this->s3Response('"new-etag-3"'),
            $this->jsonResponse(200, [
                'success' => true,
                'data' => ['upload_id' => '01936fb2-0000-7000-8000-000000000aaa', 'status' => 'completed'],
            ]),
        ], $captured);

        $checkpoints = [];
        $progress = [];
        $client = $this->makeClient($http);
        $result = $client->uploadFile(
            $filePath,
            new UploadOptions(
                onProgress: static function (int $up, int $tot) use (&$progress): void {
                    $progress[] = [$up, $tot];
                },
                resumeUploadId: '01936fb2-0000-7000-8000-000000000aaa',
                onCheckpoint: static function (MultipartCheckpointState $s) use (&$checkpoints): void {
                    $checkpoints[] = $s;
                },
            ),
        );

        self::assertSame('01936fb2-0000-7000-8000-000000000aaa', $result->getFileId());
        self::assertSame($fileSize, $result->getSizeBytes());

        // No /initiate call. The 4 captured requests are: GET /status, POST /presign, PUT S3, POST /complete.
        self::assertCount(4, $captured);
        self::assertStringNotContainsString('/initiate', (string) $captured[0]->getUri());

        // /complete carries ALL parts (server-recorded 1,2 + newly-PUT 3).
        $completeBody = \json_decode((string) $captured[3]->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('01936fb2-0000-7000-8000-000000000aaa', $completeBody['upload_id']);
        self::assertSame([1, 2, 3], \array_map(fn ($p) => $p['part_number'], $completeBody['parts']));

        // onProgress entry-seeded from /status (2 parts * CHUNK).
        self::assertGreaterThanOrEqual(2, \count($progress));
        self::assertSame(2 * self::CHUNK, $progress[0][0]);
        self::assertSame($fileSize, $progress[\count($progress) - 1][0]);

        // onCheckpoint fired on entry + after the 1 new PUT.
        self::assertGreaterThanOrEqual(2, \count($checkpoints));
        self::assertSame([1, 2], $checkpoints[0]->uploadedPartNumbers);
        self::assertSame([1, 2, 3], $checkpoints[\count($checkpoints) - 1]->uploadedPartNumbers);

        // JSON round-trip parity with TS.
        $serialised = \json_encode($checkpoints[\count($checkpoints) - 1], JSON_THROW_ON_ERROR);
        $round = \json_decode($serialised, true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('01936fb2-0000-7000-8000-000000000aaa', $round['uploadId']);
        self::assertSame(3, $round['totalParts']);
        self::assertSame([1, 2, 3], $round['uploadedPartNumbers']);
    }

    public function testInterruptMidUploadResumesMissingParts(): void
    {
        $totalParts = 5;
        $fileSize = ($totalParts - 1) * self::CHUNK + 4 * 1024 * 1024;
        $filePath = $this->writeBigFile($fileSize);

        $captured = [];
        $http = $this->stubClient([
            // /status: parts 1, 2, 4 uploaded — missing [3, 5]
            $this->jsonResponse(200, $this->statusEnvelope(
                uploadId: '01936fb2-0000-7000-8000-000000000bbb',
                totalParts: $totalParts,
                isTruncated: false,
                nextMarker: 4,
                parts: [1, 2, 4],
            )),
            // /presign batch for [3, 5]
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'upload_id' => '01936fb2-0000-7000-8000-000000000bbb',
                    'presigned_urls' => [
                        ['part_number' => 3, 'url' => 'https://s3.example.com/u2-p3', 'expires_at' => '2026-05-21T12:00:00Z'],
                        ['part_number' => 5, 'url' => 'https://s3.example.com/u2-p5', 'expires_at' => '2026-05-21T12:00:00Z'],
                    ],
                ],
            ]),
            $this->s3Response('"u2-etag-3"'),
            $this->s3Response('"u2-etag-5"'),
            $this->jsonResponse(200, [
                'success' => true,
                'data' => ['upload_id' => '01936fb2-0000-7000-8000-000000000bbb', 'status' => 'completed'],
            ]),
        ], $captured);

        $checkpoints = [];
        $client = $this->makeClient($http);
        $client->uploadFile(
            $filePath,
            new UploadOptions(
                resumeUploadId: '01936fb2-0000-7000-8000-000000000bbb',
                onCheckpoint: static function (MultipartCheckpointState $s) use (&$checkpoints): void {
                    $checkpoints[] = $s;
                },
            ),
        );

        // 1 entry checkpoint + 2 post-PUT.
        self::assertCount(3, $checkpoints);
        self::assertSame([1, 2, 4], $checkpoints[0]->uploadedPartNumbers);
        self::assertSame([1, 2, 3, 4, 5], $checkpoints[\count($checkpoints) - 1]->uploadedPartNumbers);

        // /presign batch carried [3, 5] in order.
        $presignBody = \json_decode((string) $captured[1]->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame([3, 5], $presignBody['part_numbers']);
    }

    public function testShortCircuitsWhenAllPartsAlreadyUploaded(): void
    {
        $totalParts = 3;
        $fileSize = ($totalParts - 1) * self::CHUNK + 4 * 1024 * 1024;
        $filePath = $this->writeBigFile($fileSize);

        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->statusEnvelope(
                uploadId: '01936fb2-0000-7000-8000-000000000ccc',
                totalParts: $totalParts,
                isTruncated: false,
                nextMarker: 3,
                parts: [1, 2, 3],
            )),
            $this->jsonResponse(200, [
                'success' => true,
                'data' => ['upload_id' => '01936fb2-0000-7000-8000-000000000ccc', 'status' => 'completed'],
            ]),
        ], $captured);

        $client = $this->makeClient($http);
        $result = $client->uploadFile($filePath, new UploadOptions(resumeUploadId: '01936fb2-0000-7000-8000-000000000ccc'));
        self::assertSame('01936fb2-0000-7000-8000-000000000ccc', $result->getFileId());

        // Only 2 captured requests: /status + /complete. No /presign, no S3 PUT.
        self::assertCount(2, $captured);
        foreach ($captured as $req) {
            self::assertStringNotContainsString('/presign', (string) $req->getUri());
            self::assertStringNotContainsString('s3.example.com', (string) $req->getUri());
        }
    }

    public function testRejectsStatusRecommendedChunkSizeBelowContractMinimum(): void
    {
        $totalParts = 3;
        $fileSize = ($totalParts - 1) * self::CHUNK + 4 * 1024 * 1024;
        $filePath = $this->writeBigFile($fileSize);

        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'upload_id' => '01936fb2-0000-7000-8000-000000000fff',
                    'multipart_upload_id' => 'srv',
                    'cloud_key' => 'k',
                    'total_parts' => $totalParts,
                    'uploaded_parts' => [],
                    'next_part_number_marker' => 0,
                    'is_truncated' => false,
                    'manifest_expires_at' => '2026-05-21T12:00:00Z',
                    // Below MULTIPART_CHUNK_SIZE = 16 MiB.
                    'recommended_chunk_size' => 1_048_576,
                ],
            ]),
        ], $captured);
        $client = $this->makeClient($http);
        $this->expectException(GislError::class);
        $this->expectExceptionMessageMatches('/outside the contract range/');
        $client->uploadFile($filePath, new UploadOptions(resumeUploadId: '01936fb2-0000-7000-8000-000000000fff'));
    }

    public function testRejectsStatusTotalPartsAbove10000WithTypedError(): void
    {
        $filePath = $this->writeBigFile(50 * 1024 * 1024);
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'upload_id' => '01936fb2-0000-7000-8000-0000000aaaaa',
                    'multipart_upload_id' => 'srv',
                    'cloud_key' => 'k',
                    'total_parts' => 10_001,
                    'uploaded_parts' => [],
                    'next_part_number_marker' => 0,
                    'is_truncated' => false,
                    'manifest_expires_at' => '2026-05-21T12:00:00Z',
                    'recommended_chunk_size' => self::CHUNK,
                ],
            ]),
        ], $captured);
        $client = $this->makeClient($http);
        try {
            $client->uploadFile($filePath, new UploadOptions(resumeUploadId: '01936fb2-0000-7000-8000-0000000aaaaa'));
            self::fail('Expected GislMultipartPartCountError');
        } catch (GislMultipartPartCountError $e) {
            self::assertSame(10_001, $e->requiredParts);
            self::assertSame(10_000, $e->maxParts);
        }
    }

    public function testRejectsStatusUploadIdMismatch(): void
    {
        $totalParts = 3;
        $fileSize = ($totalParts - 1) * self::CHUNK + 4 * 1024 * 1024;
        $filePath = $this->writeBigFile($fileSize);

        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    // Server returns a different upload_id than requested.
                    'upload_id' => '01936fb2-0000-7000-8000-000000bbbbbb',
                    'multipart_upload_id' => 'srv',
                    'cloud_key' => 'k',
                    'total_parts' => $totalParts,
                    'uploaded_parts' => [],
                    'next_part_number_marker' => 0,
                    'is_truncated' => false,
                    'manifest_expires_at' => '2026-05-21T12:00:00Z',
                    'recommended_chunk_size' => self::CHUNK,
                ],
            ]),
        ], $captured);
        $client = $this->makeClient($http);
        $this->expectException(GislError::class);
        $this->expectExceptionMessageMatches('/mismatching upload_id/');
        $client->uploadFile($filePath, new UploadOptions(resumeUploadId: '01936fb2-0000-7000-8000-0000ccccccc1'));
    }

    public function testCheckpointCallbackThrowDoesNotTriggerDuplicatePut(): void
    {
        // Pins the architect-flagged invariant: a user-callback throw must
        // not dispatch a second PUT for the same part (would double-record
        // etags and corrupt /complete). PHP-side mirror of TS
        // `upload-resume.test.ts:checkpoint-callback throw fails the upload`.
        $totalParts = 2;
        $fileSize = ($totalParts - 1) * self::CHUNK + 4 * 1024 * 1024;
        $filePath = $this->writeBigFile($fileSize);

        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->statusEnvelope(
                uploadId: '01936fb2-0000-7000-8000-000000ddddd1',
                totalParts: $totalParts,
                isTruncated: false,
                nextMarker: 1,
                parts: [1],
            )),
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'upload_id' => '01936fb2-0000-7000-8000-000000ddddd1',
                    'presigned_urls' => [
                        ['part_number' => 2, 'url' => 'https://s3.example.com/u6-p2', 'expires_at' => '2026-05-21T12:00:00Z'],
                    ],
                ],
            ]),
            $this->s3Response('"new-etag-2"'),
        ], $captured);

        $cb = 0;
        $client = $this->makeClient($http);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/callback boom/');
        try {
            $client->uploadFile(
                $filePath,
                new UploadOptions(
                    resumeUploadId: '01936fb2-0000-7000-8000-000000ddddd1',
                    onCheckpoint: static function () use (&$cb): void {
                        $cb++;
                        // First call = entry checkpoint; second call = post-PUT.
                        if ($cb === 2) {
                            throw new \RuntimeException('callback boom');
                        }
                    },
                ),
            );
        } finally {
            // Critically: exactly ONE S3 PUT for part 2. The callback throw
            // must NOT have triggered a retry-loop that re-PUTs the same
            // part. Captured = [/status, /presign, S3-PUT-part-2]. No
            // 4th call (no duplicate PUT, no /complete attempted).
            self::assertCount(3, $captured);
            $s3Puts = \array_filter(
                $captured,
                fn ($r) => \str_contains((string) $r->getUri(), 's3.example.com'),
            );
            self::assertCount(1, $s3Puts);
        }
    }

    public function testRefusesWhenPart1IsMissing(): void
    {
        $totalParts = 3;
        $fileSize = ($totalParts - 1) * self::CHUNK + 4 * 1024 * 1024;
        $filePath = $this->writeBigFile($fileSize);

        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->statusEnvelope(
                uploadId: '01936fb2-0000-7000-8000-000000000ddd',
                totalParts: $totalParts,
                isTruncated: false,
                nextMarker: 2,
                parts: [2], // part 1 missing
            )),
        ], $captured);

        $client = $this->makeClient($http);
        $this->expectException(GislError::class);
        $this->expectExceptionMessageMatches('/part 1 .* is missing/');
        $client->uploadFile($filePath, new UploadOptions(resumeUploadId: '01936fb2-0000-7000-8000-000000000ddd'));
    }

    public function testRefusesWrongFileSizeMismatch(): void
    {
        $totalParts = 3;
        $fileSize = ($totalParts * self::CHUNK + 1024 * 1024 * 1024); // far exceeds plan
        $filePath = $this->writeBigFile($fileSize);

        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->statusEnvelope(
                uploadId: '01936fb2-0000-7000-8000-000000000eee',
                totalParts: $totalParts,
                isTruncated: false,
                nextMarker: 3,
                parts: [1, 2, 3],
            )),
        ], $captured);

        $client = $this->makeClient($http);
        $this->expectException(GislError::class);
        $this->expectExceptionMessageMatches('/Wrong file for this uploadId/');
        $client->uploadFile($filePath, new UploadOptions(resumeUploadId: '01936fb2-0000-7000-8000-000000000eee'));
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
                multipartThresholdBytes: 8_388_608, // 8 MiB
                multipartRetryBaseMs: 0,
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

    private function s3Response(string $etag): ResponseInterface
    {
        return new Response(200, ['ETag' => $etag], '');
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
                        'size_bytes' => self::CHUNK,
                        'last_modified' => '2026-05-19T12:00:00Z',
                    ],
                    $parts,
                ),
                'next_part_number_marker' => $nextMarker,
                'is_truncated' => $isTruncated,
                'manifest_expires_at' => '2026-05-21T12:00:00Z',
                'recommended_chunk_size' => self::CHUNK,
            ],
        ];
    }

    private function writeBigFile(int $size): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'gisl-resume-test-');
        if ($path === false) {
            self::fail('tempnam failed');
        }
        $this->tempFiles[] = $path;
        $fh = \fopen($path, 'wb');
        if ($fh === false) {
            self::fail("Could not open {$path} for writing");
        }
        try {
            $chunkBuf = \str_repeat('B', 4096);
            $remaining = $size;
            while ($remaining > 0) {
                $writeLen = (int) \min($remaining, 4096);
                $written = \fwrite($fh, \substr($chunkBuf, 0, $writeLen));
                if ($written === false || $written === 0) {
                    self::fail("Short write to {$path}");
                }
                $remaining -= $written;
            }
        } finally {
            \fclose($fh);
        }
        return $path;
    }
}
