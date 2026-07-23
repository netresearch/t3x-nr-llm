<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\ListExtensionsTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Functional tests for ListExtensionsTool (ADR-048): the active packages
 * list with versions, without package paths.
 */
#[CoversClass(ListExtensionsTool::class)]
final class ListExtensionsToolTest extends AbstractFunctionalTestCase
{
    private ToolInterface $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1);

        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $tool = $registry->get('list_extensions');
        self::assertInstanceOf(ToolInterface::class, $tool);
        $this->tool = $tool;
    }

    #[Test]
    public function listsActivePackagesWithVersions(): void
    {
        $output = $this->tool->execute([], ToolExecutionContext::none())->content;

        self::assertStringContainsString('Active extensions (', $output);
        self::assertStringContainsString('- nr_llm ', $output);
        self::assertStringContainsString('- core ', $output);
        // A semver-looking version somewhere in the list.
        self::assertMatchesRegularExpression('/ \d+\.\d+/', $output);
        // Never a filesystem path.
        self::assertStringNotContainsString('/vendor/', $output);
        self::assertStringNotContainsString($this->instancePath, $output);
    }
}
