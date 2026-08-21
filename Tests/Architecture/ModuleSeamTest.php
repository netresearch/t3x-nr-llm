<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Architecture;

use Netresearch\NrLlm\Command\CancelAgentRunCommand;
use Netresearch\NrLlm\Command\ImportMcpCatalogueCommand;
use Netresearch\NrLlm\Command\PurgePrivacyDataCommand;
use Netresearch\NrLlm\Command\ReapStaleAgentRunsCommand;
use Netresearch\NrLlm\Form\Tca\ToolGroupItems;
use Netresearch\NrLlm\Service\Evaluation\LexicalSearchRetriever;
use Netresearch\NrLlm\Service\Overview\OverviewReadinessService;
use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Architectural tests for the horizontal module seams named in ADR-090.
 *
 * ADR-090 keeps nr_llm a single extension until 1.0 but requires the
 * architecture to stay *split-ready*, and names the candidate packages:
 * core, `nr_llm_specialized`, `nr_llm_tools`, `nr_llm_guardrail` and
 * `nr_llm_backend`. Every feature package depends on core and on nothing
 * else; the backend package sits on top of all of them.
 *
 * The other tests in this directory enforce the *vertical* layering
 * (Controller → Service → Provider / Domain). The seams *between* the
 * feature modules were, until this test, kept clean by review only — which
 * ADR-090 itself calls out as the gap, since new cross-module coupling is
 * what would turn a future extraction from a packaging change back into a
 * re-architecture.
 *
 * Namespace-to-package map used below, from the ADR's own scope column:
 *
 * - specialized: `Specialized`
 * - tools:       `Service\Tool`, `Service\Agent` (agent-run persistence and
 *                human-in-the-loop approval), `Service\Retrieval` (RAG
 *                site-search)
 * - guardrail:   `Service\Guardrail`
 * - backend:     `Controller`, `Widgets`
 *
 * What is enforced is directional, not symmetric:
 *
 * - specialized ↮ tools, both ways
 * - guardrail ↛ specialized, guardrail ↛ tools
 * - everything outside the backend ↛ backend
 *
 * The two remaining pairs are deliberately free: specialized → guardrail and
 * tools → guardrail are how the safety pipeline gets invoked at all, and
 * `AbstractSpecializedService` uses it today. The backend calling anything is
 * likewise legitimate. Do not "complete" this into a symmetric rule set.
 */
final class ModuleSeamTest
{
    private const NS_ROOT = 'Netresearch\NrLlm';

    private const NS_TESTS = 'Netresearch\NrLlm\Tests';

    private const NS_SPECIALIZED = 'Netresearch\NrLlm\Specialized';

    private const NS_TOOL = 'Netresearch\NrLlm\Service\Tool';

    private const NS_AGENT = 'Netresearch\NrLlm\Service\Agent';

    private const NS_RETRIEVAL = 'Netresearch\NrLlm\Service\Retrieval';

    private const NS_GUARDRAIL = 'Netresearch\NrLlm\Service\Guardrail';

    private const NS_CONTROLLER = 'Netresearch\NrLlm\Controller';

    private const NS_WIDGETS = 'Netresearch\NrLlm\Widgets';

    /**
     * The specialized services must not reach into the tool/agent module.
     *
     * Translation, image and speech are a self-contained package on top of
     * core. Reaching for the tool registry, the tool loop or agent-run
     * persistence from here would make `nr_llm_specialized` depend on
     * `nr_llm_tools`, which the ADR's dependency column forbids.
     */
    public function testSpecializedServicesDoNotDependOnTheToolModule(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS_SPECIALIZED))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace(self::NS_TOOL),
                Selector::inNamespace(self::NS_AGENT),
                Selector::inNamespace(self::NS_RETRIEVAL),
            )
            ->because('nr_llm_specialized depends on core only, never on nr_llm_tools (ADR-090).');
    }

    /**
     * The tool/agent module must not reach into the specialized services.
     *
     * A tool that needs translation, image generation or speech goes through
     * the pipeline the same way any other consumer does; importing
     * `Specialized\*` here would couple the two candidate packages in both
     * directions and make either extraction impossible.
     */
    public function testToolModuleDoesNotDependOnSpecializedServices(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::inNamespace(self::NS_TOOL),
                Selector::inNamespace(self::NS_AGENT),
                Selector::inNamespace(self::NS_RETRIEVAL),
            )
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace(self::NS_SPECIALIZED))
            ->because('nr_llm_tools depends on core only, never on nr_llm_specialized (ADR-090).');
    }

    /**
     * The guardrail pipeline must not depend on the modules it protects.
     *
     * Guardrails are invoked *by* the tool loop and *by* the specialized
     * services, never the reverse. A guardrail that imports a tool or a
     * specialized service would both invert that relationship and prevent
     * the safety pipeline from staying in core, which ADR-090 lists as the
     * likely outcome for `nr_llm_guardrail`.
     */
    public function testGuardrailModuleDoesNotDependOnTheModulesItProtects(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS_GUARDRAIL))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace(self::NS_TOOL),
                Selector::inNamespace(self::NS_AGENT),
                Selector::inNamespace(self::NS_RETRIEVAL),
                Selector::inNamespace(self::NS_SPECIALIZED),
            )
            ->because('Guardrails are invoked by the tool and specialized modules, never the reverse (ADR-090).');
    }

    /**
     * Nothing outside the backend package may depend on the backend UI.
     *
     * `nr_llm_backend` is the only package that depends on the others, so a
     * provider, a specialized service or any core service importing a
     * controller or a dashboard widget would make the backend
     * non-extractable.
     *
     * The subject is expressed as "everything under the extension namespace
     * except the backend itself and the test suite" rather than as a list of
     * the namespaces that exist today, so a top-level namespace added later is
     * covered without anyone remembering this rule. `ServiceLayerTest` already
     * asserts the Service → Controller half as a layering rule; the seam
     * statement is about packages and also covers the widget namespace.
     */
    public function testNothingOutsideTheBackendDependsOnIt(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::AllOf(
                Selector::inNamespace(self::NS_ROOT),
                Selector::NoneOf(
                    Selector::inNamespace(self::NS_CONTROLLER),
                    Selector::inNamespace(self::NS_WIDGETS),
                    Selector::inNamespace(self::NS_TESTS),
                ),
            ))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace(self::NS_CONTROLLER),
                Selector::inNamespace(self::NS_WIDGETS),
            )
            ->because('nr_llm_backend depends on the other packages; nothing outside it may depend on the backend (ADR-090).');
    }

    /**
     * Core must not depend on the tool/agent/retrieval module — the remaining
     * seam the roadmap names, and the prerequisite for the post-1.0 split:
     * every feature package depends on core, so a core class reaching back
     * into `nr_llm_tools` would make the two packages mutually dependent.
     *
     * "Core" here is the remainder: everything that is not one of the mapped
     * module namespaces above. The classes listed below are excluded BY NAME
     * rather than by directory, because they are the tool module's own
     * operational surface living in shared directories — in a split each moves
     * WITH its module, so their coupling is ownership, not leakage. The list is
     * not counted here: it said "seven" while carrying six, because a class
     * left it without the number following.
     *
     * - `CancelAgentRunCommand`, `ReapStaleAgentRunsCommand` — CLI entry
     *   points of the agent runtime (→ nr_llm_tools)
     * - `ImportMcpCatalogueCommand` — the CLI entry point of the MCP
     *   catalogue import, the same operation the MCP Servers module runs
     *   (→ nr_llm_tools)
     * - `PurgePrivacyDataCommand` — sweeps, among others, the agent-run
     *   tables through their repository (→ split into per-module sweeps when
     *   the packages separate)
     * - `ToolGroupItems` — the TCA itemsProcFunc that lists tool groups
     *   (→ nr_llm_tools)
     * - `LexicalSearchRetriever` — the evaluation harness's baseline over the
     *   retrieval API (→ nr_llm_tools, retrieval scope)
     * - `OverviewReadinessService` — feeds the backend Overview module's
     *   readiness card (→ nr_llm_backend)
     * `EffectivePolicyReadout` used to be on this list. It is not any more, and
     * the reason is worth keeping: it crossed the seam only because
     * `TrustZoneResolver` and `DataClassEnforcementResolver` sat in the tool
     * namespace, where the tool gate — their first consumer — had put them.
     * Neither is about tools. A trust zone is a property of a provider, and the
     * enforcement switch governs an axis, not a tool. ADR-144 needed both from
     * core to gate the SEND path, which made the misfiling load-bearing rather
     * than cosmetic: they moved to `Service\Governance` and this exception
     * disappeared with them.
     *
     * A NEW core class that imports the tool module fails this rule; the
     * named list is the complete, deliberate exception set. Do not grow it
     * without recording where the class moves in a split — and check first
     * whether the dependency is really the tool module's, as that one was not.
     */
    public function testCoreDoesNotDependOnTheToolModule(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::AllOf(
                Selector::inNamespace(self::NS_ROOT),
                Selector::NoneOf(
                    Selector::inNamespace(self::NS_SPECIALIZED),
                    Selector::inNamespace(self::NS_TOOL),
                    Selector::inNamespace(self::NS_AGENT),
                    Selector::inNamespace(self::NS_RETRIEVAL),
                    Selector::inNamespace(self::NS_GUARDRAIL),
                    Selector::inNamespace(self::NS_CONTROLLER),
                    Selector::inNamespace(self::NS_WIDGETS),
                    Selector::inNamespace(self::NS_TESTS),
                    Selector::classname(CancelAgentRunCommand::class),
                    Selector::classname(ImportMcpCatalogueCommand::class),
                    Selector::classname(PurgePrivacyDataCommand::class),
                    Selector::classname(ReapStaleAgentRunsCommand::class),
                    Selector::classname(ToolGroupItems::class),
                    Selector::classname(LexicalSearchRetriever::class),
                    Selector::classname(OverviewReadinessService::class),
                ),
            ))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace(self::NS_TOOL),
                Selector::inNamespace(self::NS_AGENT),
                Selector::inNamespace(self::NS_RETRIEVAL),
            )
            ->because('Core is what every package depends on; core reaching back into nr_llm_tools would make the split circular (ADR-090).');
    }
}
