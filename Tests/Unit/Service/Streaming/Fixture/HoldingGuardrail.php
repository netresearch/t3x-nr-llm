<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Streaming\Fixture;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Service\Guardrail\ContextualGuardrailInterface;
use Netresearch\NrLlm\Tests\Fixture\GuardrailIdentityDoubleTrait;

final class HoldingGuardrail implements ContextualGuardrailInterface
{
    use GuardrailIdentityDoubleTrait;

    /** @var list<string> */
    public array $screened = [];

    public ?LlmConfiguration $seenConfiguration = null;

    public ?LlmConfiguration $askedAbout = null;

    public function __construct(
        private readonly GuardrailResult $result,
        private readonly bool $holds = true,
    ) {}

    public function checkOutput(CompletionResponse $response): GuardrailResult
    {
        return GuardrailResult::allow();
    }

    public function checkOutputInContext(CompletionResponse $response, ProviderCallContext $context): GuardrailResult
    {
        $this->screened[]        = $response->content;
        $this->seenConfiguration = $context->configuration;

        return $this->result;
    }

    public function holdsStream(ProviderCallContext $context): bool
    {
        $this->askedAbout = $context->configuration;

        return $this->holds;
    }
}
