<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Service\Tool\RunAugmentation;

/**
 * Everything the AgentRuntime needs to execute one agent run (ADR-101).
 *
 * Built by a UI adapter (the playground controller), a CLI command, or — once
 * the queue epic lands — a worker rehydrating a queued request. Deliberately a
 * plain value object so it can later be serialised for queued execution without
 * changing the runtime interface.
 */
final readonly class AgentRunRequest
{
    /**
     * @param list<ChatMessage|array<string, mixed>> $messages         the initial transcript,
     *                                                                 usually a single user message
     * @param list<string>|null                      $allowedToolNames per-run allow-list; null offers
     *                                                                 the globally-enabled set. The
     *                                                                 loop's gate (ADR-093) is
     *                                                                 authoritative either way.
     * @param int|null                               $maxIterations    requested round cap; null uses the
     *                                                                 loop default. The runtime clamps a
     *                                                                 non-null value to its ceiling
     *                                                                 ({@see AgentRuntime::MAX_ITERATIONS}).
     * @param AiActorContext                         $actor            who initiated the run — the full identity
     *                                                                 (backend user + admin flag + groups, or a
     *                                                                 service account). Persisted with a queued
     *                                                                 run and restored in the worker so a run
     *                                                                 authorises identically whether it executes
     *                                                                 synchronously or on a worker (ADR-083),
     *                                                                 rather than inheriting the worker's absent
     *                                                                 ambient BE user. Also drives the budget
     *                                                                 pre-flight and the run-row attribution.
     */
    public function __construct(
        public LlmConfiguration $configuration,
        public array $messages,
        public AiActorContext $actor,
        public ?array $allowedToolNames = null,
        public ?ToolOptions $options = null,
        public ?int $maxIterations = null,
        public ?RunAugmentation $augmentation = null,
        public bool $captureRaw = false,
    ) {}
}
