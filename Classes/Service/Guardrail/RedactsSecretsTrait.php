<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail;

use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;
use Netresearch\NrLlm\Utility\ErrorMessageSanitizerTrait;

/**
 * Secret-masking shared by the output ({@see SecretRedactionGuardrail}) and
 * input ({@see SecretRedactionInputGuardrail}) reference guardrails (ADR-085 /
 * ADR-087).
 *
 * The two guardrails are separate classes — a single class cannot implement both
 * {@see GuardrailInterface} and {@see InputGuardrailInterface}, whose ``TAG_NAME``
 * constants would collide — but the masking is identical, so it lives here once.
 */
trait RedactsSecretsTrait
{
    use ErrorMessageSanitizerTrait;

    /**
     * REDACT when the masking changed something, otherwise ALLOW (a pass-through)
     * so normal content is untouched. ``$where`` names the side for the reason.
     */
    private function redactionResult(string $content, string $where): GuardrailResult
    {
        $redacted = $this->redactSecrets($content);

        if ($redacted === $content) {
            return GuardrailResult::allow();
        }

        return GuardrailResult::redact($redacted, sprintf('Redacted secret-shaped strings from the %s.', $where));
    }

    private function redactSecrets(string $content): string
    {
        $redacted = $this->sanitizeErrorMessage($content);
        // API-key and Bearer-token shapes the URL-param sanitiser does not cover.
        // The sk- class allows hyphens/underscores so modern project keys
        // (sk-proj-…) match. Each preg_replace is guarded: on failure (e.g. a
        // backtrack-limit hit on a huge payload) it returns null, which a bare
        // (string) cast would turn into '' — silently wiping the whole content.
        $withoutApiKeys = preg_replace('/\bsk-[A-Za-z0-9_\-]{16,}/', 'sk-***', (string)$redacted);
        if (is_string($withoutApiKeys)) {
            $redacted = $withoutApiKeys;
        }
        $withoutBearer = preg_replace('/\b(Bearer\s+)[A-Za-z0-9._\-]{8,}/i', '$1***', (string)$redacted);
        if (is_string($withoutBearer)) {
            $redacted = $withoutBearer;
        }

        return $redacted;
    }
}
