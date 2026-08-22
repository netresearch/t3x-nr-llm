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
     * Only the LEAF modules get a card. Until the AI section existed these were
     * the children of the single 'nrllm' container; they are now spread across
     * three subject containers (ADR-119, #812). The containers themselves get
     * no card — they hold nothing a card could describe — and neither does the
     * section, which is not a module of this extension in the first place.
     *
     * A list rather than one name on purpose: a fourth container will be added
     * here, and a card check that silently covers only one third of the modules
     * is worse than none.
     *
     * @var list<string>
     */
    private const CARD_PARENTS = ['nrllm_setup', 'nrllm_authoring', 'nrllm_operation'];

    /**
     * Both files are read as source rather than executed. This is a
     * consistency check between two files a developer edits, and including
     * Modules.php would drag in include-once semantics for no benefit: it
     * returns its array on the first require and true on every later one.
     *
     * @var array<string, string> module name => its block of the array literal
     */
    private array $modules = [];

    protected function setUp(): void
    {
        parent::setUp();

        $source = \file_get_contents(self::MODULES_FILE);
        self::assertIsString($source);

        \preg_match_all("/^    '([a-z_]+)' => \[(.*?)^    \],/ms", $source, $matches, PREG_SET_ORDER);
        self::assertNotSame([], $matches, 'No module blocks found — has Modules.php been reformatted?');

        foreach ($matches as $match) {
            $this->modules[$match[1]] = $match[2];
        }
    }

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
        $names = \array_keys($this->modules);
        \sort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    private function registeredModules(): array
    {
        $names = [];
        foreach ($this->modules as $name => $block) {
            if ($name === self::NOT_A_CARD_TARGET) {
                continue;
            }

            $isCardTarget = false;
            foreach (self::CARD_PARENTS as $parent) {
                if (\str_contains($block, "'parent' => '" . $parent . "'")) {
                    $isCardTarget = true;
                    break;
                }
            }

            if (!$isCardTarget) {
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
