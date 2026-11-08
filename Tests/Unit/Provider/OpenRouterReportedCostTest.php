<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Domain\ValueObject\ProviderCallUsage;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Domain\ValueObject\VisionContent;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Provider\Middleware\UsageMiddleware;
use Netresearch\NrLlm\Provider\OpenRouterProvider;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

#[CoversClass(OpenRouterProvider::class)]
#[CoversClass(UsageMiddleware::class)]
final class OpenRouterReportedCostTest extends AbstractUnitTestCase
{
    /**
     * @param 'chat'|'tools'|'embedding'|'vision' $path
     * @param array<string, mixed>                $usage
     * @param array<string, mixed>                $legacy
     */
    #[Test]
    #[DataProvider('reportedCosts')]
    public function adapterCostReachesAccounting(
        string $path,
        array $usage,
        array $legacy,
        ?float $expectedCost,
        ?int $expectedInput,
        ?int $expectedOutput,
    ): void {
        $body = [
            'model'   => 'openai/gpt-4o',
            'choices' => [['message' => ['content' => 'answer'], 'finish_reason' => 'stop']],
            'data'    => [['embedding' => [0.1, 0.2]]],
            'usage'   => $usage,
            ...$legacy,
        ];
        $client = self::createStub(ClientInterface::class);
        $client
            ->method('sendRequest')
            ->willReturnCallback(
                fn (
                    RequestInterface $request,
                ): ResponseInterface => $this->createJsonResponseMock(
                    str_ends_with($request->getUri()->getPath(), '/models') ? [
                        'data' => [
                            [
                                'id'             => 'openai/gpt-4o',
                                'architecture'   => ['modality' => 'text+image->text'],
                                'context_length' => 128000,
                            ],
                        ],
                    ] : $body,
                ),
            );
        $provider = new OpenRouterProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            new NullLogger(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->configure(
            [
                'apiKeyIdentifier' => $this->randomApiKey(),
                'defaultModel'     => 'openai/gpt-4o',
                'timeout'          => 60,
                'autoFallback'     => false,
            ],
        );
        $provider->setHttpClient($client);

        $model = new Model();
        $model->setModelId('openai/gpt-4o');
        // Deliberately differs from the supplied provider monetary amount.
        $model->setCostInput(250);
        $model->setCostOutput(1000);

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('primary');
        $configuration->setLlmModel($model);

        $context = ProviderCallContext::forConfiguration(
            ProviderOperation::Chat,
            $configuration,
        );
        $tracker = $this->createMock(UsageTrackerServiceInterface::class);
        $tracker
            ->expects(self::once())
            ->method('trackUsage')
            ->with(
                self::anything(),
                'openrouter',
                self::callback(
                    static fn (
                        array $metrics,
                    ): bool => $expectedCost === null ? !array_key_exists('cost', $metrics) : ($metrics['cost'] ?? null) === $expectedCost,
                ),
            );
        $middleware = new UsageMiddleware($tracker, new NullLogger());
        $response   = $middleware->handle(
            $context,
            static function () use ($provider, $path): mixed {
                $options = ['model' => 'openai/gpt-4o'];

                return match ($path) {
                    'chat' => $provider->chatCompletion(
                        [['role' => 'user', 'content' => 'Hi']],
                        $options,
                    ),
                    'tools' => $provider->chatCompletionWithTools(
                        [['role' => 'user', 'content' => 'Hi']],
                        [
                            new ToolSpec(
                                'read_record',
                                'Read a record',
                                ['type' => 'object'],
                            ),
                        ],
                        $options,
                    ),
                    'embedding' => $provider->embeddings('Hi', $options),
                    'vision'    => $provider->analyzeImage(
                        [VisionContent::text('Describe the image')],
                        $options,
                    ),
                };
            },
        );
        self::assertSame($expectedCost, $this->responseUsage($response)->estimatedCost);
        $recorded = $context->telemetrySignals->callUsage;
        self::assertInstanceOf(ProviderCallUsage::class, $recorded);
        self::assertSame($expectedCost, $recorded->cost);
        self::assertSame($expectedInput, $recorded->inputTokens);
        self::assertSame(
            $path === 'embedding' ? 0 : $expectedOutput,
            $recorded->outputTokens,
        );
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, array<string, mixed>, ?float, ?int, ?int}>
     */
    public static function reportedCosts(): iterable
    {
        foreach (['chat', 'tools', 'embedding', 'vision'] as $path) {
            yield $path . ' independent amount without tokens' => [$path, ['cost' => 0.37], [], 0.37, null, null];
            yield $path . ' partial token measurement' => [
                $path,
                ['cost' => 0.37, 'prompt_tokens' => 1000],
                [],
                0.37,
                1000,
                null,
            ];
            yield $path . ' provider amount overrides local estimate' => [
                $path,
                [
                    'cost'              => 0.37,
                    'prompt_tokens'     => 1000,
                    'completion_tokens' => 500,
                ],
                [],
                0.37,
                1000,
                500,
            ];
            yield $path . ' independently reported zero' => [$path, ['cost' => 0], [], 0.0, null, null];
            yield $path . ' canonical beats conflicting legacy' => [$path, ['cost' => 0.37], ['total_cost' => 9.0], 0.37, null, null];
            yield $path . ' legacy amount fallback' => [$path, [], ['total_cost' => 0.45], 0.45, null, null];
            yield $path . ' legacy zero fallback' => [$path, [], ['total_cost' => 0], 0.0, null, null];
            foreach ([-1, '0.25', true, ['amount' => 0.25]] as $index => $malformed) {
                yield $path . ' malformed cost ' . $index => [$path, ['cost' => $malformed], [], null, null, null];
                yield $path . ' malformed legacy cost ' . $index => [$path, [], ['total_cost' => $malformed], null, null, null];
            }
        }
    }

    private function responseUsage(
        mixed $response,
    ): UsageStatistics {
        self::assertTrue(
            $response instanceof CompletionResponse || $response instanceof EmbeddingResponse || $response instanceof VisionResponse,
        );

        return $response->usage;
    }
}
