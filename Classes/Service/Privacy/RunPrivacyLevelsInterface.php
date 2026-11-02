<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Privacy;

use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;

/**
 * The privacy level content is stored at, per run and per provider (ADR-242).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
interface RunPrivacyLevelsInterface
{
    public function forRun(int $runUid): PrivacyLevel;

    public function forProvider(string $providerIdentifier): PrivacyLevel;
}
