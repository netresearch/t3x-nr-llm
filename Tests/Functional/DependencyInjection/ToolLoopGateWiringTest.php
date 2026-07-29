<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\DependencyInjection;

use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Pins the production wiring of the agent loop's tool gate (ADR-120).
 *
 * {@see ToolLoopService} takes its {@see ToolCallPolicyInterface} as a required
 * constructor argument, so an install whose container cannot resolve one fails
 * to compile rather than running with a weaker gate. Nothing else asserts that:
 * every other test hand-constructs the loop, which is exactly the wiring this
 * test exists to be independent of.
 *
 * Two things are checked, and the second is the one with teeth. That the loop
 * resolves at all proves the required argument is autowirable. That the bound
 * policy is the real {@see ToolCallPolicy} proves the container reaches the
 * composite gate — the trust-zone axis included — and not some narrower
 * implementation that would satisfy the type while deciding less.
 *
 * Extends {@see FunctionalTestCase} directly rather than the project base,
 * whose setUp() converts a container-compile failure into a skipped test — the
 * precise regression this test exists to catch. Same rationale, and the same
 * scoped HashService warning suppression, as
 * {@see DashboardWidgetRegistrationTest}.
 */
final class ToolLoopGateWiringTest extends FunctionalTestCase
{
    /** @var non-empty-string[] */
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
    ];

    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = [
        'extbase',
        'fluid',
    ];

    protected function setUp(): void
    {
        set_error_handler(
            static fn(int $errno, string $errstr, string $errfile): bool => str_contains($errfile, 'Crypto/HashService.php')
                && (str_contains($errstr, 'TYPO3_CONF_VARS') || str_contains($errstr, 'array offset')),
            \E_WARNING,
        );

        try {
            parent::setUp();
        } finally {
            restore_error_handler();
        }
    }

    #[Test]
    public function theAgentLoopResolvesWithItsRequiredToolGate(): void
    {
        $loop = $this->get(ToolLoopServiceInterface::class);

        self::assertInstanceOf(ToolLoopService::class, $loop);
    }

    #[Test]
    public function productionBindsTheCompositeToolCallPolicy(): void
    {
        // A narrower implementation would satisfy the type hint while deciding
        // less; the loop cannot tell the difference, so the container is pinned
        // here instead.
        $policy = $this->get(ToolCallPolicyInterface::class);

        self::assertInstanceOf(ToolCallPolicy::class, $policy);
    }
}
