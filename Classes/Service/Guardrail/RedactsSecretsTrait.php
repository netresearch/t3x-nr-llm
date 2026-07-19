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
        // Best-effort masking of common secret shapes the URL sanitiser does not
        // cover — unknown formats still pass through. Each preg_replace is
        // guarded: on failure (e.g. a backtrack-limit hit on a huge payload) it
        // returns null, which a bare (string) cast would turn into '' — silently
        // wiping the whole content; keep the last good string instead.
        //
        // The sk- class allows hyphens/underscores so modern project keys
        // (sk-proj-…) match.
        $withoutApiKeys = preg_replace('/\bsk-[A-Za-z0-9_\-]{16,}/', 'sk-***', (string)$redacted);
        if (is_string($withoutApiKeys)) {
            $redacted = $withoutApiKeys;
        }
        // Bearer token — the class covers base64-standard chars (+ / =) so a
        // token's tail is not left behind (parity with {@see ContentRedactor}).
        $withoutBearer = preg_replace('/\b(Bearer\s+)[A-Za-z0-9._~+\/\-]+=*/i', '$1***', (string)$redacted);
        if (is_string($withoutBearer)) {
            $redacted = $withoutBearer;
        }
        // High-signal vendor secret shapes. A JWT is the canonical bearer secret
        // even without a `Bearer ` prefix; the rest are fixed-prefix provider
        // tokens. All patterns are anchored and linear (no ReDoS).
        $withoutVendor = preg_replace(
            [
                '/\beyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+/', // JWT (header.payload.signature)
                '/\b(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{36,}/',            // GitHub token
                '/\bgithub_pat_[A-Za-z0-9_]{22,}/',                        // GitHub fine-grained PAT
                '/\bAKIA[0-9A-Z]{16,}/',                                   // AWS access-key id
                '/\bAIza[0-9A-Za-z_\-]{35,}/',                             // Google API key
                '/\bxox[baprs]-[A-Za-z0-9\-]{10,}/',                       // Slack token
            ],
            '***',
            (string)$redacted,
        );
        if (is_string($withoutVendor)) {
            $redacted = $withoutVendor;
        }

        return $redacted;
    }
}
