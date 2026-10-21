<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

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
 * Native call identity -> real loop -> encrypted SQL suspension -> a new runtime
 * and adapter -> actual serialized provider request. The transport is local;
 * this does not claim a live Gemini API or browser acceptance test.
 */
#[CoversClass(GeminiProvider::class)]
#[CoversClass(ToolLoopService::class)]
#[CoversClass(AgentRuntime::class)]
final class GeminiFunctionIdResumeTest extends AbstractFunctionalTestCase
{
    use LlmServiceManagerTestFactory;

    private const PROMPT = 'Execute each approved identity probe.';

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
        $this->registry      = new ToolRegistry([$this->approvalProbe()]);
    }

    /**
     * @param list<string|null> $nativeIds
     */
    #[Test]
    #[DataProvider('nativeTurns')]
    public function nativeIdentitiesSurviveEncryptedApprovalResume(
        array $nativeIds,
        bool $reorder,
    ): void {
        $nativeParts = [
            [
                'text'             => 'Opaque identity reasoning.',
                'thought'          => true,
                'thoughtSignature' => 'aWRlbnRpdHkvKys9PQ==',
            ],
        ];
        foreach ($nativeIds as $index => $nativeId) {
            $function = ['name' => 'identity_probe', 'args' => []];
            if ($nativeId !== null) {
                $function['id'] = $nativeId;
            }

            $nativeParts[] = [
                'functionCall'     => $function,
                'thoughtSignature' => 'signed-identity-' . $index,
            ];
        }

        $nativeParts[] = ['text' => 'Run all requested calls.'];

        $initialRequests = [];
        $initialAdapter  = $this->adapterWithResponse($nativeParts, $initialRequests);
        $initialRuntime  = $this->runtime($initialAdapter);
        $actor           = AiActorContext::backendUser(1, isAdmin: true);
        $started         = $initialRuntime->run(
            new AgentRunRequest(
                $this->configuration,
                [ChatMessage::user(self::PROMPT)],
                $actor,
            ),
        );
        self::assertSame(
            AgentRunOutcome::AWAITING_APPROVAL,
            $started->outcome,
            (string) $started->error?->getMessage(),
        );
        self::assertCount(1, $initialRequests);
        self::assertSame(
            [],
            $this->executions,
            'No tool may execute before approval.',
        );
        self::assertNotSame('', $started->runUuid);
        $initialPayload = json_decode(
            (string) $initialRequests[0]->getBody(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($initialPayload);
        self::assertSame(
            [['role' => 'user', 'parts' => [['text' => self::PROMPT]]]],
            $initialPayload['contents'] ?? null,
        );

        $connection = $this->connectionPool->getConnectionForTable('tx_nrllm_agentrun');
        $raw        = $connection->fetchAssociative(
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
        foreach ([
            'thoughtSignature',
            'tool_call_bindings',
            'native-call-first',
            'native-call-second',
        ] as $cleartext) {
            self::assertStringNotContainsString(
                $cleartext,
                $raw['suspended_state'],
            );
        }

        $stored = $this->repository()->findByUuid($started->runUuid);
        self::assertNotNull($stored);
        self::assertNotNull($stored->suspendedState);
        self::assertNotSame($raw['suspended_state'], $stored->suspendedState);
        $decoded = json_decode($stored->suspendedState, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $state = SuspendedRunState::fromArray($decoded);
        self::assertCount(2, $state->messages);
        self::assertSame('assistant', $state->messages[1]['role']);
        self::assertCount(count($nativeIds), $state->pendingCalls);
        $publicIds = [];
        $bindings  = [];
        foreach ($state->pendingCalls as $index => $call) {
            $id = $call['id'] ?? null;
            self::assertIsString($id);
            self::assertStringStartsWith('call_', $id);
            self::assertNotContains($id, $publicIds);
            $publicIds[] = $id;
            $function    = $call['function'] ?? null;
            self::assertIsArray($function);
            self::assertSame('identity_probe', $function['name'] ?? null);
            self::assertSame([], $function['arguments'] ?? null);
            $nativeId = $nativeIds[$index];
            if ($nativeId !== null) {
                self::assertNotSame(
                    $nativeId,
                    $id,
                    'Public call IDs remain synthesized identities.',
                );
                $bindings[] = ['tool_call_id' => $id, 'native_id' => $nativeId];
            }
        }

        $expectedCarrier = ['type' => 'nrllm_gemini_generate_content', 'parts' => $nativeParts];
        if ($bindings !== []) {
            $expectedCarrier['tool_call_bindings'] = $bindings;
        }

        self::assertSame(
            [$expectedCarrier],
            $state->messages[1]['provider_items'] ?? null,
        );
        self::assertSame(
            array_map(
                static fn (string $id): array => [
                    'id'       => $id,
                    'type'     => 'function',
                    'function' => ['name' => 'identity_probe', 'arguments' => '{}'],
                ],
                $publicIds,
            ),
            $state->messages[1]['tool_calls'] ?? null,
        );
        $originalDigest = (new PendingTurnDigest())->forState($state);
        self::assertSame(
            hash(
                'sha256',
                json_encode($state->pendingCalls, JSON_THROW_ON_ERROR),
            ),
            $originalDigest,
        );

        if ($reorder) {
            // The native parts and captured associations stay byte-for-byte in
            // their original order; only the generic execution list moves.
            $decoded['pendingCalls'] = array_reverse($state->pendingCalls);
            self::assertIsArray($decoded['messages']);
            self::assertIsArray($decoded['messages'][1]);
            $decoded['messages'][1]['tool_calls'] = array_reverse($state->pendingCalls);
            $reorderedJson                        = json_encode($decoded, JSON_THROW_ON_ERROR);
            $sealed                               = $this
                ->getService(AgentStateCodec::class)
                ->encode($reorderedJson, AgentStateCodec::PURPOSE_SUSPENDED_STATE);
            self::assertNotSame($reorderedJson, $sealed);
            self::assertSame(
                1,
                $connection->update(
                    'tx_nrllm_agentrun',
                    ['suspended_state' => $sealed],
                    [
                        'uuid'   => $stored->uuid,
                        'status' => AgentRunStatus::WAITING_FOR_APPROVAL->value,
                    ],
                ),
            );
            $stored = $this->repository()->findByUuid($stored->uuid);
            self::assertNotNull($stored);
            self::assertNotNull($stored->suspendedState);
            $reloaded = json_decode($stored->suspendedState, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($reloaded);
            $state = SuspendedRunState::fromArray($reloaded);
            self::assertSame(
                array_reverse($publicIds),
                array_column($state->pendingCalls, 'id'),
            );
            self::assertSame(
                [$expectedCarrier],
                $state->messages[1]['provider_items'] ?? null,
            );
            self::assertNotSame(
                $originalDigest,
                (new PendingTurnDigest())->forState($state),
            );
        }

        $digest = (new PendingTurnDigest())->forState($state);
        self::assertSame(
            hash(
                'sha256',
                json_encode($state->pendingCalls, JSON_THROW_ON_ERROR),
            ),
            $digest,
        );
        unset($initialRuntime, $initialAdapter, $started, $decoded);
        $resumedRequests = [];
        $resumedAdapter  = $this->adapterWithResponse(
            [['text' => 'Identity replay completed.']],
            $resumedRequests,
        );
        $resumed = $this
            ->runtime($resumedAdapter)
            ->approve($actor, $stored->uuid, new ApprovalDecision(true, 1, $digest));
        self::assertSame(
            AgentRunOutcome::COMPLETED,
            $resumed->outcome,
            (string) $resumed->error?->getMessage(),
        );
        self::assertSame(
            array_fill(
                0,
                count($nativeIds),
                ['name' => 'identity_probe', 'arguments' => []],
            ),
            $this->executions,
        );
        self::assertCount(1, $resumedRequests);
        self::assertSame('POST', $resumedRequests[0]->getMethod());
        self::assertSame(
            '/v1beta/models/gemini-replay-fixture:generateContent',
            $resumedRequests[0]->getUri()->getPath(),
        );
        $expectedTurns = [];
        $expectedIds   = $reorder ? array_reverse($nativeIds) : $nativeIds;
        foreach ($expectedIds as $index => $nativeId) {
            $response = [
                'name'     => 'identity_probe',
                'response' => ['execution' => $index + 1],
            ];
            if ($nativeId !== null) {
                $response['id'] = $nativeId;
            }

            $expectedTurns[] = ['role' => 'user', 'parts' => [['functionResponse' => $response]]];
        }

        $payload = json_decode(
            (string) $resumedRequests[0]->getBody(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($payload);
        self::assertSame(
            [
                ['role' => 'user', 'parts' => [['text' => self::PROMPT]]],
                ['role' => 'model', 'parts' => $nativeParts],
                ...$expectedTurns,
            ],
            $payload['contents'] ?? null,
            'Each literal native ID must stay attached to its own actual tool result.',
        );
        $wire = (string) $resumedRequests[0]->getBody();
        self::assertStringNotContainsString('tool_call_bindings', $wire);
        foreach ($publicIds as $publicId) {
            self::assertStringNotContainsString($publicId, $wire);
        }

        $objectPayload = json_decode($wire, false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $objectPayload);
        $objectContents = $objectPayload->contents ?? null;
        self::assertIsArray($objectContents);
        $assistant = $objectContents[1] ?? null;
        self::assertInstanceOf(stdClass::class, $assistant);
        $parts = $assistant->parts ?? null;
        self::assertIsArray($parts);
        $callCount = 0;
        foreach ($parts as $part) {
            self::assertInstanceOf(stdClass::class, $part);
            if (isset($part->functionCall)) {
                ++$callCount;
                self::assertInstanceOf(stdClass::class, $part->functionCall);
                self::assertInstanceOf(
                    stdClass::class,
                    $part->functionCall->args,
                );
                self::assertSame([], get_object_vars($part->functionCall->args));
            }
        }

        self::assertSame(count($nativeIds), $callCount);
        foreach (array_slice($objectContents, 2) as $index => $turn) {
            self::assertInstanceOf(stdClass::class, $turn);
            $parts = $turn->parts ?? null;
            self::assertIsArray($parts);
            $part = $parts[0] ?? null;
            self::assertInstanceOf(stdClass::class, $part);
            $functionResponse = $part->functionResponse ?? null;
            self::assertInstanceOf(stdClass::class, $functionResponse);
            $response = $functionResponse->response ?? null;
            self::assertInstanceOf(stdClass::class, $response);
            self::assertSame(
                ['execution' => $index + 1],
                get_object_vars($response),
            );
        }

        self::assertSame(
            'Identity replay completed.',
            $resumed->loopResult?->finalContent,
        );
        self::assertSame(2, $resumed->loopResult?->iterations);
        $completed = $this->repository()->findByUuid($stored->uuid);
        self::assertNotNull($completed);
        self::assertSame(AgentRunStatus::COMPLETED, $completed->statusEnum());
        self::assertNull($completed->suspendedState);
        $completedRow = $connection->fetchAssociative(
            'SELECT status, suspended_state, claimed_by, lease_expires FROM tx_nrllm_agentrun WHERE uuid = ?',
            [$stored->uuid],
        );
        self::assertIsArray($completedRow);
        self::assertSame(
            AgentRunStatus::COMPLETED->value,
            $completedRow['status'],
        );
        self::assertSame('', $completedRow['suspended_state']);
        self::assertSame('', $completedRow['claimed_by']);
        self::assertSame(0, (int) $completedRow['lease_expires']);
    }

    /**
     * @return iterable<string,array{list<string|null>,bool}>
     */
    public static function nativeTurns(): iterable
    {
        yield 'parallel identical calls retain their individual native IDs' => [['native-call-first', 'native-call-second'], false];
        yield 'reordered identical calls retain captured identity after SQL reload' => [['native-call-first', 'native-call-second'], true];
        yield 'single native call retains its native ID' => [['native-call-first'], false];
        yield 'ID-less call remains an ID-less response' => [[null], false];
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
                $function             = $part['functionCall'];
                $function['args']     = (object) [];
                $part['functionCall'] = $function;
                $wireParts[$index]    = $part;
            }
        }

        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode(
                [
                    'candidates' => [
                        [
                            'content'      => ['role' => 'model', 'parts' => $wireParts],
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
                'baseUrl'          => 'https://gemini-replay.test/v1beta',
                'defaultModel'     => 'gemini-replay-fixture',
                'maxRetries'       => 0,
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

    private function approvalProbe(): ToolInterface
    {
        $record = function (array $arguments): string {
            $this->executions[] = ['name' => 'identity_probe', 'arguments' => $arguments];

            return json_encode(
                ['execution' => count($this->executions)],
                JSON_THROW_ON_ERROR,
            );
        };

        return new class ($record) implements ToolInterface, RequiresApprovalInterface {
            /**
             * @param Closure(array<string,mixed>):string $record
             */
            public function __construct(private readonly Closure $record) {}

            public function getSpec(): ToolSpec
            {
                return ToolSpec::function(
                    'identity_probe',
                    'Local approved identity probe.',
                    ['type' => 'object', 'properties' => (object) []],
                );
            }

            public function execute(
                array $arguments,
                ToolExecutionContext $context,
            ): ToolResult {
                return ToolResult::text(($this->record)($arguments));
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
