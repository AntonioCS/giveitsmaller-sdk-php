<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Ergonomic;

use Gisl\Sdk\Ergonomic\Handle;
use Gisl\Sdk\Ergonomic\OperationBuilder;
use Gisl\Sdk\Ergonomic\SubmitOptions;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\GislErgonomicClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * @phpstan-import-type Captured from OperationBuilderRunTest
 */
#[CoversClass(OperationBuilder::class)]
final class OperationBuilderSubmitTest extends TestCase
{
    public function test_submit_uploads_then_creates_workflow_and_returns_handle(): void
    {
        $tempPath = self::writeTempFile('hello world bytes');

        $captured = [];
        $http = self::stubClient([
            self::jsonResponse(200, [
                'success' => true,
                'data' => [
                    'file_id' => '01936fb1-7bb3-7000-8000-000000000001',
                    'original_name' => 'fixture.bin',
                    'mime_type' => 'application/octet-stream',
                    'size_bytes' => 17,
                ],
            ]),
            self::jsonResponse(201, [
                'success' => true,
                'data' => [
                    'workflow_id' => '01936fb2-0000-7000-8000-0000000000c1',
                    'status' => 'pending',
                    // webhook_secret has a fixed-length 64-char constraint
                    // in the OpenAPI spec; the generated validator rejects
                    // anything shorter or longer.
                    'webhook_secret' => 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789',
                    'created_at' => '2026-05-27T11:00:00Z',
                    'jobs' => [],
                    'delivery_plan' => [
                        'mode' => 'individual',
                        'selection_type' => 'terminal',
                        'outputs' => [],
                        'hidden_outputs' => [],
                    ],
                    'processing_plan' => ['jobs' => []],
                    'warnings' => [],
                ],
            ]),
        ], $captured);

        $client = self::makeClient($http);
        $builder = $client->compress($tempPath, ['quality' => 75, 'format' => 'webp']);
        $handle = $builder->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        $this->assertInstanceOf(Handle::class, $handle);
        $this->assertSame('01936fb2-0000-7000-8000-0000000000c1', $handle->workflowId);
        $this->assertSame(
            'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789',
            $handle->webhookSecret,
        );

        // Exactly two outbound requests: upload + workflow create.
        $this->assertCount(2, $captured);
        $this->assertSame('POST', $captured[0]->getMethod());
        $this->assertStringContainsString('/api/uploads', (string) $captured[0]->getUri());
        $this->assertSame('POST', $captured[1]->getMethod());
        $this->assertStringContainsString('/api/workflows', (string) $captured[1]->getUri());

        // Workflow body MUST carry callback_url + the op options verbatim.
        $body = \json_decode((string) $captured[1]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($body);
        $this->assertSame('https://example.com/cb', $body['callback_url']);
        $this->assertSame('compress', $body['jobs'][0]['operations'][0]['type']);
        $this->assertSame(75, $body['jobs'][0]['operations'][0]['options']['quality']);
        $this->assertSame('webp', $body['jobs'][0]['operations'][0]['options']['format']);
        $this->assertSame('upload', $body['jobs'][0]['source']['type']);
        $this->assertSame('01936fb1-7bb3-7000-8000-000000000001', $body['jobs'][0]['source']['file_id']);
    }

    public function test_submit_handle_omits_webhook_secret_when_server_does_not_return_one(): void
    {
        $tempPath = self::writeTempFile('payload');

        $captured = [];
        $http = self::stubClient([
            self::jsonResponse(200, [
                'success' => true,
                'data' => [
                    'file_id' => '01936fb1-7bb3-7000-8000-000000000002',
                    'original_name' => 'fixture.bin',
                    'mime_type' => 'application/octet-stream',
                    'size_bytes' => 7,
                ],
            ]),
            self::jsonResponse(201, [
                'success' => true,
                'data' => [
                    'workflow_id' => '01936fb2-0000-7000-8000-0000000000c2',
                    'status' => 'pending',
                    'created_at' => '2026-05-27T11:00:00Z',
                    // No webhook_secret field.
                    'jobs' => [],
                    'delivery_plan' => [
                        'mode' => 'individual',
                        'selection_type' => 'terminal',
                        'outputs' => [],
                        'hidden_outputs' => [],
                    ],
                    'processing_plan' => ['jobs' => []],
                    'warnings' => [],
                ],
            ]),
        ], $captured);

        $client = self::makeClient($http);
        $handle = $client->thumbnail($tempPath, ['width' => 320])
            ->submit(new SubmitOptions(webhook: 'https://example.com/cb'));

        $this->assertNull($handle->webhookSecret);
        $this->assertSame(
            ['workflowId' => '01936fb2-0000-7000-8000-0000000000c2'],
            $handle->toArray(),
            'toArray must drop absent optional keys (parity with TS JSON.stringify).',
        );
    }

    private static function writeTempFile(string $bytes): string
    {
        $dir = \sys_get_temp_dir() . '/gisl-ergo-test-' . \bin2hex(\random_bytes(6));
        \mkdir($dir, 0700, true);
        $path = $dir . '/fixture.bin';
        \file_put_contents($path, $bytes);
        return $path;
    }

    private static function makeClient(ClientInterface $http): GislErgonomicClient
    {
        $factory = new HttpFactory();
        return new GislErgonomicClient(
            config: new GislClientConfig(
                baseUrl: 'https://api.test.example.com',
                apiKey: 'test-api-key',
                multipartConcurrency: 1,
            ),
            httpClient: $http,
            requestFactory: $factory,
            streamFactory: $factory,
        );
    }

    /**
     * @param list<ResponseInterface> $queue
     * @param-out list<RequestInterface> $captured
     */
    private static function stubClient(array $queue, array &$captured): ClientInterface
    {
        $captured = [];
        return new class ($queue, $captured) implements ClientInterface {
            /** @var list<ResponseInterface> */
            private array $queue;
            /** @var list<RequestInterface> */
            private array $captured;

            /**
             * @param list<ResponseInterface> $queue
             * @param list<RequestInterface>  $captured
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
                    throw new \RuntimeException(
                        'Stub PSR-18 client: response queue exhausted on request #'
                        . \count($this->captured),
                    );
                }
                return $next;
            }
        };
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function jsonResponse(int $status, array $body): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            \json_encode($body, JSON_THROW_ON_ERROR),
        );
    }
}
