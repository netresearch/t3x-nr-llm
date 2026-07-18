<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;
use Netresearch\NrLlm\Utility\ErrorMessageSanitizerTrait;

/**
 * Redacts secrets a model may have echoed into its response (ADR-085).
 *
 * A model given secret-bearing context (a connection string, an API key, a
 * Bearer header) can repeat it verbatim. This guardrail masks the common shapes:
 * credential-bearing URL query params (via the shared
 * {@see ErrorMessageSanitizerTrait}), ``sk-…`` API keys, and ``Bearer …``
 * tokens. It only returns REDACT when it actually changed something — otherwise
 * ALLOW (a pass-through), so a normal response is untouched.
 */
final readonly class SecretRedactionGuardrail implements GuardrailInterface
{
    use ErrorMessageSanitizerTrait;

    public function checkOutput(CompletionResponse $response): GuardrailResult
    {
        $redacted = $this->sanitizeErrorMessage($response->content);
        // API-key and Bearer-token shapes the URL-param sanitiser does not cover.
        $redacted = (string)preg_replace('/\bsk-[A-Za-z0-9]{16,}\b/', 'sk-***', $redacted);
        $redacted = (string)preg_replace('/\b(Bearer\s+)[A-Za-z0-9._\-]{8,}/i', '$1***', $redacted);

        if ($redacted === $response->content) {
            return GuardrailResult::allow();
        }

        return GuardrailResult::redact($redacted, 'Redacted secret-shaped strings from the response.');
    }
}
