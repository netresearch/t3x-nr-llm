<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Translation;

use GuzzleHttp\Psr7\Response;
use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\Guardrail\InputGuardrailScreener;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Specialized\Pricing\SpecializedCostCalculatorInterface;
use Netresearch\NrLlm\Specialized\Translation\DeepLTranslator;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\NullLogger;
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
    #[DataProvider('translationShapes')]
    public function theConfiguredIdentifierIsPassedToTheBudgetCheckSoPerConfigurationCapsApply(
        bool $batch,
    ): void {
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('editorial');
        $configuration->setIsActive(true);

        $looked = [];
        $configurations = $this->createMock(LlmConfigurationRepository::class);
        $configurations
            ->method('findOneByIdentifier')
            ->willReturnCallback(
                static function (
                    string $identifier,
                ) use (&$looked, $configuration): ?LlmConfiguration {
                    $looked[] = $identifier;
                    return $identifier === 'editorial' ? $configuration : null;
                },
            );
        $checked = [];
        $budget = $this->createMock(BudgetServiceInterface::class);
        $budget
            ->method('check')
            ->willReturnCallback(
                static function (
                    int $userUid,
                    float $plannedCost,
                    ?LlmConfiguration $resolved,
                ) use (&$checked): BudgetCheckResult {
                    $checked[] = [$userUid, $plannedCost, $resolved];
                    return BudgetCheckResult::allowed();
                },
            );
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn(
                new Response(
                    200,
                    [],
                    '{"translations":[{"text":"Hello","detected_source_language":"DE"}]}',
                ),
            );
        $translator = $this->translator(
            $httpClient,
            budgetService: $budget,
            configurationRepository: $configurations,
        );
        $options = ['beUserUid' => 7, 'plannedCost' => 0.5, 'configuration' => 'editorial'];
        $results = $batch ? $translator->translateBatch(['Guten Tag'], 'en', null, $options) : [$translator->translate('Guten Tag', 'en', null, $options)];
        self::assertSame(['editorial'], $looked);
        self::assertSame([[7, 0.5, $configuration]], $checked);
        self::assertCount(1, $results);
        self::assertSame('Hello', $results[0]->translatedText);
    }

    private function translator(
        ClientInterface $httpClient,
        bool $allowed = true,
        ?BudgetServiceInterface $budgetService = null,
        ?LlmConfigurationRepository $configurationRepository = null,
    ): DeepLTranslator {
        if (!$budgetService instanceof BudgetServiceInterface) {
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
            new MiddlewarePipeline([]),
            new InputGuardrailScreener([]),
            configurationRepository: $configurationRepository,
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

    /**
     * @return iterable<string,array{bool}>
     */
    public static function translationShapes(): iterable
    {
        yield 'single' => [false];
        yield 'batch' => [true];
    }
}
