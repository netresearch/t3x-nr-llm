<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\FunctionalHarness;

use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use RuntimeException;

/**
 * Fixture-only process identity; each parent owns its instance directory.
 */
abstract class IsolatedFunctionalFixture extends AbstractFunctionalTestCase
{
    /**
     * @return non-empty-string
     */
    protected static function getInstanceIdentifier(): string
    {
        $identifier = getenv('NR_LLM_FUNCTIONAL_FIXTURE_ID');
        if (!is_string($identifier) || $identifier === '' || preg_match('/^[a-f0-9]{32}$/D', $identifier) !== 1) {
            throw new RuntimeException(
                'The harness child requires its owned instance identifier.',
                8481958859,
            );
        }

        return $identifier;
    }
}
