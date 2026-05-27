<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

/**
 * Sealed marker raised by {@see OperationBuilder::consumeSseToTerminal()}
 * when the server closed the SSE stream cleanly without yielding any of
 * the three terminal-event frames (`workflow.completed` /
 * `workflow.failed` / `workflow.partially_failed`). The
 * {@see OperationBuilder::awaitTerminal()} dispatcher catches this
 * specific class and falls back to polling.
 *
 * Extends `\RuntimeException` for ergonomic stack-tracing, but is NOT
 * part of the public `GislError` tree — callers MUST NOT match against
 * `\RuntimeException` to detect SSE outcomes (`GislError` also extends
 * `\RuntimeException`, so a bare arm would swallow auth/balance/etc.
 * errors). Match this class specifically if you need to introspect.
 *
 * Codex r2 high 93a6f1be1fcd-reaffirmation: prior implementation threw
 * a bare `\RuntimeException` from the SSE consumer and caught
 * `\RuntimeException` in the dispatcher, which silently re-issued
 * doomed requests via the poll fallback for any `GislError` thrown by
 * `streamEvents()`.
 */
final class SseStreamEndedWithoutTerminal extends \RuntimeException
{
}
