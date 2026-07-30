<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Privacy;

use Netresearch\NrLlm\Utility\SecretShapeRedactorTrait;

/**
 * Bounded, best-effort redactor for content stored at the REDACTED privacy
 * level (ADR-064).
 *
 * This is a heuristic, NOT a guaranteed PII scrubber. It masks the secret shapes
 * in {@see SecretShapeRedactorTrait} plus email addresses, and caps the payload
 * length. It does not attempt to find names, postal addresses, free-form personal
 * data, or secrets in shapes it does not know. When content must not be stored at
 * all, choose PrivacyLevel::NONE or METADATA instead of relying on this class.
 *
 * It used to carry its own, weaker copy of the secret patterns, which missed
 * seven shapes the response guardrail already caught — modern OpenAI project
 * keys, GitHub PATs (classic and fine-grained), AWS and Google keys, Slack
 * tokens and bare JWTs. A secret masked on its way to a provider was therefore
 * still written to the database in cleartext. Both now read the same catalogue
 * (ADR-123).
 *
 * Email masking stays here rather than moving into the shared trait: an address
 * is personal data rather than a secret, and the guardrails must NOT start
 * stripping addresses out of prompts and responses, where removing one changes
 * what the text says.
 */
final class ContentRedactor
{
    use SecretShapeRedactorTrait;

    /** Hard cap on stored length in characters; longer content is truncated. */
    private const MAX_LENGTH = 2000;

    private const TRUNCATION_MARKER = '… [truncated]';

    private const MASK = '***';

    private const EMAIL_PATTERN = '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/';

    public function redact(?string $content): ?string
    {
        if ($content === null) {
            return null;
        }

        // 1. Every secret shape the extension knows, from the shared catalogue.
        //    Fail-open: a pattern that gives up must not blank the stored content.
        $redacted = $this->redactSecretShapes($content);

        // 2. Email addresses — this redactor's own concern, see the class docblock.
        $withoutEmails = preg_replace(self::EMAIL_PATTERN, self::MASK, $redacted);
        if (is_string($withoutEmails)) {
            $redacted = $withoutEmails;
        }

        // 3. Cap the stored length.
        if (mb_strlen($redacted) > self::MAX_LENGTH) {
            return mb_substr($redacted, 0, self::MAX_LENGTH) . self::TRUNCATION_MARKER;
        }

        return $redacted;
    }
}
