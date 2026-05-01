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
 * Architectural tests for Service layer dependencies.
 *
 * Codifies the layering convention documented in `Classes/AGENTS.md` and
 * ADR-026 (Provider Middleware Pipeline) so future drift is caught by the
 * test suite instead of code review:
 *
 *   1. Services must not call into Controllers (reverse-dependency guard).
 *   2. Services must reach providers through abstractions
 *      (`Provider\Contract`, `Provider\Middleware`, `LlmServiceManager`,
 *      `ProviderAdapterRegistry`) and never depend on concrete adapter
 *      classes — that would bypass Fallback / Budget / Usage / Cache
 *      middleware.
 *
 * Cross-feature coupling between `Service\Feature\*` classes is currently
 * guarded only by convention; a precise phpat rule for that case is left
 * to a follow-up because the obvious form ("Feature\\* must not depend on
 * Feature\\*") would also forbid the legitimate self-namespace dependency
 * of each service on its own `*ServiceInterface`.
 */
final class ServiceLayerTest
{
    /**
     * Services must not depend on Controllers.
     *
     * Controllers depend on services, never the other way around.
     */
    public function testServicesDoNotDependOnControllers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrLlm\Service'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('Netresearch\NrLlm\Controller'))
            ->because('Services must not depend on Controllers — reverse layering violates dependency inversion.');
    }

    /**
     * Services must not depend on concrete provider adapter classes.
     *
     * All provider invocation goes through `Provider\Contract\ProviderInterface`,
     * the `Provider\Middleware\MiddlewarePipeline`, or `ProviderAdapterRegistry`.
     * Importing a concrete adapter (e.g. `OpenAiProvider`) bypasses Fallback,
     * Budget, Usage, and Cache middleware — see ADR-026.
     *
     * The deny set is expressed as a regex over the FQCN so newly added
     * adapter classes (any `Netresearch\NrLlm\Provider\<Name>Provider`
     * directly under the `Provider` namespace, including `AbstractProvider`)
     * are caught automatically without anyone remembering to update this
     * test. Sub-namespaces (`Provider\Contract`, `Provider\Middleware`,
     * `Provider\Exception`) and sibling classes that don't end in
     * `Provider` (`ProviderAdapterRegistry`) are intentionally excluded.
     */
    public function testServicesDoNotDependOnConcreteProviderAdapters(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrLlm\Service'))
            ->shouldNotDependOn()
            ->classes(
                Selector::classname('/^Netresearch\\\\NrLlm\\\\Provider\\\\[A-Z][A-Za-z0-9]*Provider$/', true),
            )
            ->because('Services must invoke providers through ProviderInterface / MiddlewarePipeline / ProviderAdapterRegistry, never via concrete adapter classes (ADR-026).');
    }
}
