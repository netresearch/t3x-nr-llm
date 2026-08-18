<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Configuration;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Pins the inventory of surfaces from which a human decides an agent run.
 *
 * ADR-130 constraint 3 originally read "both human approval surfaces sit behind
 * admin gates". That was a claim about a LIST, and the list stopped being
 * complete when ADR-131 registered a third surface — with nothing in the
 * codebase holding it, the record kept asserting the old count for two months.
 *
 * The obvious check ("no approval action outside an admin gate") is the wrong
 * one: `nrllm_aitasks` is `access => 'user'` on purpose, so that assertion would
 * fail on the intended design and would have to be deleted the day it fired.
 * What separates an INTENDED non-admin surface from an accidental one is the
 * inventory written down: every module registering `approve`/`submitInput`
 * appears here with the access it is meant to carry, every controller that
 * reaches the approval runtime appears here at all, every AJAX route that
 * targets an approval action must guard itself (ADR-037), and ADR-130 has to
 * name what the scan finds. A fourth surface fails one of those until someone
 * edits the list, the record and this file together.
 *
 * The controller list is the one a registration cannot dodge. Scanning
 * Modules.php alone missed two shapes, both verified by registering a fourth
 * surface and watching the suite stay green: an approval action registered
 * under any controller other than AgentRunController was invisible, and a
 * module named `nrllm_run` satisfied the ADR-naming check on the strength of
 * the `nrllm_runs` already in the text. Hence no controller filter on the
 * module scan, whole-identifier matching in the record, and this third list.
 *
 * Source is read, never included: Modules.php returns its array on the first
 * require and `true` on every later one, and this is a consistency check
 * between files a developer edits (same reasoning as OverviewCardCoverageTest).
 */
#[CoversNothing]
final class ApprovalSurfaceInventoryTest extends TestCase
{
    private const MODULES_FILE = __DIR__ . '/../../../Configuration/Backend/Modules.php';

    private const AJAX_ROUTES_FILE = __DIR__ . '/../../../Configuration/Backend/AjaxRoutes.php';

    private const CLASSES_DIR = __DIR__ . '/../../../Classes';

    private const CONTROLLERS_DIR = __DIR__ . '/../../../Classes/Controller';

    private const ADR_FILE = __DIR__ . '/../../../Documentation/Adr/Adr130BackendUserGrants.rst';

    /** The module actions through which a human decides a suspended run. */
    private const APPROVAL_ACTIONS = ['approve', 'submitInput'];

    /**
     * The AJAX action names that do the same job outside the module access
     * check. Suffixed with `Action` because that is how AjaxRoutes.php spells
     * a target.
     */
    private const APPROVAL_AJAX_ACTIONS = ['resumeAction', 'submitInputAction', 'approveAction'];

    /**
     * Module name => the `access` it is meant to carry.
     *
     * `nrllm_aitasks` is deliberately reachable for non-admins (ADR-131): the
     * module must be ticked in be_groups AND the actor must hold
     * `agent_approve`. Changing an entry here is a decision about who may
     * decide other users' runs — make it in ADR-130 first.
     */
    private const EXPECTED_APPROVAL_MODULES = [
        'nrllm_aitasks' => 'user',
        'nrllm_runs'    => 'admin',
    ];

    /**
     * The controllers that reach `AgentRuntime::approve()`/`submitInput()`.
     *
     * This is what an approval surface IS, independent of how it is exposed: a
     * new entry here is a new place a human decides someone else's run, whether
     * it is registered as a module, as an AJAX route, or not yet at all.
     */
    private const EXPECTED_APPROVAL_CONTROLLERS = [
        'AgentRunController',
        'ToolPlaygroundController',
    ];

    /** @var array<string, string> module name => its block of the array literal */
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
    public function everyModuleRegisteringAnApprovalActionIsInTheInventory(): void
    {
        self::assertSame(
            self::EXPECTED_APPROVAL_MODULES,
            $this->registeredApprovalSurfaces(),
            'The set of backend modules from which a human decides an agent run has changed. '
                . 'Update EXPECTED_APPROVAL_MODULES *and* ADR-130 constraint 3 in the same change — '
                . 'that constraint enumerates these surfaces, and an enumeration nobody asserts goes stale silently.',
        );
    }

    #[Test]
    public function everyControllerReachingTheApprovalRuntimeIsInTheInventory(): void
    {
        self::assertSame(
            self::EXPECTED_APPROVAL_CONTROLLERS,
            $this->controllersReachingTheApprovalRuntime(),
            'The set of controllers that reach AgentRuntime::approve()/submitInput() has changed. '
                . 'That set IS the approval surface inventory — a module registration or an AJAX route only '
                . 'exposes it. Update EXPECTED_APPROVAL_CONTROLLERS *and* ADR-130 constraint 3 together.',
        );
    }

    #[Test]
    public function everyAjaxApprovalRouteGuardsItselfWithDenyNonAdmin(): void
    {
        $routes = $this->ajaxApprovalTargets();
        self::assertNotSame(
            [],
            $routes,
            'No AJAX approval route found — has AjaxRoutes.php been reformatted? '
                . 'An empty scan would let this test pass while guarding nothing.',
        );

        foreach ($routes as $route => [$class, $method]) {
            self::assertTrue(
                $this->opensWithDenyNonAdmin($class, $method),
                'AJAX route ' . $route . ' targets ' . $class . '::' . $method
                    . ', which does not open with denyNonAdmin(). AJAX routes bypass the module access '
                    . 'check (ADR-037), so the guard is the only gate on that path.',
            );
        }
    }

    #[Test]
    public function adr130NamesEveryApprovalSurfaceItsConstraintEnumerates(): void
    {
        $constraint = $this->adr130ConstraintThree();

        foreach (\array_keys($this->registeredApprovalSurfaces()) as $module) {
            // Whole identifier, not substring: `nrllm_run` would otherwise be
            // satisfied by the `nrllm_runs` already in the text, and a fourth
            // surface whose name merely extends an existing one would pass the
            // one assertion whose job is to notice it.
            self::assertMatchesRegularExpression(
                '/(?<![a-z_])' . \preg_quote($module, '/') . '(?![a-z_])/',
                $constraint,
                'ADR-130 constraint 3 enumerates the approval surfaces but never mentions ' . $module
                    . ' as an identifier of its own. The record is the thing that goes stale; name it there.',
            );
        }

        self::assertStringContainsString(
            'denyNonAdmin',
            $constraint,
            'ADR-130 constraint 3 no longer mentions the AJAX guard, which is the other half of the inventory.',
        );
    }

    /**
     * @return array<string, string> module name => its `access` value, sorted by name
     */
    private function registeredApprovalSurfaces(): array
    {
        $surfaces = [];
        foreach ($this->modules as $name => $block) {
            if (!$this->registersAnApprovalAction($block)) {
                continue;
            }

            self::assertSame(
                1,
                \preg_match("/'access' => '(\w+)'/", $block, $matches),
                'Module ' . $name . ' registers an approval action but declares no access.',
            );

            $surfaces[$name] = $matches[1];
        }

        \ksort($surfaces);

        return $surfaces;
    }

    /**
     * True when this module block registers one of the approval actions, under
     * ANY controller.
     *
     * Deliberately not filtered to AgentRunController. That filter was here to
     * keep an unrelated controller's `approve` from being mistaken for this one,
     * and it is exactly what let a fourth surface in: registering
     * `approve`/`submitInput` under a different controller made the module
     * invisible to every assertion in this file. A false positive here costs one
     * conversation about whether that action really is an approval; a false
     * negative costs the guarantee the record claims. If an unrelated `approve`
     * ever appears, decide it in ADR-130 rather than narrowing this back.
     */
    private function registersAnApprovalAction(string $block): bool
    {
        foreach (self::APPROVAL_ACTIONS as $action) {
            if (\str_contains($block, "'" . $action . "'")) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string> short class names, sorted
     */
    private function controllersReachingTheApprovalRuntime(): array
    {
        $found = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::CONTROLLERS_DIR, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $source = \file_get_contents($file->getPathname());
            self::assertIsString($source);

            if (\preg_match('/->(?:approve|submitInput)\(/', $source) === 1) {
                $found[] = $file->getBasename('.php');
            }
        }

        \sort($found);

        return $found;
    }

    /**
     * @return array<string, array{0: string, 1: string}> route name => [controller short name, method]
     */
    private function ajaxApprovalTargets(): array
    {
        $source = \file_get_contents(self::AJAX_ROUTES_FILE);
        self::assertIsString($source);

        \preg_match_all(
            "/'([a-z0-9_]+)' => \[.*?'target' => (\w+)::class \. '::(\w+)'/s",
            $source,
            $matches,
            PREG_SET_ORDER,
        );
        self::assertNotSame([], $matches, 'No AJAX routes found — has AjaxRoutes.php been reformatted?');

        $targets = [];
        foreach ($matches as $match) {
            if (!\in_array($match[3], self::APPROVAL_AJAX_ACTIONS, true)) {
                continue;
            }

            $targets[$match[1]] = [$match[2], $match[3]];
        }

        return $targets;
    }

    /**
     * The first statement of the method must be the admin guard. Read from the
     * five lines after the signature: the guard is `if (($deny = ...))`, so a
     * later occurrence deeper in the body would not be "opens with".
     */
    private function opensWithDenyNonAdmin(string $class, string $method): bool
    {
        $source = \file_get_contents($this->classFile($class));
        self::assertIsString($source);

        $offset = \strpos($source, 'public function ' . $method . '(');
        if ($offset === false) {
            self::fail($class . ' has no method ' . $method . '().');
        }

        $head = \implode("\n", \array_slice(\explode("\n", \substr($source, $offset)), 0, 5));

        return \str_contains($head, 'denyNonAdmin');
    }

    private function classFile(string $shortName): string
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::CLASSES_DIR, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getFilename() === $shortName . '.php') {
                return $file->getPathname();
            }
        }

        self::fail('No class file found for ' . $shortName . ' under Classes/.');
    }

    /**
     * Constraint 3 of ADR-130 — the enumerated list item, up to constraint 4.
     */
    private function adr130ConstraintThree(): string
    {
        $source = \file_get_contents(self::ADR_FILE);
        self::assertIsString($source);

        self::assertSame(
            1,
            \preg_match('/^3\. \*\*``agent_approve``.*?(?=^4\. \*\*)/ms', $source, $matches),
            'ADR-130 constraint 3 could not be located. It is what enumerates the approval surfaces; '
                . 'if it was renumbered or renamed, update this test with it.',
        );

        return $matches[0];
    }
}
