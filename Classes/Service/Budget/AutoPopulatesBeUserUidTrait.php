<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Budget;

use Netresearch\NrLlm\Service\Option\BudgetAwareOptionsInterface;

/**
 * Shared auto-population logic for feature services (REC #4).
 *
 * `CompletionService`, `EmbeddingService` and `VisionService` all
 * follow the same pattern: when the caller did not set a `beUserUid`
 * on the options, consult the optional resolver and forward the
 * resolved uid onto the options via the typed `withBeUserUid()`
 * setter. Centralising it in a trait keeps the consumers focused on
 * their per-feature logic and makes future tweaks (e.g. logging
 * around resolution failure, propagating an explicit "0 = anonymous"
 * value) a single-site change.
 *
 * `TranslationService` has a different shape (it builds *new*
 * `ChatOptions` from `TranslationOptions` rather than mutating an
 * existing options object), so it uses its own one-line helper
 * instead of consuming this trait.
 *
 * Consumers must:
 * - Declare a `?BackendUserContextResolverInterface $beUserContextResolver`
 *   constructor property (private, optional, null default).
 * - Call `autoPopulateBeUserUid($options)` before forwarding the
 *   options to `LlmServiceManager`.
 */
trait AutoPopulatesBeUserUidTrait
{
    /**
     * @template T of BudgetAwareOptionsInterface
     *
     * @param T $options
     *
     * @return T
     */
    private function autoPopulateBeUserUid(BudgetAwareOptionsInterface $options): BudgetAwareOptionsInterface
    {
        if ($options->getBeUserUid() !== null || $this->beUserContextResolver === null) {
            return $options;
        }

        $resolved = $this->beUserContextResolver->resolveBeUserUid();
        if ($resolved === null) {
            return $options;
        }

        return $options->withBeUserUid($resolved);
    }
}
