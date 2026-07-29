<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail;

use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;
use Netresearch\NrLlm\Utility\SecretShapeRedactorTrait;

/**
 * Secret-masking shared by the output ({@see SecretRedactionGuardrail}) and
 * input ({@see SecretRedactionInputGuardrail}) reference guardrails (ADR-085 /
 * ADR-087).
 *
 * The two guardrails are separate classes — a single class cannot implement both
 * {@see GuardrailInterface} and {@see InputGuardrailInterface}, whose ``TAG_NAME``
 * constants would collide — but the masking is identical, so it lives here once.
 *
 * The shapes themselves live in {@see SecretShapeRedactorTrait}, shared with the
 * privacy redactor and the environment-listing tool (ADR-123). Guardrails use the
 * fail-OPEN variant: losing a model response to a regex that gave up would be
 * worse than missing one pattern.
 */
trait RedactsSecretsTrait
{
    use SecretShapeRedactorTrait;

    /**
     * REDACT when the masking changed something, otherwise ALLOW (a pass-through)
     * so normal content is untouched. ``$where`` names the side for the reason.
     */
    private function redactionResult(string $content, string $where): GuardrailResult
    {
        $redacted = $this->redactSecretShapes($content);

        if ($redacted === $content) {
            return GuardrailResult::allow();
        }

        return GuardrailResult::redact($redacted, sprintf('Redacted secret-shaped strings from the %s.', $where));
    }
}
