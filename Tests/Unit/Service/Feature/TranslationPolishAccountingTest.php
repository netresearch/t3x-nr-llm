<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Feature;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Model\UserBudget;
use Netresearch\NrLlm\Domain\Repository\UserBudgetRepository;
use Netresearch\NrLlm\Provider\Middleware\BudgetMiddleware;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Provider\Middleware\UsageMiddleware;
use Netresearch\NrLlm\Service\Budget\BudgetUsageWindowsInterface;
use Netresearch\NrLlm\Service\BudgetService;
use Netresearch\NrLlm\Service\CallMetadataFactory;
use Netresearch\NrLlm\Service\Feature\TranslationPolisher;
use Netresearch\NrLlm\Service\Feature\TranslationPromptBuilder;
use Netresearch\NrLlm\Service\Feature\TranslationService;
use Netresearch\NrLlm\Service\LlmConfigurationServiceInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\Option\TranslationOptions;
use Netresearch\NrLlm\Specialized\Translation\TranslatorInterface;
use Netresearch\NrLlm\Specialized\Translation\TranslatorRegistryInterface;
use Netresearch\NrLlm\Specialized\Translation\TranslatorResult;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Fixture\RecordingUsageTracker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;

/**
 * How a revision is accounted (ADR-233): an ordinary request.
 *
 * The chat call runs through the real BudgetMiddleware, BudgetService and
 * UsageMiddleware; the usage table is the recording tracker, and the budget's
 * usage windows sum its rows the way the SQL does — `request_count` is 1 for
 * a row that counts as a request and 0 otherwise. The DeepL double writes the
 * counting `translation` row DeepL's usage extractor writes, without a
 * configuration.
 */
#[CoversClass(TranslationService::class)]
#[CoversClass(TranslationPolisher::class)]
final class TranslationPolishAccountingTest extends AbstractUnitTestCase
{
    private const USER = 42;

    private const LIMIT = 3;

    private RecordingUsageTracker $tracker;

    private ?UserBudget $budget = null;

    private LlmConfiguration $configuration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tracker = new RecordingUsageTracker();

        $this->configuration = new LlmConfiguration();
        $this->configuration->_setProperty('uid', 9);
        $this->configuration->setIdentifier('default');
    }

    #[Test]
    public function aPolishedTranslationUsesTwoRequests(): void
    {
        $result = $this->subject()->translateWithTranslator('Hello world', 'de', 'en', $this->options());

        self::assertTrue($result->isPolished());
        self::assertSame(2, $this->userRequests(), 'DeepL and the revision, each once');
        self::assertSame(1, $this->countingRows('chat'), 'the chat row is the revision\'s request');
        self::assertSame(1, $this->rows(TranslationPolisher::OPERATION));
        self::assertSame(0, $this->countingRows(TranslationPolisher::OPERATION), 'the characters row does not count again');
    }

    /**
     * N−1 of N used beforehand: DeepL's pre-flight passes and its row makes it
     * N, so the revision's pre-flight is refused and DeepL's text returned.
     */
    #[Test]
    public function atTheUsersLimitTheRevisionIsRefusedAndDeepLsTextReturned(): void
    {
        $this->budget = new UserBudget();
        $this->budget->setBeUser(self::USER);
        $this->budget->setIsActive(true);
        $this->budget->setMaxRequestsPerDay(self::LIMIT);
        $this->prefill(self::LIMIT - 1, 0);
        $rowsBefore = count($this->tracker->calls);

        $result = $this->subject()->translateWithTranslator('Hello world', 'de', 'en', $this->options());

        self::assertSame('Hallo Welt', $result->translatedText);
        self::assertFalse($result->isPolished());
        self::assertSame('budget_refused', $result->metadata['polish']['reason'] ?? null);
        self::assertSame(self::LIMIT, $this->userRequests(), 'no row for the refused revision');
        self::assertCount($rowsBefore + 1, $this->tracker->calls, 'only DeepL\'s row was added');
    }

    #[Test]
    public function theRevisionCountsAgainstTheConfigurationsCap(): void
    {
        $before = $this->configurationRequests();

        $this->subject()->translateWithTranslator('Hello world', 'de', 'en', $this->options());

        self::assertSame($before + 1, $this->configurationRequests());
    }

    #[Test]
    public function atTheConfigurationsCapTheRevisionIsRefused(): void
    {
        $this->configuration->setMaxRequestsPerDay(self::LIMIT);
        $this->prefill(0, self::LIMIT);

        $result = $this->subject()->translateWithTranslator('Hello world', 'de', 'en', $this->options());

        self::assertSame('budget_refused', $result->metadata['polish']['reason'] ?? null);
        self::assertSame(self::LIMIT, $this->configurationRequests());
    }

    private function options(): TranslationOptions
    {
        return (new TranslationOptions())->withTranslator('deepl')->withPolish()->withBeUserUid(self::USER);
    }

    private function prefill(int $userRequests, int $configurationRequests): void
    {
        for ($i = 0; $i < $userRequests; ++$i) {
            $this->tracker->trackUsage('chat', 'openai', beUserUid: self::USER);
        }

        for ($i = 0; $i < $configurationRequests; ++$i) {
            $this->tracker->trackUsage('chat', 'openai', configurationUid: 9, beUserUid: 7);
        }
    }

    private function userRequests(): int
    {
        return count(array_filter($this->tracker->calls, static fn (array $row): bool => $row['beUserUid'] === self::USER && $row['countsAsRequest']));
    }

    private function configurationRequests(): int
    {
        return count(array_filter($this->tracker->calls, static fn (array $row): bool => $row['configurationUid'] === 9 && $row['countsAsRequest']));
    }

    private function rows(string $serviceType): int
    {
        return count(array_filter($this->tracker->calls, static fn (array $row): bool => $row['serviceType'] === $serviceType));
    }

    private function countingRows(string $serviceType): int
    {
        return count(array_filter($this->tracker->calls, static fn (array $row): bool => $row['serviceType'] === $serviceType && $row['countsAsRequest']));
    }

    private function subject(): TranslationService
    {
        $tracker = $this->tracker;

        $deepl = self::createStub(TranslatorInterface::class);
        $deepl->method('getIdentifier')->willReturn('deepl');
        $deepl->method('translate')->willReturnCallback(static function (string $text, string $target, ?string $source) use ($tracker): TranslatorResult {
            // What DeepLUsageExtractor records: a counting row, no configuration.
            $tracker->trackUsage('translation', 'deepl', ['characters' => mb_strlen($text)], beUserUid: self::USER);

            return new TranslatorResult('Hallo Welt', $source ?? 'en', $target, 'deepl');
        });

        $registry = self::createStub(TranslatorRegistryInterface::class);
        $registry->method('get')->willReturn($deepl);

        $repository = self::createStub(UserBudgetRepository::class);
        $repository->method('findOneByBeUser')->willReturnCallback(fn (): ?UserBudget => $this->budget);

        $budgetMiddleware = new BudgetMiddleware(new BudgetService($repository, $this->usageWindows()));
        $usageMiddleware  = new UsageMiddleware($tracker, new NullLogger());
        $metadata         = new CallMetadataFactory();
        $configuration    = $this->configuration;

        // chat() as LlmServiceManager runs it on the default configuration:
        // metadata from the options, then the budget and usage middlewares.
        $llmManager = self::createStub(LlmServiceManagerInterface::class);
        $llmManager->method('chat')->willReturnCallback(static function (array $messages, ?ChatOptions $options) use ($budgetMiddleware, $usageMiddleware, $metadata, $configuration): mixed {
            $options ??= new ChatOptions();
            $context = ProviderCallContext::forConfiguration(
                ProviderOperation::Chat,
                $configuration,
                $metadata->budget($options->getBeUserUid(), $options->getPlannedCost()) + $metadata->requestCount($options) + $metadata->callerSource($options),
            );

            return $budgetMiddleware->handle(
                $context,
                static fn (ProviderCallContext $context): mixed => $usageMiddleware->handle(
                    $context,
                    static fn (): CompletionResponse => new CompletionResponse('Hallo, Welt!', 'gpt-test', new UsageStatistics(10, 5, 15), 'stop', 'openai'),
                ),
            );
        });

        return new TranslationService(
            $llmManager,
            $registry,
            self::createStub(LlmConfigurationServiceInterface::class),
            new TranslationPromptBuilder(),
            polisher: new TranslationPolisher($llmManager, $tracker, new NullLogger()),
        );
    }

    private function usageWindows(): BudgetUsageWindowsInterface
    {
        $tracker = $this->tracker;

        return new class ($tracker) implements BudgetUsageWindowsInterface {
            public function __construct(private readonly RecordingUsageTracker $tracker) {}

            public function aggregate(int $beUserUid, ?int $dailyFromTimestamp, ?int $monthlyFromTimestamp, int $toTimestamp): array
            {
                $usage = $this->sum(static fn (array $row): bool => $row['beUserUid'] === $beUserUid);

                return ['daily' => $usage, 'monthly' => $usage];
            }

            public function aggregateForConfiguration(int $configurationUid, int $fromTimestamp, int $toTimestamp): array
            {
                return $this->sum(static fn (array $row): bool => $row['configurationUid'] === $configurationUid);
            }

            /**
             * @param callable(array<string, mixed>): bool $filter
             *
             * @return array{requests: int, tokens: int, cost: float}
             */
            private function sum(callable $filter): array
            {
                $requests = 0;
                foreach ($this->tracker->calls as $row) {
                    if ($filter($row) && $row['countsAsRequest']) {
                        ++$requests;
                    }
                }

                return ['requests' => $requests, 'tokens' => 0, 'cost' => 0.0];
            }
        };
    }
}
