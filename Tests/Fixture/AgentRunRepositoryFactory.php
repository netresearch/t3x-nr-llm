<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixture;

use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Service\Privacy\ContentRedactor;
use Netresearch\NrLlm\Service\Privacy\RunPrivacyLevelResolver;
use Netresearch\NrLlm\Service\Privacy\RunStepPrivacyFilter;
use Netresearch\NrLlm\Service\Tool\AgentRunRepository;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Service\Tool\RunContentPrivacyGuard;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Explicit full-content policy for existing repository fixtures, independent of installation defaults.
 */
final class AgentRunRepositoryFactory
{
    public static function create(
        ConnectionPool $pool,
        AgentStateCodec $codec,
    ): AgentRunRepository {
        $redactor = new ContentRedactor();
        $policy   = new FixedPrivacyPolicy(PrivacyLevel::FULL, redactor: $redactor);

        return new AgentRunRepository(
            $pool,
            $codec,
            new RunContentPrivacyGuard(
                $pool,
                new RunPrivacyLevelResolver($policy, $pool),
                new RunStepPrivacyFilter($policy, $redactor),
                $policy,
                $codec,
            ),
        );
    }
}
