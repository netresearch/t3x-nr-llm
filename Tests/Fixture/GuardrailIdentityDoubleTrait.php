<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixture;

/**
 * Default {@see \Netresearch\NrLlm\Service\Guardrail\GuardrailIdentity} members
 * for guardrail test doubles (ADR-106).
 *
 * Most doubles exercise middleware/screener/dispatcher behaviour, not
 * per-configuration filtering, so a generic optional identity suffices and
 * keeps the doubles focused. A test that needs a specific identifier or a
 * mandatory guardrail declares its own methods instead of using this trait.
 */
trait GuardrailIdentityDoubleTrait
{
    public function getIdentifier(): string
    {
        return 'test-guardrail';
    }

    public function isMandatory(): bool
    {
        return false;
    }
}
