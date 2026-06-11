<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\FileFirst;

use Gisl\Sdk\Ergonomic\ArchiveFormat;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\FileFirst\ArchivedRecipe;
use Gisl\Sdk\FileFirst\FileInput;
use Gisl\Sdk\FileFirst\FilesRecipe;
use Gisl\Sdk\FileFirst\RecipeStep;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\GislErgonomicClient;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Fluent `files([...])->archive(...)` (FF3b). N→1 bundle, terminal (no chain).
 */
#[CoversClass(ArchivedRecipe::class)]
final class ArchivedRecipeTest extends TestCase
{
    public function test_archive_lowers_to_one_passthrough_src_per_input_plus_an_archive_job(): void
    {
        $archived = new ArchivedRecipe(
            [FileInput::path('report.pdf'), FileInput::path('hero.jpg'), FileInput::path('narration.mp3')],
            ArchiveFormat::Zip,
            'by_job',
        );

        $payload = $archived->toWorkflowPayload(['f0', 'f1', 'f2'], null);

        self::assertCount(4, $payload->jobs);
        for ($i = 0; $i < 3; $i++) {
            self::assertSame("src_{$i}", $payload->jobs[$i]->id);
            self::assertSame('passthrough', $payload->jobs[$i]->operations[0]->type);
            self::assertSame(['type' => 'upload', 'file_id' => "f{$i}"], $payload->jobs[$i]->source);
        }

        $archiveJob = $payload->jobs[3];
        self::assertSame('archive', $archiveJob->id);
        self::assertNotNull($archiveJob->inputs);
        self::assertCount(3, $archiveJob->inputs);
        self::assertSame(['type' => 'job_output', 'from' => 'src_0'], $archiveJob->inputs[0]['source']);

        self::assertCount(1, $archiveJob->operations);
        self::assertSame('archive', $archiveJob->operations[0]->type);
        self::assertSame(
            ['format' => 'zip', 'folder_structure' => 'by_job'],
            $archiveJob->operations[0]->options,
        );
    }

    public function test_archive_omits_options_when_none_set(): void
    {
        $archived = new ArchivedRecipe([FileInput::path('a.pdf'), FileInput::path('b.pdf')]);
        $payload = $archived->toWorkflowPayload(['f0', 'f1'], null);
        self::assertSame([], $payload->jobs[2]->operations[0]->options);
    }

    public function test_callback_url_is_wired_into_the_payload(): void
    {
        $archived = new ArchivedRecipe([FileInput::path('a.pdf'), FileInput::path('b.pdf')]);
        $payload = $archived->toWorkflowPayload(['f0', 'f1'], 'https://example.com/cb');
        self::assertSame('https://example.com/cb', $payload->callbackUrl);
    }

    public function test_archive_must_be_the_first_op_on_files(): void
    {
        $recipe = new FilesRecipe(
            [FileInput::path('a.pdf'), FileInput::path('b.pdf')],
            [new RecipeStep('compress', ['optimize' => null])],
        );

        $this->expectException(GislConfigError::class);
        $this->expectExceptionMessageMatches('/archive\(\) must be the first operation/');
        $recipe->archive();
    }

    public function test_archive_rejects_fewer_than_two_inputs_before_any_upload(): void
    {
        $captured = [];
        $client = $this->makeClient($this->stubClient([], $captured));

        $this->expectException(GislConfigError::class);
        $this->expectExceptionMessageMatches('/at least 2 inputs/');
        try {
            $client->files([FileInput::path('only.pdf')])->archive()->submit();
        } finally {
            self::assertSame([], $captured, 'no upload may fire when there are too few inputs');
        }
    }

    public function test_archive_rejects_more_than_fifty_inputs_before_any_upload(): void
    {
        $captured = [];
        $client = $this->makeClient($this->stubClient([], $captured));
        $inputs = [];
        for ($i = 0; $i < 51; $i++) {
            $inputs[] = FileInput::path("f-{$i}.pdf");
        }

        $this->expectException(GislConfigError::class);
        $this->expectExceptionMessageMatches('/at most 50 inputs/');
        try {
            $client->files($inputs)->archive()->submit();
        } finally {
            self::assertSame([], $captured, 'no upload may fire when there are too many inputs');
        }
    }

    // -----------------------------------------------------------------

    private function makeClient(ClientInterface $http): GislErgonomicClient
    {
        $factory = new HttpFactory();
        return new GislErgonomicClient(
            config: new GislClientConfig(baseUrl: 'https://api.test.example.com', apiKey: 'test-api-key', multipartConcurrency: 1),
            httpClient: $http,
            requestFactory: $factory,
            streamFactory: $factory,
        );
    }

    /**
     * @param list<ResponseInterface> $queue
     * @param-out list<RequestInterface> $captured
     */
    private function stubClient(array $queue, array &$captured = []): ClientInterface
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
                    throw new \RuntimeException('Stub queue exhausted on ' . $request->getUri());
                }
                return $next;
            }
        };
    }
}
