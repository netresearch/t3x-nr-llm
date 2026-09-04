<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Feature;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ModelResolution;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderMiddlewareInterface;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Context\ContextWindowManager;
use Netresearch\NrLlm\Service\Feature\ConversationService;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\ModelSelectionServiceInterface;
use Netresearch\NrLlm\Tests\LlmServiceManagerTestFactory;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Service\Session\Fixtures\RecordingAiSessionRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * One routing decision per conversation turn, measured through the real
 * manager rather than a mock of it (#922).
 *
 * The sibling cases in {@see ConversationServiceTest} mock
 * LlmServiceManagerInterface, so they can prove that ConversationService takes
 * a decision and hands it to the fit -- and cannot prove that the dispatch
 * does not take a SECOND one, because the mocked manager never reaches its
 * terminal. This test wires the real LlmServiceManager with an empty
 * middleware pipeline and a stub adapter, so the terminal actually runs, and
 * counts the evaluations across both halves of the turn.
 *
 * Two evaluations moments apart can disagree about which model the turn runs
 * on. The fit would then prune for one model while another serves the send,
 * and the persisted droppedTurns would describe neither.
 */
#[CoversClass(ConversationService::class)]
#[CoversClass(LlmServiceManager::class)]
final class ConversationSingleRoutingDecisionTest extends AbstractUnitTestCase
{
    use LlmServiceManagerTestFactory;

    /** Large enough that the transcript below fits whole. */
    private const WINDOW = 128000;

    #[Test]
    public function aTurnTakesExactlyOneRoutingDecisionAcrossTheFitAndTheSend(): void
    {
        $configuration = $this->criteriaModeConfiguration();
        $resolved      = $this->resolvedModel();

        $calls   = 0;
        $routing = self::createStub(ModelSelectionServiceInterface::class);
        $routing->method('resolveModelForCall')->willReturnCallback(
            function (LlmConfiguration $configuration, ?ProviderOperation $operation) use (&$calls, $resolved): ModelResolution {
                ++$calls;

                return ModelResolution::withoutDecision($resolved);
            },
        );

        $sent    = null;
        $adapter = self::createStub(ProviderInterface::class);
        $adapter->method('chatCompletion')->willReturnCallback(
            function (array $messages) use (&$sent): CompletionResponse {
                $sent = $messages;

                return new CompletionResponse('ok', 'resolved-model', new UsageStatistics(1, 1, 2), provider: 'openai');
            },
        );

        $repository = new RecordingAiSessionRepository();
        $service    = new ConversationService(
            $this->manager($adapter, $routing),
            $repository,
            $this->resolverReturning($configuration),
            new ContextWindowManager(),
            null,
            null,
            null,
            $routing,
        );

        $actor   = AiActorContext::backendUser(1);
        $session = $service->startSession($actor, '', $configuration);
        $service->send($actor, $session->uuid, 'and now?');

        self::assertNotNull($sent, 'the adapter was never reached, so the count below would not describe a whole turn');
        self::assertSame(1, $calls, 'the turn evaluated model selection more than once');
    }

    /**
     * The window the single decision buys.
     *
     * A criteria-mode configuration carries no model, so before #922 the fit
     * fell back to 8192 tokens and trimmed a transcript the resolved model
     * accepts whole. Asserting on droppedTurns rather than on the argument
     * makes this fail for the reason an operator would notice.
     */
    #[Test]
    public function theTranscriptIsBudgetedAgainstTheResolvedWindowRatherThanTheFallback(): void
    {
        $configuration = $this->criteriaModeConfiguration();
        $resolved      = $this->resolvedModel();

        $routing = self::createStub(ModelSelectionServiceInterface::class);
        $routing->method('resolveModelForCall')->willReturn(ModelResolution::withoutDecision($resolved));

        $adapter = self::createStub(ProviderInterface::class);
        $adapter->method('chatCompletion')->willReturn(
            new CompletionResponse('ok', 'resolved-model', new UsageStatistics(1, 1, 2), provider: 'openai'),
        );

        $repository = new RecordingAiSessionRepository();
        $service    = new ConversationService(
            $this->manager($adapter, $routing),
            $repository,
            $this->resolverReturning($configuration),
            new ContextWindowManager(),
            null,
            null,
            null,
            $routing,
        );

        $actor   = AiActorContext::backendUser(1);
        $session = $service->startSession($actor, '', $configuration);
        // ~9000 tokens of history: over the 8192 fallback, well under 128k.
        for ($i = 0; $i < 6; ++$i) {
            $service->send($actor, $session->uuid, 'turn ' . $i . ' ' . str_repeat('x', 6000));
        }

        $dropped = [];
        foreach ($repository->messages as $row) {
            if ($row['role'] === 'user') {
                $dropped[] = $row['droppedTurns'];
            }
        }

        self::assertNotSame([], $dropped, 'no user row was recorded, so the assertion below would be vacuous');
        self::assertSame([0, 0, 0, 0, 0, 0], $dropped, 'history was trimmed against a window the turn was not sent to');
    }

    /**
     * A handed-over decision belongs to the configuration it was taken for.
     *
     * FallbackMiddleware retries through
     * `ProviderCallContext::withConfiguration()`, so the terminal can be asked
     * to serve a configuration the caller never named. Reusing the primary
     * decision there would send the fallback attempt to the primary model --
     * its adapter, its window -- which is the one thing a fallback exists to
     * avoid. The fallback resolves for itself.
     */
    #[Test]
    public function aFallbackAttemptResolvesForItsOwnConfigurationRatherThanReusingTheHandedOverDecision(): void
    {
        $primary  = $this->criteriaModeConfiguration();
        $fallback = new LlmConfiguration();
        $fallback->setIdentifier('the-fallback');

        $primaryModel  = $this->resolvedModel();
        $fallbackModel = $this->resolvedModel();
        $fallbackModel->setModelId('fallback-model');

        $askedFor = [];
        $routing  = self::createStub(ModelSelectionServiceInterface::class);
        $routing->method('resolveModelForCall')->willReturnCallback(
            function (LlmConfiguration $configuration) use (&$askedFor, $primary, $primaryModel, $fallbackModel): ModelResolution {
                $askedFor[] = $configuration->getIdentifier();

                return ModelResolution::withoutDecision($configuration === $primary ? $primaryModel : $fallbackModel);
            },
        );

        $served  = null;
        $adapter = self::createStub(ProviderInterface::class);
        $adapter->method('chatCompletion')->willReturn(
            new CompletionResponse('ok', 'served', new UsageStatistics(1, 1, 2), provider: 'openai'),
        );

        $registry = self::createStub(ProviderAdapterRegistryInterface::class);
        $registry->method('createAdapterFromModel')->willReturnCallback(
            function (Model $model) use (&$served, $adapter): ProviderInterface {
                $served = $model;

                return $adapter;
            },
        );

        $manager = $this->managerWith($registry, $routing, new MiddlewarePipeline([$this->configurationSwappingMiddleware($fallback)]));

        $manager->chatForConfiguration(
            [ChatMessage::user('hi')],
            $primary,
            null,
            ModelResolution::withoutDecision($primaryModel),
        );

        self::assertSame($fallbackModel, $served, "the fallback attempt was served by the primary decision's model");
        self::assertContains('the-fallback', $askedFor, 'the fallback configuration was never resolved');
    }

    /**
     * Stands in for FallbackMiddleware: one retry, on another configuration.
     */
    private function configurationSwappingMiddleware(LlmConfiguration $fallback): ProviderMiddlewareInterface
    {
        return new class ($fallback) implements ProviderMiddlewareInterface {
            public function __construct(private readonly LlmConfiguration $fallback) {}

            public function handle(ProviderCallContext $context, callable $next): mixed
            {
                return $next($context->withConfiguration($this->fallback));
            }
        };
    }

    private function manager(ProviderInterface $adapter, ModelSelectionServiceInterface $routing): LlmServiceManager
    {
        $registry = self::createStub(ProviderAdapterRegistryInterface::class);
        $registry->method('createAdapterFromModel')->willReturn($adapter);

        return $this->managerWith($registry, $routing, $this->emptyMiddlewarePipeline());
    }

    private function managerWith(
        ProviderAdapterRegistryInterface $registry,
        ModelSelectionServiceInterface $routing,
        MiddlewarePipeline $pipeline,
    ): LlmServiceManager {
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['providers' => []]);

        return $this->createLlmServiceManager(
            $extensionConfiguration,
            self::createStub(LoggerInterface::class),
            $registry,
            $pipeline,
            self::createStub(CacheManagerInterface::class),
            null,
            null,
            $routing,
            null,
            null,
            null,
            new ContextWindowManager(),
        );
    }

    /**
     * No model relation: that is what criteria mode looks like on the row.
     */
    private function criteriaModeConfiguration(): LlmConfiguration
    {
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('criteria-mode');

        return $configuration;
    }

    private function resolvedModel(): Model
    {
        $provider = new Provider();
        $provider->setIdentifier('openai');
        $provider->setAdapterType('openai');

        $model = new Model();
        $model->setModelId('resolved-model');
        $model->setContextLength(self::WINDOW);
        $model->setProvider($provider);

        return $model;
    }

    private function resolverReturning(LlmConfiguration $configuration): ConfigurationResolver
    {
        $repository = $this->createMock(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturn($configuration);

        return new ConfigurationResolver($repository);
    }
}
