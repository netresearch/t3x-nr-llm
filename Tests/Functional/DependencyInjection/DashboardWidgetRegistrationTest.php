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

    #[Test]
    public function dashboardWidgetsAreRegisteredWhenDashboardIsInstalled(): void
    {
        $registry = $this->get(WidgetRegistry::class);
        self::assertInstanceOf(WidgetRegistry::class, $registry);

        $widgets = $registry->getAllWidgets();

        self::assertArrayHasKey('nrllm-monthly-cost', $widgets);
        self::assertArrayHasKey('nrllm-requests-by-provider', $widgets);
    }
}
