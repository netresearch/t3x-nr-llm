<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Locks the registered backend modules to the cards on the overview page.
 *
 * The overview is hand-written Fluid, so a module registered in
 * Configuration/Backend/Modules.php reaches the module menu but stays absent
 * from the overview unless someone also writes its card. That is how MCP
 * Servers, Agent Runs and Analytics went missing: all three were registered
 * and reachable, and the overview simply never mentioned them.
 */
#[CoversNothing]
final class OverviewCardCoverageTest extends TestCase
{
    private const MODULES_FILE = __DIR__ . '/../../../Configuration/Backend/Modules.php';

    private const OVERVIEW_TEMPLATE = __DIR__ . '/../../../Resources/Private/Templates/Backend/Index.html';

    /**
     * The overview itself: linking the page to itself is not a card, and the
     * help card deliberately points at an action on this same route.
     */
    private const NOT_A_CARD_TARGET = 'nrllm_overview';

    /**
     * Only the children of the LLM module get a card. 'nrllm' itself is the
     * module the overview belongs to and sits under 'tools'.
     */
    private const CARD_PARENT = 'nrllm';

    #[Test]
    public function everyRegisteredModuleHasACardOnTheOverview(): void
    {
        $missing = \array_values(\array_diff($this->registeredModules(), $this->linkedModules()));

        self::assertSame(
            [],
            $missing,
            'Registered but not linked from the overview: ' . \implode(', ', $missing)
                . '. Add a card in Resources/Private/Templates/Backend/Index.html.',
        );
    }

    #[Test]
    public function theOverviewLinksNoModuleThatIsNotRegistered(): void
    {
        // Any registered module may be linked — nrllm_aitasks for instance is
        // an editor-facing Web module that the overview points at on purpose.
        // What must not happen is a link to a route nobody registers.
        $unknown = \array_values(\array_diff($this->linkedModules(), $this->allRegisteredModules()));

        self::assertSame(
            [],
            $unknown,
            'Linked from the overview but not registered: ' . \implode(', ', $unknown)
                . '. Such a card renders a dead link.',
        );
    }

    /**
     * Every module name the extension registers, whatever its parent.
     *
     * @return list<string>
     */
    private function allRegisteredModules(): array
    {
        $modules = require self::MODULES_FILE;
        self::assertIsArray($modules);

        $names = [];
        foreach (\array_keys($modules) as $name) {
            if (\is_string($name)) {
                $names[] = $name;
            }
        }
        \sort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    private function registeredModules(): array
    {
        $modules = require self::MODULES_FILE;
        self::assertIsArray($modules);

        $names = [];
        foreach ($modules as $name => $definition) {
            if (!\is_string($name) || $name === self::NOT_A_CARD_TARGET) {
                continue;
            }
            if (!\is_array($definition) || ($definition['parent'] ?? null) !== self::CARD_PARENT) {
                continue;
            }
            $names[] = $name;
        }
        \sort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    private function linkedModules(): array
    {
        $template = \file_get_contents(self::OVERVIEW_TEMPLATE);
        self::assertIsString($template);

        \preg_match_all("/route: '([a-z_]+)'/", $template, $matches);
        $routes = \array_values(\array_unique(\array_filter(
            $matches[1],
            static fn(string $route): bool => $route !== self::NOT_A_CARD_TARGET,
        )));
        \sort($routes);

        return $routes;
    }
}
