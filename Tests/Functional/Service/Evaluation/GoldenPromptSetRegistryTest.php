<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Evaluation;

use Netresearch\NrLlm\Service\Evaluation\BuiltinGoldenPromptSetProvider;
use Netresearch\NrLlm\Service\Evaluation\GoldenPromptSetRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Verifies the golden-set DI wiring end-to-end (ADR-060): the
 * AutoconfigureTag on GoldenPromptSetProviderInterface plus the
 * AutowireIterator in GoldenPromptSetRegistry must collect the built-in
 * provider from the real container.
 *
 * The registry is private but injected into the public eval command, so the
 * testing framework resolves it through its non-public container without the
 * registry needing to be a public service.
 */
#[CoversClass(GoldenPromptSetRegistry::class)]
#[CoversClass(BuiltinGoldenPromptSetProvider::class)]
final class GoldenPromptSetRegistryTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function builtinProviderIsCollectedViaDiTag(): void
    {
        $registry = $this->get(GoldenPromptSetRegistry::class);
        self::assertInstanceOf(GoldenPromptSetRegistry::class, $registry);

        $set = $registry->findByIdentifier(BuiltinGoldenPromptSetProvider::SET_IDENTIFIER);

        self::assertNotNull($set, 'The built-in smoke set must be discoverable through the DI tag.');
        self::assertNotEmpty($set->prompts);
    }
}
