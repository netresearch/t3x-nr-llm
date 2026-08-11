<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Contract;

use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\OpenRouterProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;

/**
 * OpenRouter is the one bundled adapter that does not send through
 * `AbstractProvider::sendRequest()`: it needs the `HTTP-Referer` / `X-Title`
 * attribution headers and its own status mapping (402 = out of credits), so
 * it carries a private request path. The two overrides below are the record
 * of what that costs — they are the contract's way of making the deviation
 * visible instead of leaving it to whoever next reads the adapter.
 */
#[CoversClass(OpenRouterProvider::class)]
final class OpenRouterAdapterContractTest extends AbstractOpenAiDialectContractTestCase
{
    protected function newAdapter(): OpenRouterProvider
    {
        return new OpenRouterProvider(
            $this->requestFactory,
            $this->streamFactory,
            new NullLogger(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
    }

    protected function expectedIdentifier(): string
    {
        return 'openrouter';
    }

    /**
     * `routingStrategy: explicit` keeps the adapter from calling
     * `fetchAvailableModels()` before every completion — a second live
     * request that has nothing to do with the contract under test.
     */
    protected function adapterConfiguration(): array
    {
        return [
            'apiKeyIdentifier' => 'vault-openrouter',
            'defaultModel' => 'openai/gpt-5.2',
            'routingStrategy' => 'explicit',
            'autoFallback' => false,
            'timeout' => 30,
        ];
    }

    protected function wireModelId(): string
    {
        return 'openai/gpt-5.2';
    }

    /**
     * DEVIATION: OpenRouter's own mapping sends every non-special status
     * through `ProviderResponseException`, so a 502 from the gateway is a
     * response error here and a connection error on every other adapter.
     *
     * What this does NOT change is retry and fallback. `FailureClassifier`
     * (ADR-095) reads the carried status, not the class: a
     * `ProviderResponseException` with a 5xx code classifies as
     * `FailureClass::SERVER_ERROR`, which answers `isRetryable()` and
     * `tripsCircuit()` exactly as `CONNECTION` does. The chain hops either
     * way.
     *
     * What it does change is the class a caller catches and the wording. A
     * handler with a `catch (ProviderResponseException)` arm ahead of a
     * generic one — `ProviderController::testConnectionAction()` is the
     * in-tree case — takes that arm for an OpenRouter 5xx and the generic
     * `ProviderException` arm for every other adapter's. And the message
     * reads `OpenRouter API error (502): …` where the shared path says
     * `Server returned status 502`.
     */
    protected function expectedServerErrorException(): string
    {
        return ProviderResponseException::class;
    }

    /**
     * DEVIATION: the private request path has no retry loop, so `maxRetries`
     * is inert for OpenRouter. A flaky upstream gets exactly one attempt.
     */
    protected function retriesTransportFailures(): bool
    {
        return false;
    }
}
