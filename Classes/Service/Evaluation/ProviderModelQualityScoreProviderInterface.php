<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

/**
 * Optional provider-instance-aware quality capability (ADR-220). @internal.
 */
interface ProviderModelQualityScoreProviderInterface extends ModelQualityScoreProviderInterface
{
    public function getQualityScoreForProviderModel(
        string $providerId,
        string $modelId,
    ): ?float;
}
