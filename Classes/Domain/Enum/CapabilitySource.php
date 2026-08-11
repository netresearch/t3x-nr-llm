<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * Where a model's capability declaration came from (ADR-160).
 *
 * A capability on `tx_nrllm_model` used to be a bare token: an operator's
 * manual tick and a provider's own answer were indistinguishable afterwards,
 * so nothing could tell a verified capability from an assumed one. This enum
 * is the missing half.
 *
 * `Catalog` is deliberately separate from `Discovery`. When a provider's
 * model endpoint is unreachable, `ModelDiscovery` substitutes the static
 * catalog bundled with the extension (see `DiscoveryResult::fallback()`).
 * Folding that into `Discovery` would report an assumption as a
 * confirmation — the exact conflation this record exists to end.
 *
 * @api
 */
enum CapabilitySource: string
{
    /**
     * The provider's own model endpoint reported it.
     */
    case Discovery = 'discovery';

    /**
     * The bundled static catalog supplied it because the provider did not answer.
     */
    case Catalog = 'catalog';

    /**
     * Nobody confirmed it: an operator ticked it, or it predates provenance.
     */
    case Operator = 'operator';

    /**
     * Only a live provider answer counts as verification. The catalog is a
     * shipped guess and an operator tick is a claim.
     */
    public function isVerification(): bool
    {
        return $this === self::Discovery;
    }

    /**
     * The value persisted in `tx_nrllm_model.capabilities_source`, or null
     * for a record no confirmation ever touched.
     */
    public static function tryFromStored(string $value): ?self
    {
        return $value === '' ? null : self::tryFrom($value);
    }
}
