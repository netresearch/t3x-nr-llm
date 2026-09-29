<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Testing;

use LogicException;
use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionAnswer;
use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionSubject;
use Netresearch\NrLlm\Domain\ValueObject\Decision\ProbabilityKind;
use Netresearch\NrLlm\Service\Decision\DecisionException;
use Netresearch\NrLlm\Service\Decision\DecisionRequest;
use Netresearch\NrLlm\Service\Decision\DecisionResult;
use Netresearch\NrLlm\Testing\FakeDecisionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FakeDecisionService::class)]
final class FakeDecisionServiceTest extends TestCase
{
    private function decision(float $value): DecisionResult
    {
        return new DecisionResult('x.y', 1, 'judge', 'fake', 'm', ProbabilityKind::None, ['ok' => DecisionAnswer::yesNo('ok', $value)]);
    }

    #[Test]
    public function queuedResultsComeBackInOrderAndRequestsAreRecorded(): void
    {
        $fake = new FakeDecisionService();
        $fake->results = [$this->decision(1.0), $this->decision(0.0)];

        $request = new DecisionRequest('x.y', new DecisionSubject(candidate: 'c'));

        self::assertSame(1.0, $fake->evaluate($request)->answer('ok')->value);
        self::assertSame(0.0, $fake->evaluate($request)->answer('ok')->value);
        self::assertSame([$request, $request], $fake->requests);
    }

    #[Test]
    public function aSetThrowableIsThrownOnceThenCleared(): void
    {
        $fake = new FakeDecisionService();
        $fake->throwable = DecisionException::noConfiguration();
        $fake->results = [$this->decision(1.0)];

        $request = new DecisionRequest('x.y', new DecisionSubject());

        try {
            $fake->evaluate($request);
            self::fail('expected the queued throwable');
        } catch (DecisionException $e) {
            self::assertSame(DecisionException::NO_CONFIGURATION, $e->getCode());
        }

        self::assertSame(1.0, $fake->evaluate($request)->answer('ok')->value);
    }

    #[Test]
    public function anEmptyQueueIsAnExplicitTestError(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1795211051);

        (new FakeDecisionService())->evaluate(new DecisionRequest('x.y', new DecisionSubject()));
    }

    #[Test]
    public function availabilityFailsOnlyWhenAFailureIsSet(): void
    {
        $fake = new FakeDecisionService();
        $fake->assertAvailable('x.y');

        $fake->unavailable = DecisionException::noConfiguration();

        $this->expectExceptionCode(DecisionException::NO_CONFIGURATION);
        $fake->assertAvailable('x.y');
    }
}
