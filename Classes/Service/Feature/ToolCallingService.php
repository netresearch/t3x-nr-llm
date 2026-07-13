<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Feature;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Service\Budget\AutoPopulatesBeUserUidTrait;
use Netresearch\NrLlm\Service\Budget\BackendUserContextResolverInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ToolOptions;

/**
 * High-level service for tool-calling chat completion.
 *
 * Thin, typed facade over the manager's tool-calling entry points —
 * option handling and dispatch semantics are identical to calling
 * `LlmServiceManagerInterface` directly (the manager keeps owning
 * normalisation, provider resolution and the middleware pipeline).
 *
 * Budget pre-flight (REC #4): when a caller does not set an explicit
 * `beUserUid` on the options, the service consults
 * `BackendUserContextResolverInterface` to find the active backend user
 * and populates the option so the BudgetMiddleware in the pipeline can
 * enforce per-user limits without every caller having to remember the
 * wiring. The resolver injection is optional so unit tests that only
 * care about the messaging path can omit it; in production DI the
 * Symfony container always autowires it from
 * `Configuration/Services.yaml`.
 */
final readonly class ToolCallingService implements ToolCallingServiceInterface
{
    use AutoPopulatesBeUserUidTrait;

    public function __construct(
        private LlmServiceManagerInterface $llmManager,
        private ?BackendUserContextResolverInterface $beUserContextResolver = null,
    ) {}

    public function chatWithTools(array $messages, array $tools, ?ToolOptions $options = null): CompletionResponse
    {
        $options ??= new ToolOptions();
        $options = $this->autoPopulateBeUserUid($options);

        return $this->llmManager->chatWithTools($messages, $tools, $options);
    }

    public function chatWithToolsForConfiguration(array $messages, array $tools, LlmConfiguration $configuration, ?ToolOptions $options = null): CompletionResponse
    {
        $options ??= new ToolOptions();
        $options = $this->autoPopulateBeUserUid($options);

        return $this->llmManager->chatWithToolsForConfiguration($messages, $tools, $configuration, $options);
    }
}
