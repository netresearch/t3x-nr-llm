<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Decision\Profile;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Declares the decision profiles an extension uses (ADR-211).
 *
 * Implement it in the consumer extension; the tag is applied by
 * autoconfiguration, as for golden prompt sets (ADR-060).
 *
 * @api Extension point: third parties implement this. No new abstract
 * member within a major version (ADR-127).
 */
#[AutoconfigureTag(name: self::TAG_NAME)]
interface DecisionProfileProviderInterface
{
    public const TAG_NAME = 'nr_llm.decision_profile';

    /**
     * @return list<DecisionProfile>
     */
    public function getDecisionProfiles(): array;
}
