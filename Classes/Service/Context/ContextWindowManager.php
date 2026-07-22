<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Context;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ContextFitResult;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Keeps an agent-loop transcript within the model's context window by dropping
 * oldest whole tool-call turns (ADR-107).
 *
 * Stateful per run: it seeds a conservative calibration factor and only ever
 * grows it toward the real prompt-token counts reported by each provider call,
 * so the estimate always errs high and never under-prunes into an overflow.
 * The pruning is turn-atomic — an assistant tool-call message and all its
 * tool_result replies are kept or dropped together — so the tool-call/tool-
 * result pairing the provider requires is never broken, and the leading
 * system/task messages and the newest turn are always preserved.
 */
final class ContextWindowManager implements ContextWindowManagerInterface
{
    /** Applied when the model's context length is unknown — never leave a run unprotected. */
    private const UNKNOWN_WINDOW_FALLBACK = 8192;

    /** The first (pre-ground-truth) send is already over-estimated by this factor. */
    private const CALIBRATION_SEED = 1.15;

    /** Fraction of the window held back on top of the response reserve. */
    private const SAFETY_FRACTION = 0.03;

    private float $calibration = self::CALIBRATION_SEED;

    /** Estimate of the exact payload approved for the previous send; null before the first. */
    private ?int $lastSentEstimate = null;

    public function __construct(
        private readonly TranscriptEstimator $estimator = new TranscriptEstimator(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function fit(
        array $messages,
        LlmConfiguration $configuration,
        ?ChatOptions $options,
        ?UsageStatistics $lastUsage,
        array $toolSpecs = [],
    ): ContextFitResult {
        // A null $lastUsage marks the first send of a run's loop. Reset the
        // per-run calibration state here so a single shared manager instance
        // (ToolLoopService is a shared service) never carries one run's
        // calibration into the next.
        if ($lastUsage === null) {
            $this->calibration      = self::CALIBRATION_SEED;
            $this->lastSentEstimate = null;
        }

        $ctx = $configuration->getLlmModel()?->getContextLength() ?? 0;
        if ($ctx <= 0) {
            $ctx = self::UNKNOWN_WINDOW_FALLBACK;
        }

        $this->recalibrate($lastUsage);

        $budget = $ctx - $this->reserve($options, $configuration, $ctx) - (int)ceil($ctx * self::SAFETY_FRACTION);
        if ($budget <= 0) {
            // Misconfiguration (reserve larger than window): defer to the provider.
            $this->logger->warning('ContextWindow: non-positive budget, deferring to provider', ['contextLength' => $ctx]);

            return $this->passthrough($messages);
        }

        // The system prompt is prepended by MessageShaper AFTER fit() on the
        // public path (ADR-093), so when the transcript has no leading system
        // message its size must still be counted against the wire.
        $systemPromptTokens = $this->missingSystemPromptTokens($messages, $configuration);
        $estimate           = fn(array $msgs): int => $this->estimator->estimate($msgs, $toolSpecs, $this->calibration) + $systemPromptTokens;

        [$head, $turns] = $this->partition($messages);

        if ($estimate($messages) <= $budget) {
            $result = $this->passthrough($messages, $estimate($messages), $budget);
        } else {
            $result = $this->drop($head, $turns, $budget, $estimate);
        }

        // Never emit a known-orphaned request: if pruning somehow produced a
        // broken pairing, defer to the provider rather than send a 4xx.
        if (!$this->isPairingValid($result->messages)) {
            $this->logger->warning('ContextWindow: pruned array failed the pairing guard, deferring to provider');

            return $this->passthrough($messages);
        }

        // Remember what we approved so the next recalibrate() divides real usage
        // against THIS estimate, not the (grown) next transcript.
        $this->lastSentEstimate = $result->estimatedTokens;

        return $result;
    }

    private function recalibrate(?UsageStatistics $lastUsage): void
    {
        if ($lastUsage === null || $this->lastSentEstimate === null || $this->lastSentEstimate <= 0) {
            return;
        }
        $ratio = $lastUsage->promptTokens / $this->lastSentEstimate;
        // Monotone up: never trust an estimate below observed reality.
        $this->calibration = max($this->calibration, $ratio);
    }

    private function reserve(?ChatOptions $options, LlmConfiguration $configuration, int $ctx): int
    {
        $optionMax = $options?->getMaxTokens() ?? 0;
        if ($optionMax > 0) {
            return $optionMax;
        }
        $modelMax = $configuration->getLlmModel()?->getMaxOutputTokens() ?? 0;
        if ($modelMax > 0) {
            return $modelMax;
        }

        return max(1000, (int)ceil($ctx * 0.02));
    }

    /**
     * @param list<ChatMessage|array<string, mixed>> $messages
     */
    private function missingSystemPromptTokens(array $messages, LlmConfiguration $configuration): int
    {
        if (isset($messages[0]) && $this->roleOf($messages[0]) === 'system') {
            return 0;
        }
        $systemPrompt = $configuration->getSystemPrompt();
        if ($systemPrompt === '') {
            return 0;
        }

        return $this->estimator->estimate([ChatMessage::system($systemPrompt)], [], $this->calibration);
    }

    /**
     * @param list<ChatMessage|array<string, mixed>> $messages
     *
     * @return array{0: list<ChatMessage|array<string, mixed>>, 1: list<list<ChatMessage|array<string, mixed>>>}
     */
    private function partition(array $messages): array
    {
        $head     = [];
        $count    = count($messages);
        $index    = 0;
        $seenUser = false;
        // HEAD: the leading messages up to AND INCLUDING the first user turn (the
        // system run plus any injected preamble). The task is never droppable.
        for (; $index < $count; ++$index) {
            $head[] = $messages[$index];
            if ($this->roleOf($messages[$index]) === 'user') {
                $seenUser = true;
                ++$index;
                break;
            }
        }
        if (!$seenUser) {
            return [$head, []];
        }

        // TURNS: everything after the first user, a new turn opening on each
        // assistant message. A pre-assistant fragment attaches to the open turn.
        $turns   = [];
        $current = null;
        for (; $index < $count; ++$index) {
            $message = $messages[$index];
            if ($this->roleOf($message) === 'assistant') {
                if ($current !== null) {
                    $turns[] = $current;
                }
                $current = [$message];
            } elseif ($current === null) {
                $current = [$message];
            } else {
                $current[] = $message;
            }
        }
        if ($current !== null) {
            $turns[] = $current;
        }

        return [$head, $turns];
    }

    /**
     * @param list<ChatMessage|array<string, mixed>>                $head
     * @param list<list<ChatMessage|array<string, mixed>>>          $turns
     * @param callable(list<ChatMessage|array<string, mixed>>): int $estimate
     */
    private function drop(array $head, array $turns, int $budget, callable $estimate): ContextFitResult
    {
        $kept    = $turns;
        $dropped = 0;
        // Drop oldest whole turns while it still overflows; NEVER drop the newest
        // turn, so the output is never empty or system-only.
        while (count($kept) > 1 && $estimate($this->assemble($head, $kept)) > $budget) {
            array_shift($kept);
            ++$dropped;
        }

        $messages = $this->assemble($head, $kept);
        $estimated = $estimate($messages);
        $overflow  = $estimated > $budget;

        return new ContextFitResult(
            messages: $messages,
            pruned: $dropped > 0 || $overflow,
            droppedTurns: $dropped,
            keptTurns: count($kept),
            estimatedTokens: $estimated,
            budget: $budget,
            overflowAtFloor: $overflow,
            calibration: $this->calibration,
        );
    }

    /**
     * @param list<ChatMessage|array<string, mixed>>       $head
     * @param list<list<ChatMessage|array<string, mixed>>> $turns
     *
     * @return list<ChatMessage|array<string, mixed>>
     */
    private function assemble(array $head, array $turns): array
    {
        $out = $head;
        foreach ($turns as $turn) {
            foreach ($turn as $message) {
                $out[] = $message;
            }
        }

        return $out;
    }

    /**
     * @param list<ChatMessage|array<string, mixed>> $messages
     */
    private function passthrough(array $messages, int $estimated = 0, int $budget = 0): ContextFitResult
    {
        [, $turns] = $this->partition($messages);

        return new ContextFitResult(
            messages: $messages,
            pruned: false,
            droppedTurns: 0,
            keptTurns: count($turns),
            estimatedTokens: $estimated,
            budget: $budget,
            overflowAtFloor: false,
            calibration: $this->calibration,
        );
    }

    /**
     * A cheap post-fit backstop: every tool_result must follow an assistant turn
     * that declared its tool_call_id. (An assistant tool-call turn WITHOUT its
     * replies is the valid newest-pending turn, so the reverse is not checked.).
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     */
    private function isPairingValid(array $messages): bool
    {
        $openIds = [];
        foreach ($messages as $message) {
            $data = $this->normalise($message);
            $role = is_string($data['role'] ?? null) ? $data['role'] : '';
            if ($role === 'assistant') {
                $toolCalls = is_array($data['tool_calls'] ?? null) ? $data['tool_calls'] : [];
                foreach ($toolCalls as $call) {
                    if (is_array($call) && is_string($call['id'] ?? null)) {
                        $openIds[$call['id']] = true;
                    }
                }
            } elseif ($role === 'tool') {
                $id = is_string($data['tool_call_id'] ?? null) ? $data['tool_call_id'] : '';
                if ($id === '' || !isset($openIds[$id])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param ChatMessage|array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    private function normalise(ChatMessage|array $message): array
    {
        return $message instanceof ChatMessage ? $message->toArray() : $message;
    }

    /**
     * @param ChatMessage|array<string, mixed> $message
     */
    private function roleOf(ChatMessage|array $message): string
    {
        $data = $this->normalise($message);

        return is_string($data['role'] ?? null) ? $data['role'] : '';
    }
}
