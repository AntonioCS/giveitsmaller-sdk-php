<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\Errors\GislError;
use Gisl\Sdk\Errors\GislMultipartPartCountError;
use Gisl\Sdk\Errors\GislUploadCapExceededError;
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
 * SDK-3 (Wb6ebOMM) — presignParts() client-side validation tests. Mirrors
 * `packages/typescript/tests/unit/presign-parts.test.ts`.
 */
#[CoversClass(GislClient::class)]
final class GislClientPresignPartsTest extends TestCase
{
    private HttpFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new HttpFactory();
    }

    public function testRejectsEmptyUploadId(): void
    {
        $captured = [];
        $client = $this->makeClient($this->stubClient([], $captured));
        $this->expectException(GislError::class);
        $client->presignParts('', [2], 10);
    }

    public function testRejectsEmptyPartNumbers(): void
    {
        $captured = [];
        $client = $this->makeClient($this->stubClient([], $captured));
        $this->expectException(GislError::class);
        $this->expectExceptionMessageMatches('/non-empty/');
        $client->presignParts('u1', [], 10);
    }

    public function testRejectsBatchLargerThan100(): void
    {
        $captured = [];
        $client = $this->makeClient($this->stubClient([], $captured));
        $big = \range(2, 102); // 101 entries
        $this->expectException(GislError::class);
        $this->expectExceptionMessageMatches('/100/');
        $client->presignParts('u1', $big, 200);
    }

    public function testRejectsPartNumber1(): void
    {
        $captured = [];
        $client = $this->makeClient($this->stubClient([], $captured));
        $this->expectException(GislError::class);
        $this->expectExceptionMessageMatches('/Part 1 is sealed/');
        $client->presignParts('u1', [1, 2, 3], 10);
    }

    public function testRejectsPartNumberAboveTotalParts(): void
    {
        $captured = [];
        $client = $this->makeClient($this->stubClient([], $captured));
        $this->expectException(GislError::class);
        $this->expectExceptionMessageMatches('/\[2, 10\]/');
        $client->presignParts('u1', [11], 10);
    }

    public function testRejectsDuplicatePartNumbers(): void
    {
        $captured = [];
        $client = $this->makeClient($this->stubClient([], $captured));
        $this->expectException(GislError::class);
        $this->expectExceptionMessageMatches('/duplicate/');
        $client->presignParts('u1', [2, 3, 2], 10);
    }

    public function testRejectsTotalPartsAbove10000WithTypedError(): void
    {
        $captured = [];
        $client = $this->makeClient($this->stubClient([], $captured));
        try {
            $client->presignParts('u1', [2], 10_001);
            self::fail('Expected GislMultipartPartCountError');
        } catch (GislMultipartPartCountError $e) {
            self::assertSame(10_001, $e->requiredParts);
            self::assertSame(10_000, $e->maxParts);
        }
    }

    public function testRejectsTotalPartsBelow1(): void
    {
        $captured = [];
        $client = $this->makeClient($this->stubClient([], $captured));
        $this->expectException(GislError::class);
        $client->presignParts('u1', [2], 0);
    }

    public function testHappyPathPostsSnakeCaseBody(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'upload_id' => 'u1',
                    'presigned_urls' => [
                        ['part_number' => 2, 'url' => 'https://s3.example.com/p2', 'expires_at' => '2026-05-21T12:00:00Z'],
                        ['part_number' => 5, 'url' => 'https://s3.example.com/p5', 'expires_at' => '2026-05-21T12:00:00Z'],
                    ],
                ],
            ]),
        ], $captured);
        $client = $this->makeClient($http);

        $result = $client->presignParts('u1', [2, 5], 100);
        self::assertSame('u1', $result['uploadId']);
        self::assertCount(2, $result['presignedUrls']);
        self::assertSame(2, $result['presignedUrls'][0]['partNumber']);
        self::assertSame('https://s3.example.com/p2', $result['presignedUrls'][0]['url']);

        self::assertCount(1, $captured);
        $req = $captured[0];
        self::assertSame('POST', $req->getMethod());
        self::assertSame('/api/uploads/multipart/u1/presign', $req->getUri()->getPath());
        $body = (string) $req->getBody();
        $decoded = \json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        self::assertSame(['part_numbers' => [2, 5]], $decoded);
    }

    public function testSurfacesServer422FileTooLargeForMultipartAsTypedCapError(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(422, [
                'success' => false,
                'error' => 'multipart capacity exceeded',
                'error_type' => 'FILE_TOO_LARGE_FOR_MULTIPART',
            ]),
        ], $captured);
        $client = $this->makeClient($http);
        try {
            $client->presignParts('u-big', [2], 100);
            self::fail('Expected GislUploadCapExceededError');
        } catch (GislUploadCapExceededError $e) {
            self::assertSame(GislUploadCapExceededError::KIND_V2_MULTIPART, $e->kind);
            self::assertNull($e->typedPayload);
        }
    }

    public function testAccepts100PartNumbersBoundary(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => ['upload_id' => 'u1', 'presigned_urls' => []],
            ]),
        ], $captured);
        $client = $this->makeClient($http);
        $exactly100 = \range(2, 101); // 100 entries
        $client->presignParts('u1', $exactly100, 200);
        self::assertCount(1, $captured);
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
}
