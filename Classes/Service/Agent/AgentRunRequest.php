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
use Netresearch\NrLlm\Domain\ValueObject\SkillInvocation;
use Netresearch\NrLlm\Domain\ValueObject\SkillToolAllowList;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Service\Tool\RunAugmentation;

/**
 * Everything AgentRuntime needs to execute one run (ADR-101).
 * Built by UI and CLI adapters or rehydrated by a worker from the queued
 * request. AgentRunRequestCodec serializes this value for queue execution.
 *
 * @api
 */
final readonly class AgentRunRequest
{
    /**
     * @param list<ChatMessage|array<string, mixed>> $messages           the initial transcript,
     *                                                                   usually a single user message
     * @param list<string>|null                      $allowedToolNames   per-run allow-list; null offers
     *                                                                   the globally-enabled set. The
     *                                                                   loop's gate (ADR-093) is
     *                                                                   authoritative either way.
     * @param int|null                               $maxIterations      requested round cap; null uses the
     *                                                                   loop default. The runtime clamps a
     *                                                                   non-null value to its ceiling
     *                                                                   ({@see AgentRuntime::MAX_ITERATIONS}).
     * @param AiActorContext                         $actor              who initiated the run — the full identity
     *                                                                   (backend user + admin flag + groups, or a
     *                                                                   service account). Persisted with a queued
     *                                                                   run and restored in the worker so a run
     *                                                                   authorises identically whether it executes
     *                                                                   synchronously or on a worker (ADR-083),
     *                                                                   rather than inheriting the worker's absent
     *                                                                   ambient BE user. Also drives the budget
     *                                                                   pre-flight and the run-row attribution.
     * @param SkillToolAllowList|null                $skillAllowList     the skill allow-list the run started with
     *                                                                   (ADR-038 item 5). Set for a queued run,
     *                                                                   whose list is resolved when it is
     *                                                                   enqueued; the loop then holds the run to
     *                                                                   it, intersected with the live list. Null
     *                                                                   resolves the list when the loop starts.
     * @param SkillInvocation|null                   $invocation         starts a process skill on this run
     *                                                                   (ADR-214 item 10, ADR-228). The runtime
     *                                                                   checks it at start and at worker pickup
     *                                                                   and composes the approved version.
     * @param string                                 $predecessorRunUuid continues the conversation of that run
     *                                                                   (ADR-228): the runtime derives its process
     *                                                                   pin, invocation and subject record from it.
     *                                                                   '' starts without a predecessor.
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
        public ?SkillToolAllowList $skillAllowList = null,
        public ?SkillInvocation $invocation = null,
        public string $predecessorRunUuid = '',
    ) {}
}
