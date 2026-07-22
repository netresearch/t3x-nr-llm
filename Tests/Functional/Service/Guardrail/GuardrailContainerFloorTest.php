<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Guardrail;

use Netresearch\NrLlm\Service\Guardrail\GuardrailRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * The real-DI mandatory floor (ADR-106): resolves the GuardrailRegistry from the
 * container — built over the actual tagged guardrail iterators — and asserts the
 * secret-redaction floor is present and mandatory.
 *
 * This is the load-bearing guard against a Services.yaml regression or an
 * #[AutoconfigureTag] typo silently dropping a security guardrail from the
 * collection while every isolated unit test (which uses doubles) stays green.
 */
#[CoversClass(GuardrailRegistry::class)]
final class GuardrailContainerFloorTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function secretRedactionIsAMandatoryFloorInTheRealContainer(): void
    {
        $registry = $this->get(GuardrailRegistry::class);
        self::assertInstanceOf(GuardrailRegistry::class, $registry);

        // Building over the real tagged iterators must not throw (no cross-side
        // mandatory disagreement) and must classify secret-redaction mandatory
        // on both axes — so no configuration can ever disable it.
        self::assertTrue($registry->isMandatoryIdentifier('secret-redaction'));

        // A mandatory guardrail is never offered as a selectable (un-checkable) box.
        self::assertNotContains('secret-redaction', $registry->selectableIdentifiers());

        // The shipped optional guardrail is discoverable and selectable.
        self::assertContains('provider-content-filter', $registry->selectableIdentifiers());
    }
}
