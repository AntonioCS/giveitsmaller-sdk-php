<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Generated\OpenApi\Model\WorkflowDownloadResponse;
use Gisl\Sdk\Errors\GislApiError;
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

#[CoversClass(GislClient::class)]
final class GislClientDownloadsTest extends TestCase
{
    private HttpFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new HttpFactory();
    }

    public function testGetWorkflowDownloadsReturnsHydratedResponse(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'downloads' => [
                        [
                            'job_id' => '01936fb2-0000-7000-8000-000000000bbb',
                            'ref' => 'compressed',
                            'files' => [
                                [
                                    'kind' => 'output',
                                    'url' => 'https://cdn.example.com/jobs/compressed/photo.jpg',
                                    'filename' => 'photo.jpg',
                                    'size_bytes' => 12345,
                                    'mime_type' => 'image/jpeg',
                                    'expires_at' => '2026-12-31T00:00:00Z',
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ], $captured);

        $client = $this->makeClient($http);
        $result = $client->getWorkflowDownloads('wf_01HXY2');

        self::assertInstanceOf(WorkflowDownloadResponse::class, $result);
        self::assertCount(1, $captured);
        self::assertSame('GET', $captured[0]->getMethod());
        self::assertStringEndsWith('/api/workflows/wf_01HXY2/downloads', (string) $captured[0]->getUri());

        $downloads = $result->getDownloads();
        self::assertIsArray($downloads);
        self::assertCount(1, $downloads);
        self::assertSame('01936fb2-0000-7000-8000-000000000bbb', $downloads[0]->getJobId());
        self::assertSame('compressed', $downloads[0]->getRef());
    }

    public function testWorkflowIdIsRawurlencoded(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => ['downloads' => []],
            ]),
        ], $captured);

        $client = $this->makeClient($http);
        $client->getWorkflowDownloads('wf with/slash?and=other#chars');

        // rawurlencode encodes spaces as %20 (NOT '+'), and slashes/?#& fully.
        self::assertStringContainsString(
            '/api/workflows/wf%20with%2Fslash%3Fand%3Dother%23chars/downloads',
            (string) $captured[0]->getUri(),
        );
    }

    public function testApiErrorEnvelopeIsRaisedAsGislApiError(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(404, [
                'success' => false,
                'error' => 'workflow_not_found',
                'message' => 'Workflow does not exist',
            ]),
        ], $captured);

        $client = $this->makeClient($http);

        $this->expectException(GislApiError::class);
        $this->expectExceptionMessage('Workflow does not exist');
        $client->getWorkflowDownloads('wf_missing');
    }

    public function testEmptyDownloadsArrayHydratesAsEmptyList(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => ['downloads' => []],
            ]),
        ], $captured);

        $client = $this->makeClient($http);
        $result = $client->getWorkflowDownloads('wf_pending');

        self::assertInstanceOf(WorkflowDownloadResponse::class, $result);
        $downloads = $result->getDownloads();
        self::assertIsArray($downloads);
        self::assertCount(0, $downloads);
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

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
