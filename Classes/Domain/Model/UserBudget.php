<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

/**
 * Per-backend-user AI budget ceilings.
 *
 * Daily and monthly limits are independent; a user request must clear
 * BOTH windows. `0` in any bucket means "unlimited on that axis".
 *
 * This is a **ceiling**, not a counter: actual usage is aggregated on
 * demand from tx_nrllm_service_usage so we never drift from a source
 * of truth that is already being written for every request.
 */
class UserBudget extends AbstractEntity
{
    protected int $beUser = 0;

    protected int $maxRequestsPerDay = 0;
    protected int $maxTokensPerDay = 0;
    protected float $maxCostPerDay = 0.0;

    protected int $maxRequestsPerMonth = 0;
    protected int $maxTokensPerMonth = 0;
    protected float $maxCostPerMonth = 0.0;

    protected bool $isActive = true;

    protected int $tstamp = 0;
    protected int $crdate = 0;

    public function getBeUser(): int
    {
        return $this->beUser;
    }

    public function setBeUser(int $beUser): void
    {
        $this->beUser = max(0, $beUser);
    }

    public function getMaxRequestsPerDay(): int
    {
        return $this->maxRequestsPerDay;
    }

    public function setMaxRequestsPerDay(int $value): void
    {
        $this->maxRequestsPerDay = max(0, $value);
    }

    public function getMaxTokensPerDay(): int
    {
        return $this->maxTokensPerDay;
    }

    public function setMaxTokensPerDay(int $value): void
    {
        $this->maxTokensPerDay = max(0, $value);
    }

    public function getMaxCostPerDay(): float
    {
        return $this->maxCostPerDay;
    }

    public function setMaxCostPerDay(float $value): void
    {
        $this->maxCostPerDay = max(0.0, $value);
    }

    public function getMaxRequestsPerMonth(): int
    {
        return $this->maxRequestsPerMonth;
    }

    public function setMaxRequestsPerMonth(int $value): void
    {
        $this->maxRequestsPerMonth = max(0, $value);
    }

    public function getMaxTokensPerMonth(): int
    {
        return $this->maxTokensPerMonth;
    }

    public function setMaxTokensPerMonth(int $value): void
    {
        $this->maxTokensPerMonth = max(0, $value);
    }

    public function getMaxCostPerMonth(): float
    {
        return $this->maxCostPerMonth;
    }

    public function setMaxCostPerMonth(float $value): void
    {
        $this->maxCostPerMonth = max(0.0, $value);
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getIsActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): void
    {
        $this->isActive = $isActive;
    }

    public function getTstamp(): int
    {
        return $this->tstamp;
    }

    public function getCrdate(): int
    {
        return $this->crdate;
    }

    public function hasAnyLimit(): bool
    {
        return $this->maxRequestsPerDay > 0
            || $this->maxTokensPerDay > 0
            || $this->maxCostPerDay > 0.0
            || $this->maxRequestsPerMonth > 0
            || $this->maxTokensPerMonth > 0
            || $this->maxCostPerMonth > 0.0;
    }
}
