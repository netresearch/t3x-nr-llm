<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Testing;

use LogicException;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Feature\ToolCallingServiceInterface;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Throwable;

/**
 * Consumer-facing test double for {@see ToolCallingServiceInterface}.
 *
 * Ships in the runtime-autoloaded `Netresearch\NrLlm\Testing\` namespace so
 * downstream extensions can type-hint their unit tests against a maintained
 * fake instead of hand-rolling one that fatals whenever the interface grows —
 * the failure mode ADR-051 records for nr_ai_search. Implementing the real
 * interface means PHPStan keeps this double in sync with the production
 * contract.
 *
 * Queue one {@see CompletionResponse} per expected call in {@see $responses};
 * they are returned in FIFO order across both methods. Every call is captured
 * in the matching `*Calls` array for assertions. Set {@see $throwable} to make
 * the next call throw instead of returning a queued response.
 *
 * Not a DI service: excluded from container autoconfiguration in
 * `Configuration/Services.yaml`. It is a fixture for consumer test suites,
 * never wire it into production.
 */
final class FakeToolCallingService implements ToolCallingServiceInterface
{
    /**
     * Responses returned in FIFO order, one per call across both methods.
     *
     * @var list<CompletionResponse>
     */
    public array $responses = [];

    /** @var list<array{messages: list<ChatMessage|array<string, mixed>>, tools: list<ToolSpec|array<string, mixed>>, options: ?ToolOptions}> */
    public array $chatWithToolsCalls = [];

    /** @var list<array{messages: list<ChatMessage|array<string, mixed>>, tools: list<ToolSpec|array<string, mixed>>, configuration: LlmConfiguration, options: ?ToolOptions}> */
    public array $chatWithToolsForConfigurationCalls = [];

    /** When set, the next call throws this instead of returning a response. */
    public ?Throwable $throwable = null;

    public function chatWithTools(array $messages, array $tools, ?ToolOptions $options = null): CompletionResponse
    {
        $this->chatWithToolsCalls[] = ['messages' => $messages, 'tools' => $tools, 'options' => $options];

        return $this->next(__FUNCTION__);
    }

    public function chatWithToolsForConfiguration(array $messages, array $tools, LlmConfiguration $configuration, ?ToolOptions $options = null): CompletionResponse
    {
        $this->chatWithToolsForConfigurationCalls[] = ['messages' => $messages, 'tools' => $tools, 'configuration' => $configuration, 'options' => $options];

        return $this->next(__FUNCTION__);
    }

    private function next(string $method): CompletionResponse
    {
        if ($this->throwable instanceof Throwable) {
            throw $this->throwable;
        }

        $response = array_shift($this->responses);
        if (!$response instanceof CompletionResponse) {
            throw new LogicException(sprintf('%s::%s() was called but no response was queued in $responses.', self::class, $method));
        }

        return $response;
    }
}
