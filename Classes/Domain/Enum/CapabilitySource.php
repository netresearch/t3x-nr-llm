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
 * `Catalog` is deliberately separate from `Discovery`, and it is the wider of
 * the two. It covers both ways the bundled static catalog ends up supplying
 * the tokens: an unreachable model endpoint, where `ModelDiscovery`
 * substitutes the catalog wholesale (see `DiscoveryResult::fallback()`), and a
 * reachable one that lists model ids and nothing else — OpenAI's and
 * Anthropic's do — where the model LIST is live but the capability tokens are
 * still the shipped guess. Folding either into `Discovery` would report an
 * assumption as a confirmation, the exact conflation this record exists to
 * end.
 *
 * @api
 */
enum CapabilitySource: string
{
    /**
     * The provider's own model endpoint reported these capabilities.
     */
    case Discovery = 'discovery';

    /**
     * The bundled static catalog supplied them, because the provider's model
     * endpoint either did not answer or does not report capabilities.
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
