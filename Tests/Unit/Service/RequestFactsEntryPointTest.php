<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Closure;
use Netresearch\NrLlm\Domain\Enum\RequestShape;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\RequestFacts;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Contract\ToolCapableInterface;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderMiddlewareInterface;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Tests\LlmServiceManagerTestFactory;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * The request facts exist before the pipeline runs (ADR-174, issue #771).
 *
 * WHERE they are recorded is the whole claim, and it is not visible in the
 * numbers: `resolveModel()` runs inside the pipeline TERMINAL, so a fact set
 * assembled anywhere in there would be measured after the model was chosen and
 * would be worth nothing to the question it exists to answer. These tests
 * observe the scratchpad from a middleware — which the pipeline runs strictly
 * before the terminal — so a later recording point fails them even though every
 * recorded figure would still be correct.
 */
#[CoversClass(LlmServiceManager::class)]
final class RequestFactsEntryPointTest extends AbstractUnitTestCase
{
    use LlmServiceManagerTestFactory;

    #[Test]
    public function aChatSendCarriesItsFactsIntoThePipelineBeforeTheTerminalRuns(): void
    {
        $seen = null;

        $this->manager($this->chatAdapter(), $this->probe($seen))->chatWithConfiguration(
            [ChatMessage::system('You are terse.'), ChatMessage::user('Why is the sky blue?')],
            $this->configuration(),
        );

        self::assertInstanceOf(RequestFacts::class, $seen, 'The facts must be on the context before the terminal.');
        self::assertSame(2, $seen->messageCount);
        self::assertSame(1, $seen->turnCount);
        self::assertSame(0, $seen->toolCount);
        self::assertSame(34, $seen->payloadBytes);
        self::assertGreaterThan(0, $seen->tokenEstimate);
        self::assertSame(RequestShape::SINGLE_TURN->value, $seen->shape);
    }

    /**
     * The configuration's system prompt is NOT in the counts, and that is a
     * property of where the measurement sits (ADR-174).
     *
     * `applySystemPrompt()` runs inside the terminal, on call options built out
     * of the RESOLVED model — so a fact set that included the prompt would be a
     * fact set measured after the decision it exists to precede. The docblocks
     * say the counts describe the caller's transcript; this is what makes that
     * statement fail if the collection point ever moves.
     */
    #[Test]
    public function theConfigurationsSystemPromptIsNotCounted(): void
    {
        $seen = null;

        $configuration = $this->configuration();
        $configuration->setSystemPrompt('You are a terse assistant that never mentions the weather.');

        $this->manager($this->chatAdapter(), $this->probe($seen))->chatWithConfiguration(
            [ChatMessage::user('Why is the sky blue?')],
            $configuration,
        );

        self::assertInstanceOf(RequestFacts::class, $seen);
        self::assertSame(1, $seen->messageCount, 'One caller message; the configuration prompt is not one of them.');
        self::assertSame(1, $seen->turnCount);
        self::assertSame(20, $seen->payloadBytes, 'The bytes of the user turn alone.');
    }

    #[Test]
    public function aCompletionSendMeasuresItsPromptAsOneUserTurn(): void
    {
        $seen = null;

        $this->manager($this->chatAdapter(), $this->probe($seen))->completeWithConfiguration(
            'Summarise the release notes.',
            $this->configuration(),
        );

        self::assertInstanceOf(RequestFacts::class, $seen);
        self::assertSame(1, $seen->messageCount);
        self::assertSame(1, $seen->turnCount);
        self::assertSame(28, $seen->payloadBytes);
        self::assertSame(RequestShape::SINGLE_TURN->value, $seen->shape);
    }

    #[Test]
    public function aToolSendCountsItsSchemasAndIsShapedByThem(): void
    {
        $seen = null;

        $this->manager($this->toolAdapter(), $this->probe($seen))->chatWithToolsForConfiguration(
            [ChatMessage::user('List the pages.')],
            [
                new ToolSpec('list_pages', 'Lists pages', []),
                new ToolSpec('get_page', 'Reads one page', []),
            ],
            $this->configuration(),
        );

        self::assertInstanceOf(RequestFacts::class, $seen);
        self::assertSame(2, $seen->toolCount);
        self::assertSame(RequestShape::TOOL_ASSISTED->value, $seen->shape);
    }

    /**
     * A middleware that copies the scratchpad's fact set on the way IN — i.e.
     * before the terminal, which is where the model is resolved.
     */
    private function probe(?RequestFacts &$seen): ProviderMiddlewareInterface
    {
        $capture = static function (?RequestFacts $facts) use (&$seen): void {
            $seen = $facts;
        };

        return new class ($capture) implements ProviderMiddlewareInterface {
            /**
             * @param Closure(?RequestFacts): void $capture
             */
            public function __construct(private readonly Closure $capture) {}

            public function handle(ProviderCallContext $context, callable $next): mixed
            {
                ($this->capture)($context->telemetrySignals->requestFacts);

                return $next($context);
            }
        };
    }

    private function manager(ProviderInterface $adapter, ProviderMiddlewareInterface $probe): LlmServiceManager
    {
        $registry = self::createStub(ProviderAdapterRegistryInterface::class);
        $registry->method('createAdapterFromModel')->willReturn($adapter);

        return $this->createLlmServiceManager(
            $this->extensionConfigStub(),
            self::createStub(LoggerInterface::class),
            $registry,
            new MiddlewarePipeline([$probe]),
            self::createStub(CacheManagerInterface::class),
        );
    }

    private function chatAdapter(): ProviderInterface
    {
        $adapter = self::createStub(ProviderInterface::class);
        $adapter->method('chatCompletion')->willReturn($this->response());
        $adapter->method('complete')->willReturn($this->response());

        return $adapter;
    }

    private function toolAdapter(): ProviderInterface
    {
        $adapter = self::createStubForIntersectionOfInterfaces([
            ProviderInterface::class,
            ToolCapableInterface::class,
        ]);
        $adapter->method('chatCompletionWithTools')->willReturn($this->response());

        return $adapter;
    }

    private function extensionConfigStub(): ExtensionConfiguration
    {
        $stub = self::createStub(ExtensionConfiguration::class);
        $stub->method('get')->willReturn(['providers' => []]);

        return $stub;
    }

    private function configuration(): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setIdentifier('openai');
        $provider->setAdapterType('openai');

        $model = new Model();
        $model->setModelId('small');
        $model->setContextLength(4000);
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('primary');
        $configuration->setLlmModel($model);

        return $configuration;
    }

    private function response(): CompletionResponse
    {
        return new CompletionResponse(
            content: 'ok',
            model: 'small',
            usage: new UsageStatistics(1, 1, 2),
            provider: 'openai',
        );
    }
}
