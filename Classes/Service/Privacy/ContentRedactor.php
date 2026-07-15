<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Privacy;

use Netresearch\NrLlm\Utility\ErrorMessageSanitizerTrait;

/**
 * Bounded, best-effort redactor for content stored at the REDACTED privacy
 * level (ADR-064).
 *
 * This is a heuristic, NOT a guaranteed PII scrubber. It masks a small set of
 * high-signal secrets — credential-bearing URL query parameters (via the shared
 * {@see ErrorMessageSanitizerTrait}), obvious bearer / API tokens, and email
 * addresses — and caps the payload length. It does not attempt to find names,
 * postal addresses, free-form personal data, or secrets in shapes it does not
 * know. When content must not be stored at all, choose PrivacyLevel::NONE or
 * METADATA instead of relying on this class.
 */
final class ContentRedactor
{
    use ErrorMessageSanitizerTrait;

    /** Hard cap on stored length in characters; longer content is truncated. */
    private const MAX_LENGTH = 2000;

    private const TRUNCATION_MARKER = '… [truncated]';

    private const MASK = '***';

    public function redact(?string $content): ?string
    {
        if ($content === null) {
            return null;
        }

        // 1. Credential query parameters (?key=, ?token=, …) — reuse the shared
        //    trait rather than duplicating its regex.
        $redacted = $this->sanitizeErrorMessage($content);

        // 2. Obvious bearer / API tokens in free text.
        $redacted = (string)preg_replace(
            [
                '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i',
                '/\bsk-[A-Za-z0-9]{20,}/',
            ],
            [
                'Bearer ' . self::MASK,
                self::MASK,
            ],
            $redacted,
        );

        // 3. Email addresses.
        $redacted = (string)preg_replace(
            '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/',
            self::MASK,
            $redacted,
        );

        // 4. Cap the stored length.
        if (mb_strlen($redacted) > self::MAX_LENGTH) {
            return mb_substr($redacted, 0, self::MAX_LENGTH) . self::TRUNCATION_MARKER;
        }

        return $redacted;
    }
}
