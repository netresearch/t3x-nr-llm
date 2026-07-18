<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail;

use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;

/**
 * Redacts secrets from the OUTGOING prompt before it reaches a provider
 * (ADR-087) — the input-side counterpart of {@see SecretRedactionGuardrail}.
 *
 * A user can paste a secret (an API key, a credential-bearing URL, a Bearer
 * header) into a prompt that should not be forwarded verbatim to a third-party
 * provider. This masks the same shapes on the prompt as the response side, and
 * only returns REDACT when it actually changed something. Because input
 * screening runs on the send path ({@see InputGuardrailScreener}), the REDACT
 * rewrites the prompt in place.
 */
final readonly class SecretRedactionInputGuardrail implements InputGuardrailInterface
{
    use RedactsSecretsTrait;

    public function checkInput(string $text): GuardrailResult
    {
        return $this->redactionResult($text, 'prompt');
    }
}
