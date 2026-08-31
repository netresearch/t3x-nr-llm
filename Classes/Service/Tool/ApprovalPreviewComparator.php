<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Whether an approved turn still writes against the state its approver was
 * shown (ADR-184).
 *
 * The rule is one comparison: {@see SuspendedRunState::$callPreviews} holds the
 * bounded lines every previewing tool produced at suspend (ADR-136), so the
 * persisted preview IS what the approval is bound to. Re-run the preview, bound
 * it identically, compare. Nothing else is stored and no tool is asked anything
 * new.
 *
 * It lives beside {@see ToolLoopService} rather than inside it because the loop
 * is already the largest class in this namespace, and because the rule has one
 * subject — the previews — while the loop has many.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class ApprovalPreviewComparator
{
    /** How much of a preview is persisted and therefore compared (ADR-136). */
    private const MAX_LINES = 20;

    private const MAX_LINE_LENGTH = 500;

    /**
     * Shown when the tool that produced a persisted preview can no longer be
     * asked what it would do.
     */
    private const UNAVAILABLE = 'This tool is no longer available, so what the call would do now cannot be compared with what you were shown.';

    public function __construct(
        private ToolRegistry $registry,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * Cut a preview down to what is persisted: at most twenty lines, each at
     * most 500 characters, whitespace collapsed.
     *
     * Both sides of the comparison go through here, so a preview that overflowed
     * its cap does not stale on its own overflow marker.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    public function bound(array $lines): array
    {
        $bounded = [];
        foreach (array_slice($lines, 0, self::MAX_LINES) as $line) {
            $bounded[] = mb_substr(trim(preg_replace('/\s+/u', ' ', $line) ?? $line), 0, self::MAX_LINE_LENGTH);
        }

        if (count($lines) > self::MAX_LINES) {
            $bounded[] = sprintf('… and %d more line(s), not shown.', count($lines) - self::MAX_LINES);
        }

        return $bounded;
    }

    /**
     * Re-run the previews of an approved turn and report whether any of them
     * moved.
     *
     * Returns null when every call still shows what the approver was shown — the
     * normal case, and the only one that executes. Otherwise it returns the
     * state to suspend on instead: the same turn, the CURRENT previews, and the
     * indexes that changed.
     *
     * Two cases deliberately do NOT bind. A call with no persisted preview:
     * nothing was shown, so there is nothing for the approval to be a decision
     * about, and a tool without {@see ToolPreviewInterface} keeps the behaviour
     * it had. And a preview that FAILED at suspend: the card told the approver
     * they were deciding blind, and binding them to the text of an exception
     * would bind them to nothing useful.
     *
     * @param list<ToolCall> $calls
     * @param list<string>   $offered
     */
    public function compare(SuspendedRunState $state, array $calls, array $offered, ToolExecutionContext $context): ?SuspendedRunState
    {
        $shown = [];
        foreach ($state->callPreviews as $preview) {
            if (!$preview['failed']) {
                $shown[$preview['index']] = $preview;
            }
        }

        if ($shown === []) {
            return null;
        }

        $previews = [];
        $stale    = [];
        foreach ($calls as $index => $call) {
            $before = $shown[$index] ?? null;
            if ($before === null) {
                continue;
            }

            $now = $this->recompute($call, $offered, $context, $before);
            $previews[] = $now;
            if ($now['lines'] !== $before['lines']) {
                $stale[] = $index;
            }
        }

        return $stale === [] ? null : $this->restale($state, $previews, $stale);
    }

    /**
     * What one call's preview says NOW, or the reason it cannot say anything.
     *
     * A call that is not offered is not re-previewed: reading under an authority
     * the run no longer has is worse than not reading. Its ORIGINAL preview is
     * returned unchanged, so it compares equal and — because the caller rebuilds
     * the whole list — is not silently unbound for the next approval.
     *
     * A re-preview that THROWS is the opposite of a preview that failed at
     * suspend: something WAS shown and can no longer be compared, so it stales.
     * The exception TEXT never reaches the persisted state, as in
     * {@see ToolLoopService::invoke()}; an exception body may carry DBAL
     * credentials.
     *
     * @param list<string>                                                       $offered
     * @param array{index: int, tool: string, lines: list<string>, failed: bool} $before
     *
     * @return array{index: int, tool: string, lines: list<string>, failed: bool}
     */
    private function recompute(ToolCall $call, array $offered, ToolExecutionContext $context, array $before): array
    {
        if (!in_array($call->name, $offered, true)) {
            return $before;
        }

        $tool = $this->registry->get($call->name);
        if (!$tool instanceof ToolPreviewInterface) {
            return ['index' => $before['index'], 'tool' => $call->name, 'lines' => [self::UNAVAILABLE], 'failed' => true];
        }

        try {
            $lines = $this->bound(array_values(array_filter($tool->previewCall($call->arguments, $context), is_string(...))));
        } catch (Throwable $e) {
            $this->logger?->warning('Re-preview failed at resume; the approval is refused rather than executed blind.', ['tool' => $call->name, 'exception' => $e]);

            return [
                'index'  => $before['index'],
                'tool'   => $call->name,
                'lines'  => [sprintf('The preview for this call failed (%s), so what it would do now cannot be compared with what you were shown.', $e::class)],
                'failed' => true,
            ];
        }

        return [
            'index'  => $before['index'],
            'tool'   => $call->name,
            'lines'  => $lines === [] ? ['The tool produced no preview for this call.'] : $lines,
            'failed' => false,
        ];
    }

    /**
     * The same suspension again, carrying the CURRENT previews and the calls
     * that moved.
     *
     * Everything else is the state as it was: same transcript, same pending
     * calls, same allow-list, same options, same counters. The approver decides
     * the same turn a second time, against a picture that is no longer stale.
     *
     * The stale marker is a field of its own rather than an extra preview line,
     * and that is load-bearing: a notice inside the lines would join the next
     * comparison, so a second approval against an unchanged record would find it
     * missing and bounce again, forever.
     *
     * @param list<array{index: int, tool: string, lines: list<string>, failed: bool}> $previews
     * @param list<int>                                                                $stale
     */
    private function restale(SuspendedRunState $state, array $previews, array $stale): SuspendedRunState
    {
        return new SuspendedRunState(
            $state->messages,
            $state->pendingCalls,
            $state->iterations,
            $state->promptTokens,
            $state->completionTokens,
            $state->allowedToolNames,
            $state->options,
            $state->inputToolName,
            $state->inputSchema,
            $previews,
            $state->forcedSnippetUids,
            $state->forcedSkillUids,
            $stale,
        );
    }
}
