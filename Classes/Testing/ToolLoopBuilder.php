<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Testing;

use Netresearch\NrLlm\Service\Context\ContextWindowManagerInterface;
use Netresearch\NrLlm\Service\Governance\GovernanceEventRepositoryInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Prompt\PromptSnippetComposer;
use Netresearch\NrLlm\Service\Schema\JsonSchemaValidator;
use Netresearch\NrLlm\Service\Skill\SkillInjectionService;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityServiceInterface;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Psr\Log\LoggerInterface;

/**
 * Fluent test builder for {@see ToolLoopService} (ADR-118).
 *
 * The loop's security collaborators — the composite tool gate and the input
 * schema validator — are required constructor parameters since ADR-118, so a
 * bare `new ToolLoopService(...)` in a test would have to wire them by hand.
 * This builder defaults them to lean stand-ins that reproduce the pre-ADR-118
 * behaviour exactly: a {@see NullToolCallPolicy} over the given registry and
 * availability gate (plus the optional {@see AllowedToolsResolver}), and the
 * real, dependency-free {@see JsonSchemaValidator}. Every collaborator can be
 * overridden through a `with*()` call before {@see self::build()}.
 *
 * Not a DI service: excluded from container autoconfiguration in
 * `Configuration/Services.yaml`. It is a fixture for test suites — the
 * documented migration path for out-of-tree code that constructed the loop
 * directly — never wire it into production.
 */
final class ToolLoopBuilder
{
    private ?ToolCallPolicyInterface $toolPolicy = null;

    private ?AllowedToolsResolver $allowedTools = null;

    private ?JsonSchemaValidator $schemaValidator = null;

    private ?LoggerInterface $logger = null;

    private int $defaultMaxIterations = 5;

    private ?SkillInjectionService $skillInjection = null;

    private ?PromptSnippetComposer $snippetComposer = null;

    private ?ContextWindowManagerInterface $contextWindow = null;

    private ?GovernanceEventRepositoryInterface $governanceEvents = null;

    public function __construct(
        private readonly LlmServiceManagerInterface $mgr,
        private readonly ToolRegistry $registry,
        private readonly ToolAvailabilityServiceInterface $availability,
    ) {}

    /**
     * Replace the default {@see NullToolCallPolicy} with a specific gate.
     */
    public function withToolPolicy(ToolCallPolicyInterface $toolPolicy): self
    {
        $this->toolPolicy = $toolPolicy;

        return $this;
    }

    /**
     * Feed a per-configuration allow-list resolver into the default
     * {@see NullToolCallPolicy}. Ignored when {@see self::withToolPolicy()}
     * supplies a gate of its own.
     */
    public function withAllowedTools(AllowedToolsResolver $allowedTools): self
    {
        $this->allowedTools = $allowedTools;

        return $this;
    }

    public function withSchemaValidator(JsonSchemaValidator $schemaValidator): self
    {
        $this->schemaValidator = $schemaValidator;

        return $this;
    }

    public function withLogger(?LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    public function withDefaultMaxIterations(int $defaultMaxIterations): self
    {
        $this->defaultMaxIterations = $defaultMaxIterations;

        return $this;
    }

    public function withSkillInjection(SkillInjectionService $skillInjection): self
    {
        $this->skillInjection = $skillInjection;

        return $this;
    }

    public function withSnippetComposer(PromptSnippetComposer $snippetComposer): self
    {
        $this->snippetComposer = $snippetComposer;

        return $this;
    }

    public function withContextWindow(ContextWindowManagerInterface $contextWindow): self
    {
        $this->contextWindow = $contextWindow;

        return $this;
    }

    public function withGovernanceEvents(GovernanceEventRepositoryInterface $governanceEvents): self
    {
        $this->governanceEvents = $governanceEvents;

        return $this;
    }

    /**
     * Build a fully-wired {@see ToolLoopService}.
     */
    public function build(): ToolLoopService
    {
        return new ToolLoopService(
            $this->mgr,
            $this->registry,
            $this->toolPolicy ?? new NullToolCallPolicy($this->registry, $this->availability, $this->allowedTools),
            $this->schemaValidator ?? new JsonSchemaValidator(),
            $this->logger,
            $this->defaultMaxIterations,
            $this->skillInjection,
            $this->snippetComposer,
            $this->contextWindow,
            $this->governanceEvents,
        );
    }
}
