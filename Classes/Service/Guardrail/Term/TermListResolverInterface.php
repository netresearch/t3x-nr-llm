<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail\Term;

/**
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
interface TermListResolverInterface
{
    /**
     * The visible term lists naming this configuration, oldest first.
     *
     * @return list<TermListRule>
     */
    public function forConfiguration(int $configurationUid): array;
}
