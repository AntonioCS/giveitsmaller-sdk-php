<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\FileFirst;

use Gisl\Sdk\Errors\GislItemFailedError;
use Gisl\Sdk\Errors\GislNoSuchKeyError;
use Gisl\Sdk\Errors\GislSinkError;
use Gisl\Sdk\FileFirst\Downloader;
use Gisl\Sdk\FileFirst\ItemFailure;
use Gisl\Sdk\FileFirst\ItemResult;
use Gisl\Sdk\FileFirst\OutputFile;
use Gisl\Sdk\FileFirst\RunResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * In-memory streaming downloader stub: records (url -> destPath) calls and
 * writes a marker file so toFile/downloadTo can be asserted without network.
 */
final class RecordingDownloader implements Downloader
{
    /** @var list<array{url: string, dest: string}> */
    public array $calls = [];

    public function downloadTo(string $url, string $destPath): void
    {
        $this->calls[] = ['url' => $url, 'dest' => $destPath];
    }
}

final class RunResultTest extends TestCase
{
    private function out(string $name): OutputFile
    {
        return new OutputFile(
            url: "https://cdn.example.com/{$name}",
            filename: $name,
            sizeBytes: 10,
            operation: 'compress',
        );
    }

    #[Test]
    public function url_sugar_is_set_for_exactly_one_output(): void
    {
        $r = new RunResult('wf1', 'completed', [$this->out('a.jpg')], [], []);
        self::assertSame('https://cdn.example.com/a.jpg', $r->url);
    }

    #[Test]
    public function url_sugar_is_null_for_zero_or_many_outputs(): void
    {
        self::assertNull((new RunResult('wf1', 'completed', [], [], []))->url);
        self::assertNull(
            (new RunResult('wf1', 'completed', [$this->out('a.jpg'), $this->out('b.jpg')], [], []))->url,
        );
    }

    #[Test]
    public function ok_is_true_only_when_failed_is_empty(): void
    {
        self::assertTrue((new RunResult('wf1', 'completed', [], [], []))->ok);
        $failed = [new ItemFailure('bad', new GislItemFailedError('bad', 'failed', 'boom'))];
        self::assertFalse((new RunResult('wf1', 'failed', [], [], $failed))->ok);
    }

    #[Test]
    public function by_key_returns_matching_succeeded_item(): void
    {
        $hero = new ItemResult('hero', [$this->out('hero.jpg')]);
        $r = new RunResult('wf1', 'completed', [$this->out('hero.jpg')], [$hero], []);
        self::assertSame($hero, $r->byKey('hero'));
    }

    #[Test]
    public function by_key_throws_on_missing_key(): void
    {
        $r = new RunResult('wf1', 'completed', [$this->out('a.jpg')], [new ItemResult(null, [$this->out('a.jpg')])], []);
        $this->expectException(GislNoSuchKeyError::class);
        $r->byKey('nope');
    }

    #[Test]
    public function to_file_streams_the_single_output(): void
    {
        $dl = new RecordingDownloader();
        $r = new RunResult('wf1', 'completed', [$this->out('a.jpg')], [], [], $dl);
        $r->toFile('/tmp/out.jpg');
        self::assertSame([['url' => 'https://cdn.example.com/a.jpg', 'dest' => '/tmp/out.jpg']], $dl->calls);
    }

    #[Test]
    public function to_file_throws_not_single_output_for_zero_or_many(): void
    {
        $dl = new RecordingDownloader();
        $r = new RunResult('wf1', 'completed', [], [], [], $dl);
        try {
            $r->toFile('/tmp/out.jpg');
            self::fail('expected GislSinkError');
        } catch (GislSinkError $e) {
            self::assertSame('not_single_output', $e->getReason());
        }
        self::assertSame([], $dl->calls);
    }

    #[Test]
    public function download_to_writes_each_output_in_order(): void
    {
        $dl = new RecordingDownloader();
        $r = new RunResult('wf1', 'completed', [$this->out('a.jpg'), $this->out('b.jpg')], [], [], $dl);
        $manifest = $r->downloadTo('/tmp/out');
        self::assertSame(
            ['/tmp/out' . \DIRECTORY_SEPARATOR . 'a.jpg', '/tmp/out' . \DIRECTORY_SEPARATOR . 'b.jpg'],
            $manifest->paths,
        );
        self::assertCount(2, $dl->calls);
    }

    #[Test]
    public function download_to_fail_on_partial_throws_when_failures_present(): void
    {
        $dl = new RecordingDownloader();
        $failed = [new ItemFailure('bad', new GislItemFailedError('bad', 'partially_failed', 'boom'))];
        $r = new RunResult('wf1', 'partially_failed', [$this->out('a.jpg')], [], $failed, $dl);
        try {
            $r->downloadTo('/tmp/out', failOnPartial: true);
            self::fail('expected GislSinkError');
        } catch (GislSinkError $e) {
            self::assertSame('partial_failure', $e->getReason());
        }
        self::assertSame([], $dl->calls);
    }

    #[Test]
    public function download_to_default_downloads_successes_despite_failures(): void
    {
        $dl = new RecordingDownloader();
        $failed = [new ItemFailure('bad', new GislItemFailedError('bad', 'partially_failed', 'boom'))];
        $r = new RunResult('wf1', 'partially_failed', [$this->out('a.jpg'), $this->out('b.jpg')], [], $failed, $dl);
        $manifest = $r->downloadTo('/tmp/out');   // failOnPartial defaults false
        self::assertCount(2, $manifest->paths);
        self::assertCount(2, $dl->calls);
    }

    #[Test]
    public function download_to_does_not_double_separator_on_trailing_slash(): void
    {
        $dl = new RecordingDownloader();
        $r = new RunResult('wf1', 'completed', [$this->out('a.jpg')], [], [], $dl);
        $manifest = $r->downloadTo('/tmp/out/');
        self::assertSame(['/tmp/out' . \DIRECTORY_SEPARATOR . 'a.jpg'], $manifest->paths);
    }

    #[Test]
    public function download_to_strips_path_traversal_from_filename(): void
    {
        $dl = new RecordingDownloader();
        $evil = new OutputFile(
            url: 'https://cdn.example.com/x',
            filename: '../../etc/passwd',
            sizeBytes: 1,
            operation: 'compress',
        );
        $r = new RunResult('wf1', 'completed', [$evil], [], [], $dl);
        $manifest = $r->downloadTo('/tmp/out');
        // basename() reduces "../../etc/passwd" to "passwd" — stays in /tmp/out.
        self::assertSame(['/tmp/out' . \DIRECTORY_SEPARATOR . 'passwd'], $manifest->paths);
    }

    #[Test]
    public function download_to_throws_on_duplicate_basename_before_writing(): void
    {
        $dl = new RecordingDownloader();
        // Two outputs whose basenames collide ("a.jpg") — would overwrite.
        $a = new OutputFile(url: 'https://cdn.example.com/1', filename: 'a.jpg', sizeBytes: 1, operation: 'compress');
        $b = new OutputFile(url: 'https://cdn.example.com/2', filename: 'sub/a.jpg', sizeBytes: 1, operation: 'compress');
        $r = new RunResult('wf1', 'completed', [$a, $b], [], [], $dl);
        try {
            $r->downloadTo('/tmp/out');
            self::fail('expected GislSinkError');
        } catch (GislSinkError $e) {
            self::assertSame('duplicate_filename', $e->getReason());
        }
        // Fails BEFORE any write.
        self::assertSame([], $dl->calls);
    }

    #[Test]
    public function download_to_throws_on_case_insensitive_basename_collision(): void
    {
        $dl = new RecordingDownloader();
        $a = new OutputFile(url: 'https://cdn.example.com/1', filename: 'a.jpg', sizeBytes: 1, operation: 'compress');
        $b = new OutputFile(url: 'https://cdn.example.com/2', filename: 'A.JPG', sizeBytes: 1, operation: 'compress');
        $r = new RunResult('wf1', 'completed', [$a, $b], [], [], $dl);
        try {
            $r->downloadTo('/tmp/out');
            self::fail('expected GislSinkError');
        } catch (GislSinkError $e) {
            self::assertSame('duplicate_filename', $e->getReason());
        }
        self::assertSame([], $dl->calls);
    }

    #[Test]
    public function download_to_rejects_empty_directory(): void
    {
        $dl = new RecordingDownloader();
        $r = new RunResult('wf1', 'completed', [$this->out('a.jpg')], [], [], $dl);
        try {
            $r->downloadTo('');
            self::fail('expected GislSinkError');
        } catch (GislSinkError $e) {
            self::assertSame('invalid_directory', $e->getReason());
        }
        self::assertSame([], $dl->calls);
    }

    #[Test]
    public function download_to_throws_downloader_unavailable_when_none_bound(): void
    {
        $r = new RunResult('wf1', 'completed', [$this->out('a.jpg')], [], []);
        try {
            $r->downloadTo('/tmp/out');
            self::fail('expected GislSinkError');
        } catch (GislSinkError $e) {
            self::assertSame('downloader_unavailable', $e->getReason());
        }
    }

    #[Test]
    public function by_key_throws_for_keyless_run(): void
    {
        $r = new RunResult('wf1', 'completed', [$this->out('a.jpg')], [new ItemResult(null, [$this->out('a.jpg')])], []);
        $this->expectException(GislNoSuchKeyError::class);
        $r->byKey('anything');
    }

    #[Test]
    public function to_array_omits_url_for_multi_output(): void
    {
        $r = new RunResult('wf1', 'completed', [$this->out('a.jpg'), $this->out('b.jpg')], [], []);
        self::assertArrayNotHasKey('url', $r->toArray());
    }

    #[Test]
    public function to_array_shape_with_failure_matches_cross_language_golden(): void
    {
        $ok = new ItemResult('good', [$this->out('good.jpg')]);
        $failed = [new ItemFailure('bad', new GislItemFailedError('bad', 'failed', 'boom', 'codec_failed'))];
        $r = new RunResult('wf1', 'partially_failed', [$this->out('good.jpg')], [$ok], $failed);
        self::assertSame(
            [
                'workflowId' => 'wf1',
                'state' => 'partially_failed',
                'ok' => false,
                'url' => 'https://cdn.example.com/good.jpg',
                'artifacts' => [
                    ['url' => 'https://cdn.example.com/good.jpg', 'filename' => 'good.jpg', 'sizeBytes' => 10, 'operation' => 'compress'],
                ],
                'succeeded' => [
                    ['key' => 'good', 'outputs' => [
                        ['url' => 'https://cdn.example.com/good.jpg', 'filename' => 'good.jpg', 'sizeBytes' => 10, 'operation' => 'compress'],
                    ]],
                ],
                // failed[] is now {key, error, state, errorMessage?, errorCode?} — the
                // error string is the GislItemFailedError message; the two optional
                // keys appear in fixed order AFTER state.
                'failed' => [
                    ['key' => 'bad', 'error' => 'failed: boom', 'state' => 'failed', 'errorMessage' => 'boom', 'errorCode' => 'codec_failed'],
                ],
            ],
            $r->toArray(),
        );
    }

    #[Test]
    public function failed_item_error_is_a_typed_gisl_item_failed_error_carrying_state_and_code(): void
    {
        // The narrowed `ItemFailure::$error` exposes the structured failure so a
        // caller can branch WITHOUT string-parsing the message.
        $err = new GislItemFailedError('bad', 'failed', 'codec exploded', 'codec_failed');
        $r = new RunResult('wf1', 'failed', [], [], [new ItemFailure('bad', $err)]);

        self::assertInstanceOf(GislItemFailedError::class, $r->failed[0]->error);
        self::assertSame('bad', $r->failed[0]->error->key);
        self::assertSame('failed', $r->failed[0]->error->state);
        self::assertSame('codec exploded', $r->failed[0]->error->errorMessage);
        self::assertSame('codec_failed', $r->failed[0]->error->errorCode);
        // The message preserves the pre-typed "{state}: {errorMessage}" string.
        self::assertSame('failed: codec exploded', $r->failed[0]->error->getMessage());
    }

    #[Test]
    public function to_array_omits_error_message_and_code_for_a_bare_state_failure(): void
    {
        // A cancel/expire terminal carries only the bare $state — both optional
        // keys are OMITTED entirely (never emitted as `=> null`).
        $err = new GislItemFailedError(null, 'cancelled');
        $r = new RunResult('wf1', 'cancelled', [], [], [new ItemFailure(null, $err)]);

        $failedEntry = $r->toArray()['failed'][0];
        self::assertSame(
            ['key' => null, 'error' => 'cancelled', 'state' => 'cancelled'],
            $failedEntry,
        );
        self::assertArrayNotHasKey('errorMessage', $failedEntry);
        self::assertArrayNotHasKey('errorCode', $failedEntry);
    }

    #[Test]
    public function to_array_omits_only_the_code_when_message_present_without_code(): void
    {
        // A failure with an errorMessage but no errorCode keeps `errorMessage`
        // and omits `errorCode` only — proving each optional key is independent.
        $err = new GislItemFailedError('bad', 'failed', 'no code here');
        $r = new RunResult('wf1', 'failed', [], [], [new ItemFailure('bad', $err)]);

        $failedEntry = $r->toArray()['failed'][0];
        self::assertSame(
            ['key' => 'bad', 'error' => 'failed: no code here', 'state' => 'failed', 'errorMessage' => 'no code here'],
            $failedEntry,
        );
        self::assertArrayNotHasKey('errorCode', $failedEntry);
    }

    #[Test]
    public function sinks_throw_downloader_unavailable_when_none_bound(): void
    {
        $r = new RunResult('wf1', 'completed', [$this->out('a.jpg')], [], []);
        try {
            $r->toFile('/tmp/out.jpg');
            self::fail('expected GislSinkError');
        } catch (GislSinkError $e) {
            self::assertSame('downloader_unavailable', $e->getReason());
        }
    }

    #[Test]
    public function to_array_shape_matches_cross_language_golden(): void
    {
        $hero = new ItemResult('hero', [$this->out('hero.jpg')]);
        $r = new RunResult('wf1', 'completed', [$this->out('hero.jpg')], [$hero], []);
        self::assertSame(
            [
                'workflowId' => 'wf1',
                'state' => 'completed',
                'ok' => true,
                'url' => 'https://cdn.example.com/hero.jpg',
                'artifacts' => [
                    ['url' => 'https://cdn.example.com/hero.jpg', 'filename' => 'hero.jpg', 'sizeBytes' => 10, 'operation' => 'compress'],
                ],
                'succeeded' => [
                    ['key' => 'hero', 'outputs' => [
                        ['url' => 'https://cdn.example.com/hero.jpg', 'filename' => 'hero.jpg', 'sizeBytes' => 10, 'operation' => 'compress'],
                    ]],
                ],
                'failed' => [],
            ],
            $r->toArray(),
        );
    }
}
