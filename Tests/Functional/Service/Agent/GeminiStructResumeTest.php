<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent;

use Closure;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Provider\GeminiProvider;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRuntime;
use Netresearch\NrLlm\Service\Agent\ApprovalDecision;
use Netresearch\NrLlm\Service\Agent\PendingTurnDigest;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolver;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\AgentRunRepository;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\RequiresApprovalInterface;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityService;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolGroupStateRepository;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\LlmServiceManagerTestFactory;
use Netresearch\NrVault\Http\SecureHttpClientFactory;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use stdClass;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Provider response -> real loop -> encrypted SQL suspension -> a new runtime
 * and adapter -> actual serialized provider request. The transport is local;
 * this does not claim a live Gemini API or browser acceptance test.
 */
#[CoversClass(GeminiProvider::class)]
#[CoversClass(ToolLoopService::class)]
#[CoversClass(AgentRuntime::class)]
final class GeminiStructResumeTest extends AbstractFunctionalTestCase
{
    use LlmServiceManagerTestFactory;

    private const PROMPT = 'Run the approved replay probes.';

    private ConnectionPool $connectionPool;

    private LlmConfiguration $configuration;

    private ToolRegistry $registry;

    /** @var list<array{name:string, arguments:array<string,mixed>}> */
    private array $executions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectionPool = $this->getService(ConnectionPool::class);
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1);
        $this->configuration = $this->localConfiguration();
        $this->registry = new ToolRegistry(
            [
                $this->approvalProbe('replay_alpha'),
                $this->approvalProbe('replay_beta'),
            ],
        );
    }

    /**
     * @param list<array<string,mixed>>                              $nativeParts
     * @param list<array{name:string,arguments:array<string,mixed>}> $expectedExecutions
     * @param list<array<string,mixed>>                              $expectedResultTurns
     */
    #[Test]
    #[DataProvider('signedTurns')]
    public function signedNativePartsSurviveAnActualSavedApprovalResume(
        array $nativeParts,
        array $expectedExecutions,
        array $expectedResultTurns,
    ): void {
        $initialRequests = [];
        $initialAdapter = $this->adapterWithResponse($nativeParts, $initialRequests);
        $initialRuntime = $this->runtime($initialAdapter);
        $actor = AiActorContext::backendUser(1, isAdmin: true);
        $started = $initialRuntime->run(
            new AgentRunRequest(
                $this->configuration,
                [ChatMessage::user(self::PROMPT)],
                $actor,
            ),
        );

        self::assertSame(
            AgentRunOutcome::AWAITING_APPROVAL,
            $started->outcome,
            (string)$started->error?->getMessage(),
        );
        self::assertCount(1, $initialRequests);
        self::assertSame(
            [],
            $this->executions,
            'Approval must happen before either tool executes.',
        );
        self::assertNotSame('', $started->runUuid);
        $initialPayload = json_decode((string)$initialRequests[0]->getBody(), true);
        self::assertIsArray($initialPayload);
        self::assertSame(
            [['role' => 'user', 'parts' => [['text' => self::PROMPT]]]],
            $initialPayload['contents'] ?? null,
        );

        // Read the actual row, not the state returned by the initial runtime.
        $raw = $this->connectionPool
            ->getConnectionForTable('tx_nrllm_agentrun')
            ->fetchAssociative(
                'SELECT status, suspended_state FROM tx_nrllm_agentrun WHERE uuid = ?',
                [$started->runUuid],
            );
        self::assertIsArray($raw);
        self::assertSame(
            AgentRunStatus::WAITING_FOR_APPROVAL->value,
            $raw['status'],
        );
        self::assertIsString($raw['suspended_state']);
        self::assertNotSame('', $raw['suspended_state']);
        self::assertStringNotContainsString(
            'thoughtSignature',
            $raw['suspended_state'],
        );

        $freshRepository = $this->repository();
        $stored = $freshRepository->findByUuid($started->runUuid);
        self::assertNotNull($stored);
        self::assertNotNull($stored->suspendedState);
        self::assertNotSame(
            $raw['suspended_state'],
            $stored->suspendedState,
            'The repository must decrypt the persisted state.',
        );
        $decoded = json_decode($stored->suspendedState, true);
        self::assertIsArray($decoded);
        $state = SuspendedRunState::fromArray($decoded);
        self::assertCount(2, $state->messages);
        self::assertSame('assistant', $state->messages[1]['role']);
        self::assertSame(
            [
                [
                    'type' => 'nrllm_gemini_generate_content',
                    'parts' => $nativeParts,
                ],
            ],
            $state->messages[1]['provider_items'] ?? null,
        );
        self::assertSame(
            array_column($expectedExecutions, 'name'),
            array_map(
                static fn(
                    array $call,
                ): mixed => is_array($call['function'] ?? null) ? $call['function']['name'] ?? null : null,
                $state->pendingCalls,
            ),
        );

        // The first provider/runtime cannot carry an in-memory continuation.
        unset($initialRuntime, $initialAdapter, $started);
        $resumedRequests = [];
        $resumedAdapter = $this->adapterWithResponse(
            [['text' => 'Replay completed.']],
            $resumedRequests,
        );
        $resumedRuntime = $this->runtime($resumedAdapter);
        $resumed = $resumedRuntime->approve(
            $actor,
            $stored->uuid,
            new ApprovalDecision(
                true,
                1,
                (new PendingTurnDigest())->forState($state),
            ),
        );

        self::assertSame(
            AgentRunOutcome::COMPLETED,
            $resumed->outcome,
            (string)$resumed->error?->getMessage(),
        );
        self::assertSame($expectedExecutions, $this->executions);
        self::assertCount(1, $resumedRequests);
        self::assertSame('POST', $resumedRequests[0]->getMethod());
        self::assertSame(
            '/v1beta/models/gemini-replay-fixture:generateContent',
            $resumedRequests[0]->getUri()->getPath(),
        );
        $resumedPayload = json_decode((string)$resumedRequests[0]->getBody(), true);
        self::assertIsArray($resumedPayload);
        self::assertSame(
            [
                ['role' => 'user', 'parts' => [['text' => self::PROMPT]]],
                ['role' => 'model', 'parts' => $nativeParts],
                ...$expectedResultTurns,
            ],
            $resumedPayload['contents'] ?? null,
            'The signed parts, order and each result-to-function mapping are the literal provider contract.',
        );
        $objectPayload = json_decode(
            (string)$resumedRequests[0]->getBody(),
            false,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertInstanceOf(stdClass::class, $objectPayload);
        $objectContents = $objectPayload->contents ?? null;
        self::assertIsArray($objectContents);
        $assistant = $objectContents[1] ?? null;
        self::assertInstanceOf(stdClass::class, $assistant);
        $wireParts = $assistant->parts ?? null;
        self::assertIsArray($wireParts);
        $wireCallCount = 0;
        foreach ($wireParts as $part) {
            self::assertInstanceOf(stdClass::class, $part);
            if (isset($part->functionCall)) {
                self::assertInstanceOf(stdClass::class, $part->functionCall);
                ++$wireCallCount;
                self::assertInstanceOf(
                    stdClass::class,
                    $part->functionCall->args,
                );
                self::assertSame([], get_object_vars($part->functionCall->args));
            }
        }

        self::assertSame(count($expectedExecutions), $wireCallCount);
        foreach (array_slice($objectContents, 2) as $resultTurn) {
            self::assertInstanceOf(stdClass::class, $resultTurn);
            $resultParts = $resultTurn->parts ?? null;
            self::assertIsArray($resultParts);
            $resultPart = $resultParts[0] ?? null;
            self::assertInstanceOf(stdClass::class, $resultPart);
            $functionResponse = $resultPart->functionResponse ?? null;
            self::assertInstanceOf(stdClass::class, $functionResponse);
            $response = $functionResponse->response ?? null;
            self::assertInstanceOf(stdClass::class, $response);
            self::assertSame([], get_object_vars($response));
        }

        self::assertSame(
            'Replay completed.',
            $resumed->loopResult?->finalContent,
        );
        self::assertSame(2, $resumed->loopResult?->iterations);
        $completed = $this->repository()->findByUuid($stored->uuid);
        self::assertNotNull($completed);
        self::assertSame(AgentRunStatus::COMPLETED, $completed->statusEnum());
        self::assertNull($completed->suspendedState);
    }

    /**
     * @return iterable<string,array{list<array<string,mixed>>,list<array{name:string,arguments:array<string,mixed>}>,list<array<string,mixed>>}>
     */
    public static function signedTurns(): iterable
    {
        $alpha = [
            'functionCall' => ['name' => 'replay_alpha', 'args' => []],
            'thoughtSignature' => 'YWxwaGEvKys9PQ==',
        ];
        $beta = [
            'functionCall' => ['name' => 'replay_beta', 'args' => []],
            'thoughtSignature' => 'YmV0YS8rKz09',
        ];
        $visible = ['text' => 'I will run the requested probes.'];
        $thought = [
            'text' => 'Opaque reasoning fixture.',
            'thought' => true,
            'thoughtSignature' => 'b3BhcXVlLy89',
        ];
        $alphaExecution = ['name' => 'replay_alpha', 'arguments' => []];
        $betaExecution = ['name' => 'replay_beta', 'arguments' => []];
        $alphaResult = [
            'role' => 'user',
            'parts' => [
                [
                    'functionResponse' => ['name' => 'replay_alpha', 'response' => []],
                ],
            ],
        ];
        $betaResult = [
            'role' => 'user',
            'parts' => [
                [
                    'functionResponse' => ['name' => 'replay_beta', 'response' => []],
                ],
            ],
        ];

        yield 'single signed call with visible and opaque parts' => [[$visible, $thought, $alpha], [$alphaExecution], [$alphaResult]];
        yield 'parallel calls keep distinct signatures and results' => [
            [$thought, $beta, $visible, $alpha],
            [$betaExecution, $alphaExecution],
            [$betaResult, $alphaResult],
        ];
    }

    /**
     * @param list<array<string,mixed>> $parts
     * @param list<RequestInterface>    $requests
     */
    private function adapterWithResponse(
        array $parts,
        array &$requests,
    ): GeminiProvider {
        $wireParts = $parts;
        foreach ($wireParts as $index => $part) {
            if (isset($part['functionCall']) && is_array($part['functionCall'])) {
                $function = $part['functionCall'];
                $function['args'] = (object)[];
                $part['functionCall'] = $function;
                $wireParts[$index] = $part;
            }
        }

        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode(
                [
                    'candidates' => [
                        [
                            'content' => ['role' => 'model', 'parts' => $wireParts],
                            'finishReason' => 'STOP',
                        ],
                    ],
                    'usageMetadata' => ['promptTokenCount' => 11, 'candidatesTokenCount' => 7],
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
        $client = self::createMock(ClientInterface::class);
        $client
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturnCallback(
                static function (
                    RequestInterface $request,
                ) use (&$requests, $response): ResponseInterface {
                    $requests[] = $request;
                    return $response;
                },
            );
        $vault = self::createStub(VaultServiceInterface::class);
        $vault->method('exists')->willReturn(true);
        $factory = new HttpFactory();
        $adapter = new GeminiProvider(
            $factory,
            $factory,
            new NullLogger(),
            $vault,
            new SecureHttpClientFactory(),
        );
        $adapter->configure(
            [
                'apiKeyIdentifier' => 'fixture-gemini-key',
                'baseUrl' => 'https://gemini-replay.test/v1beta',
                'defaultModel' => 'gemini-replay-fixture',
                'maxRetries' => 0,
            ],
        );
        $adapter->setHttpClient($client);
        return $adapter;
    }

    private function runtime(GeminiProvider $adapter): AgentRuntime
    {
        $adapters = self::createStub(ProviderAdapterRegistryInterface::class);
        $adapters->method('createAdapterFromModel')->willReturn($adapter);
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([]);
        $policy = new ToolCallPolicy(
            $this->registry,
            new ToolAvailabilityService(
                $this->registry,
                new ToolStateRepository($this->connectionPool),
                new ToolGroupStateRepository($this->connectionPool),
            ),
            new AllowedToolsResolver(new SkillComposer(), $this->registry),
            new ToolDataClassResolver($this->registry),
            new TrustZoneResolver(),
            new DataClassEnforcementResolver(),
        );
        $loop = new ToolLoopService(
            $this->createLlmServiceManager(
                $extensionConfiguration,
                new NullLogger(),
                $adapters,
                new MiddlewarePipeline([]),
                self::createStub(CacheManagerInterface::class),
            ),
            $this->registry,
            $policy,
            new NullLogger(),
        );
        $configurations = self::createStub(LlmConfigurationRepository::class);
        $configurations->method('findByUid')->willReturn($this->configuration);
        return new AgentRuntime(
            toolLoop: $loop,
            persister: new AgentRunPersister(
                $this->repository(),
                FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL),
                new NullLogger(),
            ),
            configurationRepository: $configurations,
            logger: new NullLogger(),
            actingBackendUserResolver: new ActingBackendUserResolver(),
            toolEffectResolver: new ToolEffectResolver($this->registry),
            toolPolicy: $policy,
        );
    }

    private function repository(): AgentRunRepository
    {
        return new AgentRunRepository(
            $this->connectionPool,
            $this->getService(AgentStateCodec::class),
        );
    }

    private function localConfiguration(): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setIdentifier('gemini-replay-fixture');
        $provider->setAdapterType('gemini');
        $provider->setTrustZoneEnum(TrustZone::LOCAL);

        $model = new Model();
        $model->setModelId('gemini-replay-fixture');
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('gemini-replay-resume');
        $configuration->setLlmModel($model);
        return $configuration;
    }

    private function approvalProbe(string $name): ToolInterface
    {
        $record = function (array $arguments) use ($name): void {
            $this->executions[] = ['name' => $name, 'arguments' => $arguments];
        };
        return new class (
            $name,
            $record,
        ) implements ToolInterface, RequiresApprovalInterface {
            /**
             * @param Closure(array<string,mixed>):void $record
             */
            public function __construct(
                private readonly string $name,
                private readonly Closure $record,
            ) {}

            public function getSpec(): ToolSpec
            {
                return ToolSpec::function(
                    $this->name,
                    'Local approved replay probe.',
                    ['type' => 'object', 'properties' => (object)[]],
                );
            }

            public function execute(
                array $arguments,
                ToolExecutionContext $context,
            ): ToolResult {
                ($this->record)($arguments);
                return ToolResult::text('{}');
            }

            public function isEnabledByDefault(): bool
            {
                return true;
            }

            public function requiresAdmin(): bool
            {
                return false;
            }

            public function getGroup(): string
            {
                return 'content';
            }
        };
    }
}
