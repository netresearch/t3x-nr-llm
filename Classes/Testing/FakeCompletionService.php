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
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Throwable;

/**
 * Consumer-facing test double for {@see CompletionServiceInterface}.
 *
 * Ships in the runtime-autoloaded `Netresearch\NrLlm\Testing\` namespace so
 * downstream extensions can fake the completion surface in their unit tests
 * instead of hand-rolling a double that fatals whenever the interface grows
 * (the failure mode ADR-051/ADR-073 record). Implementing the real interface
 * means PHPStan keeps this double in sync with the production contract.
 *
 * The six {@see CompletionResponse}-returning methods (`complete`,
 * `completeFactual`, `completeCreative` and their `*ForConfiguration` twins)
 * return {@see $responses} in FIFO order; queue one per expected call. The JSON
 * and Markdown methods return the canned {@see $jsonResult} / {@see $markdownResult}.
 * Every call is captured in the matching `*Calls` array; set {@see $throwable}
 * to make the next call throw instead of returning.
 *
 * Not a DI service: excluded from container autoconfiguration in
 * `Configuration/Services.yaml`. It is a fixture for consumer test suites,
 * never wire it into production.
 */
final class FakeCompletionService implements CompletionServiceInterface
{
    /**
     * CompletionResponses returned in FIFO order, one per call across the six
     * response-returning methods.
     *
     * @var list<CompletionResponse>
     */
    public array $responses = [];

    /**
     * Canned result for {@see self::completeJson()} and
     * {@see self::completeJsonForConfiguration()}.
     *
     * @var array<string, mixed>
     */
    public array $jsonResult = [];

    /** Canned result for {@see self::completeMarkdown()} and {@see self::completeMarkdownForConfiguration()}. */
    public string $markdownResult = '';

    /**
     * When set, the next call throws this instead of returning. Cleared before
     * throwing, so subsequent calls return queued/canned values again.
     */
    public ?Throwable $throwable = null;

    /** @var list<array{prompt: string, options: ?ChatOptions}> */
    public array $completeCalls = [];

    /** @var list<array{prompt: string, options: ?ChatOptions}> */
    public array $completeJsonCalls = [];

    /** @var list<array{prompt: string, options: ?ChatOptions}> */
    public array $completeMarkdownCalls = [];

    /** @var list<array{prompt: string, options: ?ChatOptions}> */
    public array $completeFactualCalls = [];

    /** @var list<array{prompt: string, options: ?ChatOptions}> */
    public array $completeCreativeCalls = [];

    /** @var list<array{prompt: string, configuration: LlmConfiguration, options: ?ChatOptions}> */
    public array $completeForConfigurationCalls = [];

    /** @var list<array{prompt: string, configuration: LlmConfiguration, options: ?ChatOptions}> */
    public array $completeJsonForConfigurationCalls = [];

    /** @var list<array{prompt: string, configuration: LlmConfiguration, options: ?ChatOptions}> */
    public array $completeMarkdownForConfigurationCalls = [];

    /** @var list<array{prompt: string, configuration: LlmConfiguration, options: ?ChatOptions}> */
    public array $completeFactualForConfigurationCalls = [];

    /** @var list<array{prompt: string, configuration: LlmConfiguration, options: ?ChatOptions}> */
    public array $completeCreativeForConfigurationCalls = [];

    public function complete(string $prompt, ?ChatOptions $options = null): CompletionResponse
    {
        $this->completeCalls[] = ['prompt' => $prompt, 'options' => $options];

        return $this->nextResponse(__FUNCTION__);
    }

    public function completeJson(string $prompt, ?ChatOptions $options = null): array
    {
        $this->completeJsonCalls[] = ['prompt' => $prompt, 'options' => $options];
        $this->guardThrow();

        return $this->jsonResult;
    }

    public function completeMarkdown(string $prompt, ?ChatOptions $options = null): string
    {
        $this->completeMarkdownCalls[] = ['prompt' => $prompt, 'options' => $options];
        $this->guardThrow();

        return $this->markdownResult;
    }

    public function completeFactual(string $prompt, ?ChatOptions $options = null): CompletionResponse
    {
        $this->completeFactualCalls[] = ['prompt' => $prompt, 'options' => $options];

        return $this->nextResponse(__FUNCTION__);
    }

    public function completeCreative(string $prompt, ?ChatOptions $options = null): CompletionResponse
    {
        $this->completeCreativeCalls[] = ['prompt' => $prompt, 'options' => $options];

        return $this->nextResponse(__FUNCTION__);
    }

    public function completeForConfiguration(string $prompt, LlmConfiguration $configuration, ?ChatOptions $options = null): CompletionResponse
    {
        $this->completeForConfigurationCalls[] = ['prompt' => $prompt, 'configuration' => $configuration, 'options' => $options];

        return $this->nextResponse(__FUNCTION__);
    }

    public function completeJsonForConfiguration(string $prompt, LlmConfiguration $configuration, ?ChatOptions $options = null): array
    {
        $this->completeJsonForConfigurationCalls[] = ['prompt' => $prompt, 'configuration' => $configuration, 'options' => $options];
        $this->guardThrow();

        return $this->jsonResult;
    }

    public function completeMarkdownForConfiguration(string $prompt, LlmConfiguration $configuration, ?ChatOptions $options = null): string
    {
        $this->completeMarkdownForConfigurationCalls[] = ['prompt' => $prompt, 'configuration' => $configuration, 'options' => $options];
        $this->guardThrow();

        return $this->markdownResult;
    }

    public function completeFactualForConfiguration(string $prompt, LlmConfiguration $configuration, ?ChatOptions $options = null): CompletionResponse
    {
        $this->completeFactualForConfigurationCalls[] = ['prompt' => $prompt, 'configuration' => $configuration, 'options' => $options];

        return $this->nextResponse(__FUNCTION__);
    }

    public function completeCreativeForConfiguration(string $prompt, LlmConfiguration $configuration, ?ChatOptions $options = null): CompletionResponse
    {
        $this->completeCreativeForConfigurationCalls[] = ['prompt' => $prompt, 'configuration' => $configuration, 'options' => $options];

        return $this->nextResponse(__FUNCTION__);
    }

    private function nextResponse(string $method): CompletionResponse
    {
        $this->guardThrow();

        $response = array_shift($this->responses);
        if (!$response instanceof CompletionResponse) {
            throw new LogicException(sprintf('%s::%s() was called but no response was queued in $responses.', self::class, $method), 7126400010);
        }

        return $response;
    }

    private function guardThrow(): void
    {
        if ($this->throwable instanceof Throwable) {
            $throwable = $this->throwable;
            $this->throwable = null;

            throw $throwable;
        }
    }
}
