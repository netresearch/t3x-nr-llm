<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Middleware;

use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UserBudget;
use Netresearch\NrLlm\Domain\Repository\UserBudgetRepository;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Provider\Middleware\BudgetMiddleware;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Provider\Middleware\UsageMiddleware;
use Netresearch\NrLlm\Service\Budget\BudgetUsageWindowsInterface;
use Netresearch\NrLlm\Service\BudgetService;
use Netresearch\NrLlm\Service\CallMetadataFactory;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Testing\FakeBudgetService;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * The pre-flight of a call whose request was already counted (ADR-233): the
 * revision of a DeepL translation runs after DeepL's own request row, so at
 * N−1 of N requests used beforehand the translation passes and the revision
 * must not be refused for a request it does not add.
 *
 * The middleware and the budget service are the real ones; only the usage
 * windows and the budget record are doubles.
 */
#[CoversClass(BudgetMiddleware::class)]
#[CoversClass(BudgetService::class)]
#[CoversClass(CallMetadataFactory::class)]
final class BudgetMiddlewareCountedRequestTest extends AbstractUnitTestCase
{
    private const LIMIT = 5;

    #[Test]
    public function aRevisionAfterTheLastAllowedTranslationIsAllowed(): void
    {
        // N−1 used before the translation; DeepL's row made it N. A user whose
        // budget is spent is still refused by the request check of the NEXT
        // translation, which counts.
        $middleware = $this->middleware(requestsUsed: self::LIMIT, costUsed: 0.0, costLimit: 0.0);

        self::assertSame('ran', $middleware->handle($this->context(alreadyCounted: true), static fn (): string => 'ran'));
    }

    #[Test]
    public function aCallWhoseRequestIsNotYetCountedIsStillRefusedAtTheLimit(): void
    {
        $middleware = $this->middleware(requestsUsed: self::LIMIT, costUsed: 0.0, costLimit: 0.0);

        $this->expectException(BudgetExceededException::class);
        $middleware->handle($this->context(alreadyCounted: false), static fn (): string => 'ran');
    }

    /**
     * The language detection inside a translation and the LLM translator's
     * chat call only suppress the request COUNT; their parent writes its row
     * afterwards, so their pre-flight keeps counting the request.
     */
    #[Test]
    public function aSuppressedRequestCountAloneKeepsThePreFlightAsItWas(): void
    {
        $metadata = (new CallMetadataFactory())->requestCount((new ChatOptions())->withSuppressRequestCount(true));
        self::assertSame([UsageMiddleware::METADATA_SKIP_REQUEST_COUNT => true], $metadata);

        $middleware = $this->middleware(requestsUsed: self::LIMIT, costUsed: 0.0, costLimit: 0.0);

        $this->expectException(BudgetExceededException::class);
        $middleware->handle(
            ProviderCallContext::for(ProviderOperation::Chat, [BudgetMiddleware::METADATA_BE_USER_UID => 42] + $metadata),
            static fn (): string => 'ran',
        );
    }

    #[Test]
    public function anExhaustedCostLimitStillRefusesARevision(): void
    {
        $middleware = $this->middleware(requestsUsed: 1, costUsed: 2.0, costLimit: 2.0);

        try {
            $middleware->handle($this->context(alreadyCounted: true, plannedCost: 0.5), static fn (): string => 'ran');
            self::fail('the cost limit must refuse the call');
        } catch (BudgetExceededException $e) {
            self::assertSame(BudgetCheckResult::LIMIT_DAILY_COST, $e->result->exceededLimit);
        }
    }

    /**
     * DeepL's request row carries no configuration, so the configuration's
     * usage does not hold it: its per-day cap must count the revision, or a
     * shared cap would never stop revisions.
     */
    #[Test]
    public function aConfigurationsRequestCapStillCountsTheRevision(): void
    {
        $configuration = new LlmConfiguration();
        $configuration->_setProperty('uid', 9);
        $configuration->setIdentifier('default');
        $configuration->setMaxRequestsPerDay(self::LIMIT);

        $middleware = $this->middleware(requestsUsed: 0, costUsed: 0.0, costLimit: 0.0, configurationRequests: self::LIMIT);
        $metadata   = (new CallMetadataFactory())->requestCount((new ChatOptions())->withRequestAlreadyCounted(true));

        try {
            $middleware->handle(
                ProviderCallContext::forConfiguration(ProviderOperation::Chat, $configuration, [BudgetMiddleware::METADATA_BE_USER_UID => 42] + $metadata),
                static fn (): string => 'ran',
            );
            self::fail('the configuration cap must refuse the revision');
        } catch (BudgetExceededException $e) {
            self::assertSame(BudgetCheckResult::LIMIT_CONFIGURATION_DAILY_REQUESTS, $e->result->exceededLimit);
        }
    }

    #[Test]
    public function aRevisionAfterTheLastAllowedTranslationOfTheMonthIsAllowed(): void
    {
        $middleware = $this->middleware(requestsUsed: self::LIMIT, costUsed: 0.0, costLimit: 0.0, monthlyOnly: true);

        self::assertSame('ran', $middleware->handle($this->context(alreadyCounted: true), static fn (): string => 'ran'));
    }

    #[Test]
    public function aCallNotYetCountedIsRefusedAtTheMonthlyLimit(): void
    {
        $middleware = $this->middleware(requestsUsed: self::LIMIT, costUsed: 0.0, costLimit: 0.0, monthlyOnly: true);

        try {
            $middleware->handle($this->context(alreadyCounted: false), static fn (): string => 'ran');
            self::fail('the monthly request limit must refuse the call');
        } catch (BudgetExceededException $e) {
            self::assertSame(BudgetCheckResult::LIMIT_MONTHLY_REQUESTS, $e->result->exceededLimit);
        }
    }

    /**
     * Another implementation of the `@api` budget interface knows only
     * `check()`, which counts the request: the conservative answer.
     */
    #[Test]
    public function aBudgetServiceWithoutTheCountedRequestCheckIsAskedCheck(): void
    {
        $fake = new FakeBudgetService();

        (new BudgetMiddleware($fake))->handle($this->context(alreadyCounted: true), static fn (): string => 'ran');

        self::assertCount(1, $fake->checkCalls);
    }

    private function context(bool $alreadyCounted, float $plannedCost = 0.0): ProviderCallContext
    {
        $options = (new ChatOptions(beUserUid: 42, plannedCost: $plannedCost))->withRequestAlreadyCounted($alreadyCounted);
        $factory = new CallMetadataFactory();

        return ProviderCallContext::for(
            ProviderOperation::Chat,
            $factory->budget($options->getBeUserUid(), $options->getPlannedCost()) + $factory->requestCount($options),
        );
    }

    private function middleware(int $requestsUsed, float $costUsed, float $costLimit, int $configurationRequests = 0, bool $monthlyOnly = false): BudgetMiddleware
    {
        $budget = new UserBudget();
        $budget->setBeUser(42);
        $budget->setIsActive(true);
        if ($monthlyOnly) {
            $budget->setMaxRequestsPerMonth(self::LIMIT);
        } else {
            $budget->setMaxRequestsPerDay(self::LIMIT);
            $budget->setMaxCostPerDay($costLimit);
        }

        $repository = self::createStub(UserBudgetRepository::class);
        $repository->method('findOneByBeUser')->willReturn($budget);

        $usageWindows = self::createStub(BudgetUsageWindowsInterface::class);
        $usageWindows->method('aggregate')->willReturn([
            'daily'   => ['requests' => $requestsUsed, 'tokens' => 0, 'cost' => $costUsed],
            'monthly' => ['requests' => $requestsUsed, 'tokens' => 0, 'cost' => $costUsed],
        ]);
        $usageWindows->method('aggregateForConfiguration')->willReturn(['requests' => $configurationRequests, 'tokens' => 0, 'cost' => 0.0]);

        return new BudgetMiddleware(new BudgetService($repository, $usageWindows));
    }
}
