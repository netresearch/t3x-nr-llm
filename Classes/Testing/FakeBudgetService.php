<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Testing;

use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Throwable;

/**
 * Consumer-facing test double for {@see BudgetServiceInterface}.
 *
 * Ships in the runtime-autoloaded `Netresearch\NrLlm\Testing\` namespace so
 * downstream extensions that gate on the budget check can fake it in their unit
 * tests instead of hand-rolling a double — exactly the double that broke across
 * consumers when {@see BudgetServiceInterface::check()} gained its
 * `?LlmConfiguration` parameter. Implementing the real interface means PHPStan
 * keeps this double in sync with the production contract, so a future signature
 * change fails the build here instead of silently in every consumer.
 *
 * {@see self::check()} returns {@see $checkResult} (defaulting to an allowing
 * result) and records every call in {@see $checkCalls}; set {@see $throwable} to
 * make the next call throw.
 *
 * Not a DI service: excluded from container autoconfiguration in
 * `Configuration/Services.yaml`. It is a fixture for consumer test suites,
 * never wire it into production.
 */
final class FakeBudgetService implements BudgetServiceInterface
{
    /** Canned result; a default allowing result is returned when left null. */
    public ?BudgetCheckResult $checkResult = null;

    /**
     * When set, the next call throws this instead of returning. Cleared before
     * throwing, so subsequent calls return the canned result again.
     */
    public ?Throwable $throwable = null;

    /** @var list<array{beUserUid: int, plannedCost: float, configuration: ?LlmConfiguration}> */
    public array $checkCalls = [];

    public function check(int $beUserUid, float $plannedCost = 0.0, ?LlmConfiguration $configuration = null): BudgetCheckResult
    {
        $this->checkCalls[] = ['beUserUid' => $beUserUid, 'plannedCost' => $plannedCost, 'configuration' => $configuration];

        if ($this->throwable instanceof Throwable) {
            $throwable = $this->throwable;
            $this->throwable = null;

            throw $throwable;
        }

        return $this->checkResult ?? BudgetCheckResult::allowed();
    }
}
