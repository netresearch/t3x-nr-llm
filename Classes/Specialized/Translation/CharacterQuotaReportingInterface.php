<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Translation;

use Netresearch\NrLlm\Specialized\Exception\ServiceConfigurationException;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;

/**
 * A translator that bills by the character against an account quota, and can
 * say how much of it is used (ADR-207).
 *
 * Separate from {@see TranslatorInterface} because most translators have no
 * such quota — the LLM translator is billed in tokens through the provider —
 * and because `DeepLTranslator` is final: the backend controller that shows
 * the quota depends on this interface so it can be tested without a real
 * DeepL account.
 *
 * @internal Not part of the @api surface; may change without notice.
 */
interface CharacterQuotaReportingInterface
{
    /**
     * Ask the service for the characters used and the limit of the current
     * billing period. Costs no characters.
     *
     * @throws ServiceUnavailableException   when no credential is configured or the service cannot be reached
     * @throws ServiceConfigurationException when the service rejects the credential
     */
    public function getCharacterQuota(): CharacterQuota;
}
