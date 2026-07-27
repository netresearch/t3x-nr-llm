<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Architecture;

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
 * A dependency in the *other* direction — tools calling a guardrail, the
 * backend calling anything — is legitimate and deliberately not restricted.
 */
final class ModuleSeamTest
{
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
            ->classes(Selector::inNamespace('Netresearch\NrLlm\Specialized'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('Netresearch\NrLlm\Service\Tool'),
                Selector::inNamespace('Netresearch\NrLlm\Service\Agent'),
                Selector::inNamespace('Netresearch\NrLlm\Service\Retrieval'),
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
                Selector::inNamespace('Netresearch\NrLlm\Service\Tool'),
                Selector::inNamespace('Netresearch\NrLlm\Service\Agent'),
                Selector::inNamespace('Netresearch\NrLlm\Service\Retrieval'),
            )
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('Netresearch\NrLlm\Specialized'))
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
            ->classes(Selector::inNamespace('Netresearch\NrLlm\Service\Guardrail'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('Netresearch\NrLlm\Service\Tool'),
                Selector::inNamespace('Netresearch\NrLlm\Service\Agent'),
                Selector::inNamespace('Netresearch\NrLlm\Service\Retrieval'),
                Selector::inNamespace('Netresearch\NrLlm\Specialized'),
            )
            ->because('Guardrails are invoked by the tool and specialized modules, never the reverse (ADR-090).');
    }

    /**
     * Nothing below the backend package may depend on the backend UI.
     *
     * `nr_llm_backend` is the only package that depends on the others, so a
     * provider, a specialized service or any core service importing a
     * controller or a dashboard widget would make the backend
     * non-extractable. `ServiceLayerTest` already asserts the Service →
     * Controller half of this as a layering rule; it is repeated here because
     * the seam statement is about packages, and because the widget namespace
     * is not covered there.
     */
    public function testFeatureModulesDoNotDependOnTheBackendPackage(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::inNamespace('Netresearch\NrLlm\Provider'),
                Selector::inNamespace('Netresearch\NrLlm\Service'),
                Selector::inNamespace('Netresearch\NrLlm\Specialized'),
            )
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('Netresearch\NrLlm\Controller'),
                Selector::inNamespace('Netresearch\NrLlm\Widgets'),
            )
            ->because('nr_llm_backend depends on the feature packages; none of them may depend on it (ADR-090).');
    }
}
