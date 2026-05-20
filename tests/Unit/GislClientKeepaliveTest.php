<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\Errors\GislError;
use Gisl\Sdk\Errors\GislMultipartSessionAuthRequiredError;
use Gisl\Sdk\Errors\GislMultipartSessionNotFoundError;
use Gisl\Sdk\Errors\GislMultipartSessionOwnershipError;
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
 * SDK-3 (Wb6ebOMM) — keepaliveUpload() tests. Mirrors
 * `packages/typescript/tests/unit/keepalive.test.ts`.
 */
#[CoversClass(GislClient::class)]
final class GislClientKeepaliveTest extends TestCase
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
        $client->keepaliveUpload('');
    }

    public function testPostsAndReturnsNewExpiry(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'upload_id' => 'u1',
                    'manifest_expires_at' => '2026-05-23T12:00:00Z',
                ],
            ]),
        ], $captured);
        $client = $this->makeClient($http);
        $result = $client->keepaliveUpload('u1');
        self::assertSame(['uploadId' => 'u1', 'manifestExpiresAt' => '2026-05-23T12:00:00Z'], $result);
        self::assertCount(1, $captured);
        self::assertSame('POST', $captured[0]->getMethod());
        self::assertSame('/api/uploads/multipart/u1/keepalive', $captured[0]->getUri()->getPath());
    }

    public function testSurfaces404NotFound(): void
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
        $client->keepaliveUpload('gone');
    }

    public function testSurfaces403OwnershipAsTypedError(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(403, [
                'success' => false,
                'error' => 'session belongs to another user',
                'error_type' => 'MULTIPART_SESSION_OWNERSHIP',
            ]),
        ], $captured);
        $client = $this->makeClient($http);
        $this->expectException(GislMultipartSessionOwnershipError::class);
        $client->keepaliveUpload('mp-someone-else');
    }

    public function testSurfaces403AuthRequired(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(403, [
                'success' => false,
                'error' => 'session requires authentication',
                'error_type' => 'MULTIPART_SESSION_AUTH_REQUIRED',
            ]),
        ], $captured);
        $client = $this->makeClient($http);
        $this->expectException(GislMultipartSessionAuthRequiredError::class);
        $client->keepaliveUpload('anon');
    }

    public function testRejectsMalformedResponse(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => ['upload_id' => 'u1'],
            ]),
        ], $captured);
        $client = $this->makeClient($http);
        $this->expectException(GislError::class);
        $this->expectExceptionMessageMatches('/malformed response/');
        $client->keepaliveUpload('u1');
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
