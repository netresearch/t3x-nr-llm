<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;

/**
 * Redacts secrets a model may have echoed into its response (ADR-085).
 *
 * A model given secret-bearing context (a connection string, an API key, a
 * Bearer header) can repeat it verbatim. This guardrail masks the common shapes:
 * credential-bearing URL query params (via the shared
 * {@see \Netresearch\NrLlm\Utility\ErrorMessageSanitizerTrait}), ``sk-…`` API
 * keys, and ``Bearer …`` tokens. It only returns REDACT when it actually changed
 * something — otherwise ALLOW (a pass-through), so a normal response is
 * untouched. The prompt-side counterpart is {@see SecretRedactionInputGuardrail}.
 */
final readonly class SecretRedactionGuardrail implements GuardrailInterface, StreamRedactableInterface
{
    use RedactsSecretsTrait;

    public function checkOutput(CompletionResponse $response): GuardrailResult
    {
        return $this->redactionResult($response->content, 'response');
    }
}
