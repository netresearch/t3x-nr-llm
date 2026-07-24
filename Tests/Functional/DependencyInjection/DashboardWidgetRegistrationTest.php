<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\DependencyInjection;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Dashboard\WidgetRegistry;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Regression test for the optional dashboard widget registration.
 *
 * The widgets are registered via Configuration/Services.Dashboard.php, imported
 * conditionally from Configuration/Services.php only when typo3/cms-dashboard is
 * installed. This guards installs without the dashboard system extension from a
 * container compile failure on unresolvable widget class references.
 *
 * Extends FunctionalTestCase directly (not the project base) so that a container
 * compile failure surfaces as a test failure instead of being swallowed into a
 * skipped test, and loads the dashboard system extension so the guard fires.
 *
 * Building the DI container during parent::setUp() makes TYPO3 core reset and
 * repopulate $GLOBALS['TYPO3_CONF_VARS'], and a service instantiated within that
 * window calls TYPO3\CMS\Core\Crypto\HashService::hmac(), which reads the
 * encryptionKey global while it is momentarily unset. On PHP 8.2 / TYPO3 14.3 this
 * emits harmless "undefined variable / array offset on null" warnings during boot
 * (suppressed in production by TYPO3's error handler, which functional tests
 * disable). The warning is in core's boot ordering and cannot be prevented from
 * the extension — pinning the key or disabling the global backup does not help
 * because the container build clears the global itself. setUp() below wraps that
 * build in a scoped error handler that suppresses ONLY the two specific
 * HashService warnings (matched by originating file and message), so
 * failOnWarning=true does not fail on that benign core-boot warning; any other
 * warning — including a different one from the same core file — still propagates
 * to PHPUnit and fails the test. The handler is restored inside setUp() so it stays
 * balanced and the test is not flagged risky.
 */
final class DashboardWidgetRegistrationTest extends FunctionalTestCase
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
        'dashboard',
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
    public function dashboardWidgetsAreRegisteredWhenDashboardIsInstalled(): void
    {
        $registry = $this->get(WidgetRegistry::class);
        self::assertInstanceOf(WidgetRegistry::class, $registry);

        $widgets = $registry->getAllWidgets();

        self::assertArrayHasKey('nrllm-monthly-cost', $widgets);
        self::assertArrayHasKey('nrllm-requests-by-provider', $widgets);

        // Agentic / governance / telemetry widgets (group: nrllm).
        self::assertArrayHasKey('nrllm-agent-runs-by-status', $widgets);
        self::assertArrayHasKey('nrllm-runs-awaiting-approval', $widgets);
        self::assertArrayHasKey('nrllm-run-termination-reasons', $widgets);
        self::assertArrayHasKey('nrllm-request-success-rate', $widgets);
        self::assertArrayHasKey('nrllm-average-latency', $widgets);
    }
}
