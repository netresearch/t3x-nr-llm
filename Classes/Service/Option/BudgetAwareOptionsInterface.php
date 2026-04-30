<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Option;

/**
 * Marker for option types that carry budget pre-flight metadata
 * (REC #4). Implemented by every option type whose call path reaches
 * `BudgetMiddleware`: `ChatOptions` (and subclass `ToolOptions`),
 * `EmbeddingOptions`, `VisionOptions`, `TranslationOptions`.
 *
 * The interface lets shared infrastructure (e.g. resolver-driven
 * auto-population in feature services, or `LlmServiceManager`'s
 * generic metadata builder) work across option types without
 * narrowing to a concrete class. Implementers MUST keep these fields
 * out of `toArray()` — they are pipeline metadata, not provider wire
 * payload.
 */
interface BudgetAwareOptionsInterface
{
    public function getBeUserUid(): ?int;

    public function getPlannedCost(): ?float;

    /**
     * Returns a new instance with `beUserUid` set. Validating
     * setters reject negative values (`0` is the documented
     * "anonymous / skip the check" marker; positive uids identify
     * real BE users; negative is a caller bug).
     */
    public function withBeUserUid(int $beUserUid): static;

    /**
     * Returns a new instance with `plannedCost` set. Validating
     * setters reject negative values (negative cost would credit
     * the per-day ceiling in `BudgetMiddleware` — i.e. a budget
     * bypass).
     */
    public function withPlannedCost(float $plannedCost): static;
}
