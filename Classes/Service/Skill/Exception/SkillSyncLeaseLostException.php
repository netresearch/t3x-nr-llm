<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Service\Skill\Exception;

use Netresearch\NrLlm\Exception\NrLlmExceptionInterface;
use RuntimeException;

/**
 * @internal A worker no longer owns the persisted lease (ADR-221).
 */
final class SkillSyncLeaseLostException extends RuntimeException implements NrLlmExceptionInterface
{
    public function __construct()
    {
        parent::__construct(
            'Skill synchronization lease was lost; collected data was not published.',
            1781650221,
        );
    }
}
