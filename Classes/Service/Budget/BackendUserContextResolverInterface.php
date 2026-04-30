<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Budget;

/**
 * Resolves the current backend user identifier for budget pre-flight checks.
 *
 * Feature services (CompletionService, EmbeddingService, ...) consult this
 * resolver when callers did not explicitly set a `beUserUid` on the options
 * object. The resolver is the only seam between the LLM call path and the
 * `$GLOBALS['BE_USER']` superglobal — keeping it behind an interface lets
 * tests run without a TYPO3 backend bootstrap and lets non-BE callers
 * (CLI, scheduler, FE) wire a no-op implementation.
 *
 * Returning `null` is the documented "no BE user in scope" signal — the
 * BudgetMiddleware treats absent / 0 / non-int as "skip the check", so
 * resolvers that cannot determine a user MUST return `null` rather than
 * fabricating a default (returning 0 also works but `null` makes the
 * "unknown" intent explicit at every call site).
 */
interface BackendUserContextResolverInterface
{
    /**
     * Return the current backend user uid, or null when no BE user is
     * authenticated in the current request scope.
     */
    public function resolveBeUserUid(): ?int;
}
