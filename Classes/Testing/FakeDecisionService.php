<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Testing;

use Netresearch\NrLlm\Exception\LogicException;
use Netresearch\NrLlm\Service\Decision\DecisionRequest;
use Netresearch\NrLlm\Service\Decision\DecisionResult;
use Netresearch\NrLlm\Service\Decision\DecisionServiceInterface;
use Throwable;

/**
 * Consumer-facing test double for {@see DecisionServiceInterface} (ADR-211).
 *
 * {@see evaluate()} returns {@see $results} in FIFO order — queue one per
 * expected call — and records every request in {@see $requests}. Set
 * {@see $throwable} to make the next call throw instead, which is how a test
 * exercises the caller's handling of a failed decision.
 *
 * It returns what was queued and checks nothing: not that the profile exists,
 * not that the subject carries its required fields, not that the answers
 * match its questions. A test of that contract belongs against the real
 * service.
 *
 * Not a DI service: excluded from container autoconfiguration in
 * `Configuration/Services.yaml`. It is a fixture for consumer test suites,
 * never wire it into production.
 *
 * @api
 */
final class FakeDecisionService implements DecisionServiceInterface
{
    /** @var list<DecisionResult> */
    public array $results = [];

    /** @var list<DecisionRequest> */
    public array $requests = [];

    /**
     * When set, the next call throws this. Cleared before throwing, so the
     * call after it returns queued results again.
     */
    public ?Throwable $throwable = null;

    /**
     * When set, assertAvailable() throws this — how a test exercises a caller
     * that checks availability before spending anything. Not cleared.
     */
    public ?Throwable $unavailable = null;

    public function assertAvailable(string $profile, ?string $configuration = null): void
    {
        if ($this->unavailable instanceof Throwable) {
            throw $this->unavailable;
        }
    }

    public function evaluate(DecisionRequest $request): DecisionResult
    {
        $this->requests[] = $request;

        if ($this->throwable instanceof Throwable) {
            $throwable = $this->throwable;
            $this->throwable = null;

            throw $throwable;
        }

        return array_shift($this->results)
            ?? throw new LogicException(sprintf('%s::evaluate() was called but no result was queued in $results.', self::class), 1795211051);
    }
}
