<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent;

use Error;
use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRuntime;
use Netresearch\NrLlm\Service\Agent\Exception\RunEnqueueFailedException;
use Netresearch\NrLlm\Service\Agent\Queue\AgentRunQueuedMessage;
use Netresearch\NrLlm\Service\Agent\QueuedRunCoordinator;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Service\Privacy\RunPrivacyLevelsInterface;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolverInterface;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\RecordingAgentRunRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * A deferred run stores a row and returns (ADR-234): whatever the transport,
 * nothing executes inside the caller, and the row outlives a consumer that
 * cannot be woken.
 */
#[CoversClass(QueuedRunCoordinator::class)]
#[CoversClass(AgentRuntime::class)]
final class DeferredRunTest extends TestCase
{
    private ?RunPrivacyLevelsInterface $runLevels = null;

    private RecordingAgentRunRepository $repository;

    /** @var list<object> */
    private array $dispatched = [];

    private mixed $savedRouting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new RecordingAgentRunRepository();
        $this->dispatched = [];
        // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
        $this->savedRouting = $GLOBALS['TYPO3_CONF_VARS']['SYS']['messenger']['routing'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->savedRouting === null) {
            unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['messenger']['routing']);
        } else {
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['messenger']['routing'] = $this->savedRouting;
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: array<string, mixed>|null}>
     */
    public static function synchronousRouting(): array
    {
        return [
            'core default'          => [['*' => 'default']],
            'no routing at all'     => [null],
            'unrelated message'     => [[RuntimeException::class => 'doctrine', '*' => 'default']],
            'named default'         => [[AgentRunQueuedMessage::class => 'default', '*' => 'doctrine']],
            'default among several' => [[AgentRunQueuedMessage::class => ['default', 'doctrine']]],
            'namespace default'     => [['Netresearch\\NrLlm\\*' => 'default', '*' => 'doctrine']],
        ];
    }

    /**
     * The whole point: on the synchronous transport a dispatch WOULD execute the
     * run in this process, so the message must not go out.
     *
     * @param array<string, mixed>|null $routing
     */
    #[Test]
    #[DataProvider('synchronousRouting')]
    public function storesARowAndWakesNobodyOnASynchronousRouting(?array $routing): void
    {
        $this->route($routing);

        $uuid = $this->runtime()->defer($this->request());

        self::assertNotSame('', $uuid);
        self::assertCount(1, $this->repository->enqueuedRuns);
        self::assertSame($uuid, $this->repository->enqueuedRuns[0]['uuid']);
        self::assertSame('task:say', $this->repository->enqueuedRuns[0]['origin']);
        self::assertSame([], $this->dispatched);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function asynchronousRouting(): array
    {
        return [
            'this message' => [[AgentRunQueuedMessage::class => 'doctrine', '*' => 'default']],
            'everything'   => [['*' => 'doctrine']],
            'namespace'    => [['Netresearch\\*' => 'default', 'Netresearch\\NrLlm\\Service\\*' => 'doctrine', '*' => 'default']],
        ];
    }

    /**
     * @param array<string, mixed> $routing
     */
    #[Test]
    #[DataProvider('asynchronousRouting')]
    public function wakesTheConsumerWhereTheMessageLeavesTheProcess(array $routing): void
    {
        $this->route($routing);

        $uuid = $this->runtime()->defer($this->request());

        self::assertCount(1, $this->dispatched);
        self::assertInstanceOf(AgentRunQueuedMessage::class, $this->dispatched[0]);
        self::assertSame($uuid, $this->dispatched[0]->runUuid);
    }

    /**
     * Unlike enqueue(): the sweep still finds the row, so a dead transport
     * neither fails the caller nor fails the run.
     */
    #[Test]
    public function aConsumerThatCannotBeWokenLeavesTheRowQueued(): void
    {
        $this->route(['*' => 'doctrine']);
        $bus = self::createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new RuntimeException('transport down', 1798000002));

        $uuid = $this->runtime(bus: $bus)->defer($this->request());

        self::assertNotSame('', $uuid);
        self::assertNull($this->repository->finished);
    }

    #[Test]
    public function worksWithoutAMessageBus(): void
    {
        $this->route(['*' => 'doctrine']);

        $runtime = new AgentRuntime(
            self::createStub(ToolLoopServiceInterface::class),
            new AgentRunPersister($this->repository, FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL)),
            self::createStub(LlmConfigurationRepository::class),
        );

        self::assertNotSame('', $runtime->defer($this->request()));
    }

    #[Test]
    public function failsClosedWhenTheRowCannotBeStored(): void
    {
        $this->repository->throwOnEnqueue = true;

        $this->expectException(RunEnqueueFailedException::class);

        $this->runtime()->defer($this->request());
    }

    /**
     * On the synchronous transport the retry would run inside this failure
     * handling and use up the retry budget in seconds. A background run waits
     * for the sweep, a run of the API keeps retrying in-process as before.
     */
    #[Test]
    public function aRetryOfABackgroundRunWaitsForTheNextTick(): void
    {
        $this->route(['*' => 'default']);
        $outcome = $this->retry('task:say');

        self::assertSame(AgentRunOutcome::REQUEUED, $outcome);
        self::assertCount(1, $this->repository->requeues);
        self::assertSame([], $this->dispatched);
    }

    #[Test]
    public function aRetryOfABackgroundRunWakesAConsumerWhereThereIsOne(): void
    {
        $this->route(['*' => 'doctrine']);
        $outcome = $this->retry('task:say');

        self::assertSame(AgentRunOutcome::REQUEUED, $outcome);
        self::assertCount(1, $this->dispatched);
    }

    #[Test]
    public function aRetryOfARunWithoutAnOriginStaysInProcess(): void
    {
        $this->route(['*' => 'default']);
        $outcome = $this->retry('');

        self::assertSame(AgentRunOutcome::REQUEUED, $outcome);
        self::assertCount(1, $this->dispatched);
    }

    #[Test]
    public function withoutAPrivacyPolicyNothingIsKept(): void
    {
        $this->execute(new ToolLoopResult('the answer', [], 1, false, UsageStatistics::fromTokens(1, 1)), level: null);

        self::assertSame([], $this->repository->answers);
    }

    #[Test]
    public function aFinishedBackgroundRunKeepsItsAnswer(): void
    {
        $this->execute(new ToolLoopResult('the answer', [], 1, false, UsageStatistics::fromTokens(1, 1)));

        self::assertSame(['the answer'], array_values($this->repository->answers));
    }

    #[Test]
    public function aRunWithoutAnOriginKeepsNoAnswer(): void
    {
        $this->execute(new ToolLoopResult('the answer', [], 1, false, UsageStatistics::fromTokens(1, 1)), origin: '');

        self::assertSame([], $this->repository->answers);
    }

    /**
     * The default level keeps the answer: unlike a step in the event stream the
     * answer is the result nobody watched arrive (ADR-234). `none` is the
     * operator saying no content at all, and `redacted` keeps the redacted text.
     *
     * @return array<string, array{0: PrivacyLevel, 1: list<string>}>
     */
    public static function answerByPrivacyLevel(): array
    {
        return [
            'none'     => [PrivacyLevel::NONE, []],
            'metadata' => [PrivacyLevel::METADATA, ['ask jane@example.org']],
            'redacted' => [PrivacyLevel::REDACTED, ['ask ***']],
            'full'     => [PrivacyLevel::FULL, ['ask jane@example.org']],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('answerByPrivacyLevel')]
    public function theAnswerFollowsThePrivacyLevel(PrivacyLevel $level, array $expected): void
    {
        $this->execute(
            new ToolLoopResult('ask jane@example.org', [], 1, false, UsageStatistics::fromTokens(1, 1)),
            level: $level,
        );

        self::assertSame($expected, array_values($this->repository->answers));
    }

    /**
     * A provider or a task tightens what the run keeps (ADR-242), whatever the
     * global level says.
     *
     * @return array<string, array{0: PrivacyLevel, 1: list<string>}>
     */
    public static function answerByRunLevel(): array
    {
        return [
            'none'     => [PrivacyLevel::NONE, []],
            'metadata' => [PrivacyLevel::METADATA, []],
            'redacted' => [PrivacyLevel::REDACTED, ['ask ***']],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('answerByRunLevel')]
    public function theAnswerFollowsTheLevelOfTheRun(PrivacyLevel $level, array $expected): void
    {
        $this->runLevels = new class ($level) implements RunPrivacyLevelsInterface {
            public function __construct(private readonly PrivacyLevel $level) {}

            public function forRun(int $runUid): PrivacyLevel
            {
                return $this->level;
            }

            public function forProvider(string $providerIdentifier): PrivacyLevel
            {
                return $this->level;
            }
        };

        $this->execute(
            new ToolLoopResult('ask jane@example.org', [], 1, false, UsageStatistics::fromTokens(1, 1)),
            level: PrivacyLevel::FULL,
        );

        self::assertSame($expected, array_values($this->repository->answers));
    }

    #[Test]
    public function aFailedRunKeepsNoAnswer(): void
    {
        $this->execute(null);

        self::assertSame([], $this->repository->answers);
    }

    /**
     * @param array<string, mixed>|null $routing
     */
    private function route(?array $routing): void
    {
        if ($routing === null) {
            unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['messenger']['routing']);

            return;
        }

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['messenger']['routing'] = $routing;
    }

    private function execute(?ToolLoopResult $result, string $origin = 'task:say', ?PrivacyLevel $level = PrivacyLevel::METADATA): void
    {
        $this->route(['*' => 'default']);
        $request = new AgentRunRequest(new LlmConfiguration(), [ChatMessage::user('hi')], AiActorContext::backendUser(9), [], new ToolOptions(), origin: $origin);

        $uuid = $this->runtime()->defer($request);

        $loop = self::createStub(ToolLoopServiceInterface::class);
        if ($result instanceof ToolLoopResult) {
            $loop->method('runLoop')->willReturn($result);
        } else {
            $loop->method('runLoop')->willThrowException(new RuntimeException('down', 1798000003));
        }

        $this->repository->findResult = new AgentRun(
            uid: 1,
            uuid: $uuid,
            status: 'queued',
            configurationUid: 1,
            configurationIdentifier: 'cfg',
            beUser: 9,
            iterations: 0,
            truncated: false,
            totalPromptTokens: 0,
            totalCompletionTokens: 0,
            totalTokens: 0,
            estimatedCost: 0.0,
            errorClass: '',
            terminationReason: '',
            startedAt: 0,
            finishedAt: 0,
            crdate: 0,
            queuedRequest: $this->repository->enqueuedRuns[0]['requestJson'],
        );

        $outcome = $this->runtime($loop, level: $level)->runQueued($uuid);

        self::assertSame($result instanceof ToolLoopResult ? AgentRunOutcome::COMPLETED : AgentRunOutcome::FAILED, $outcome?->outcome);
    }

    private function retry(string $origin): ?AgentRunOutcome
    {
        $uuid = 'run-1';
        $json = json_encode(['messages' => [], 'actor' => AiActorContext::backendUser(9)->toArray()], JSON_THROW_ON_ERROR);

        $this->repository->findResult = new AgentRun(
            uid: 1,
            uuid: $uuid,
            status: 'queued',
            configurationUid: 1,
            configurationIdentifier: 'cfg',
            beUser: 9,
            iterations: 0,
            truncated: false,
            totalPromptTokens: 0,
            totalCompletionTokens: 0,
            totalTokens: 0,
            estimatedCost: 0.0,
            errorClass: '',
            terminationReason: '',
            startedAt: 0,
            finishedAt: 0,
            crdate: 0,
            queuedRequest: $json,
            origin: $origin,
        );

        $loop = self::createStub(ToolLoopServiceInterface::class);
        $loop->method('runLoop')->willThrowException(new ProviderConnectionException('connection reset', 1798000005));

        return $this->runtime($loop)->runQueued($uuid)?->outcome;
    }

    private function runtime(?ToolLoopServiceInterface $loop = null, ?MessageBusInterface $bus = null, ?PrivacyLevel $level = PrivacyLevel::FULL, ?LoggerInterface $logger = null): AgentRuntime
    {
        $policy = $level instanceof PrivacyLevel ? new FixedPrivacyPolicy($level) : null;
        $bus ??= $this->recordingBus();

        $configurations = self::createStub(LlmConfigurationRepository::class);
        $configurations->method('findByUid')->willReturn(new LlmConfiguration());

        return new AgentRuntime(
            $loop ?? self::createStub(ToolLoopServiceInterface::class),
            new AgentRunPersister(
                $this->repository,
                FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL),
                null,
                $this->runLevels,
            ),
            $configurations,
            $logger,
            $bus,
            actingBackendUserResolver: $this->actingUsers(),
            privacyPolicy: $policy,
        );
    }

    private function recordingBus(): MessageBusInterface
    {
        $bus = self::createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatched[] = $message;

            return new Envelope($message);
        });

        return $bus;
    }

    private function actingUsers(): ActingBackendUserResolverInterface
    {
        $users = self::createStub(ActingBackendUserResolverInterface::class);
        $users->method('resolve')->willReturn(self::createStub(BackendUserAuthentication::class));

        return $users;
    }

    private function request(): AgentRunRequest
    {
        return new AgentRunRequest(new LlmConfiguration(), [ChatMessage::user('hi')], AiActorContext::backendUser(9), origin: 'task:say');
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function throwingDiagnosticKinds(): iterable
    {
        yield 'exception' => [false];
        yield 'error' => [true];
    }

    #[Test]
    #[DataProvider('throwingDiagnosticKinds')]
    public function aPersistedDeferredRunIsReturnedWhenBothWakeupAndDiagnosticFail(
        bool $error,
    ): void {
        $this->route(['*' => 'doctrine']);
        $bus = self::createStub(MessageBusInterface::class);
        $bus
            ->method('dispatch')
            ->willThrowException(new RuntimeException('transport unavailable'));
        $logger = self::createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('warning')
            ->willThrowException(
                $error ? new Error('diagnostic error') : new RuntimeException('diagnostic exception'),
            );
        $uuid = $this->runtime(bus: $bus, logger: $logger)->defer($this->request());
        self::assertNotSame('', $uuid);
        self::assertCount(1, $this->repository->enqueuedRuns);
        self::assertSame($uuid, $this->repository->enqueuedRuns[0]['uuid']);
        self::assertNull($this->repository->finished);
    }
}
