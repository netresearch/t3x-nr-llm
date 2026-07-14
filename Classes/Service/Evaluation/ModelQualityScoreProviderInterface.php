<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

/**
 * Supplies an aggregated quality score per model for quality-aware routing
 * (ADR-060).
 *
 * The score is derived from stored evaluation results and normalised to
 * 0.0-1.0; null means the model has no evaluation data and therefore no
 * quality signal. Kept as an interface so routing can be tested without a
 * database and so an extension can substitute a different quality source.
 */
interface ModelQualityScoreProviderInterface
{
    public function getQualityScore(string $modelId): ?float;
}
