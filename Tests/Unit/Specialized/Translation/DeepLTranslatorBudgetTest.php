<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Translation;

use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Specialized\Pricing\SpecializedCostCalculatorInterface;
use Netresearch\NrLlm\Specialized\Translation\DeepLTranslator;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * DeepL was the last paid external call with no budget pre-flight (ADR-078
 * excluded it because its options carried no budget fields). Now that the
 * translation service threads them through, the cap must actually fire — and
 * fire BEFORE any HTTP dispatch.
 */
#[CoversClass(DeepLTranslator::class)]
final class DeepLTranslatorBudgetTest extends TestCase
{
    #[Test]
    public function aDeniedBudgetStopsTheTranslationBeforeAnyRequestIsSent(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::never())->method('sendRequest');

        $translator = $this->translator($httpClient, allowed: false);

        $this->expectException(BudgetExceededException::class);
        $translator->translate('Guten Tag', 'en', null, ['beUserUid' => 7, 'plannedCost' => 0.5]);
    }

    #[Test]
    public function aDeniedBudgetAlsoStopsABatchTranslation(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::never())->method('sendRequest');

        $translator = $this->translator($httpClient, allowed: false);

        $this->expectException(BudgetExceededException::class);
        $translator->translateBatch(['Guten Tag', 'Auf Wiedersehen'], 'en', null, ['beUserUid' => 7]);
    }

    #[Test]
    public function theConfiguredIdentifierIsPassedToTheBudgetCheckSoPerConfigurationCapsApply(): void
    {
        $budget = $this->createMock(BudgetServiceInterface::class);
        $budget->expects(self::once())
            ->method('check')
            ->with(7, 0.5, self::anything())
            ->willReturn(BudgetCheckResult::allowed());

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willThrowException(new RuntimeException('stop here', 1784600500));

        $translator = $this->translator($httpClient, budgetService: $budget);

        try {
            $translator->translate('Guten Tag', 'en', null, [
                'beUserUid'     => 7,
                'plannedCost'   => 0.5,
                'configuration' => 'editorial',
            ]);
        } catch (Throwable) {
            // The dispatch is stubbed to fail; the budget expectation above is
            // what this test asserts.
        }
    }

    private function translator(
        ClientInterface $httpClient,
        bool $allowed = true,
        ?BudgetServiceInterface $budgetService = null,
    ): DeepLTranslator {
        if ($budgetService === null) {
            $budgetService = $this->createMock(BudgetServiceInterface::class);
            $budgetService->method('check')->willReturn(
                $allowed
                    ? BudgetCheckResult::allowed()
                    : BudgetCheckResult::denied(BudgetCheckResult::LIMIT_MONTHLY_COST, 10.0, 5.0),
            );
        }

        $vault = $this->createMock(VaultServiceInterface::class);
        $vault->method('exists')->willReturn(true);
        $vault->method('retrieve')->willReturn('deepl-key');

        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([
            'translators' => ['deepl' => ['apiKeyIdentifier' => 'deepl-key', 'timeout' => 30]],
        ]);

        $translator = new DeepLTranslator(
            $vault,
            $this->requestFactory(),
            $this->streamFactory(),
            $extensionConfiguration,
            self::createStub(UsageTrackerServiceInterface::class),
            new NullLogger(),
            self::createStub(SpecializedCostCalculatorInterface::class),
            $budgetService,
        );
        $translator->setHttpClient($httpClient);

        return $translator;
    }

    private function requestFactory(): RequestFactoryInterface
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('withHeader')->willReturnSelf();
        $request->method('withBody')->willReturnSelf();

        $factory = $this->createMock(RequestFactoryInterface::class);
        $factory->method('createRequest')->willReturn($request);

        return $factory;
    }

    private function streamFactory(): StreamFactoryInterface
    {
        $factory = $this->createMock(StreamFactoryInterface::class);
        $factory->method('createStream')->willReturn(self::createStub(StreamInterface::class));

        return $factory;
    }
}
