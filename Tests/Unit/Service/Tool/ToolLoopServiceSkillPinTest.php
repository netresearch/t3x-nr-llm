<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\SkillTrustLevel;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\SkillPin;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Exception\SkillInstructionWithdrawnException;
use Netresearch\NrLlm\Service\Agent\Process\ApprovedProcessPinProbe;
use Netresearch\NrLlm\Service\Agent\Process\ProcessPinProbe;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Prompt\PromptSnippetComposer;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Skill\SkillComposerFactory;
use Netresearch\NrLlm\Service\Skill\SkillInjectionService;
use Netresearch\NrLlm\Service\Skill\SkillInstructionPolicy;
use Netresearch\NrLlm\Service\Skill\SkillPinCheck;
use Netresearch\NrLlm\Service\Skill\SkillVersionDigest;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\Exception\ToolApprovalRequiredException;
use Netresearch\NrLlm\Service\Tool\Exception\ToolInputRequiredException;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Unit\Language\EnglishPreviewTranslatorTrait;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\FixedSkillRecordLookup;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\FixedSkillSourceLookup;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\InMemorySkillApprovalRepository;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeInputTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeToolAvailability;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\ShiftingPreviewTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * A suspended run keeps the approved skill versions it was composed with as
 * pins, and a resume re-checks them before anything continues (ADR-214
 * item 6): a revocation or a source downgrade while the run waits for a human
 * stops the run instead of letting it act under the withdrawn instruction.
 */
#[CoversClass(ToolLoopService::class)]
final class ToolLoopServiceSkillPinTest extends TestCase
{
    use EnglishPreviewTranslatorTrait;

    private const SKILL  = 3;

    private const SOURCE = 1;

    private const BODY   = 'Always answer in JSON.';

    private InMemorySkillApprovalRepository $approvals;

    private FixedSkillSourceLookup $sources;

    private FixedSkillRecordLookup $records;

    private Skill $skill;

    private ShiftingPreviewTool $tool;

    /** @var list<CompletionResponse> */
    private array $queue = [];

    private int $modelCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvals = new InMemorySkillApprovalRepository();
        $this->sources   = new FixedSkillSourceLookup([self::SOURCE => SkillTrustLevel::VERIFIED]);
        $this->records   = new FixedSkillRecordLookup([self::SKILL]);
        $this->skill     = $this->skill();
        $this->tool      = new ShiftingPreviewTool('attach_file');
        $this->approvals->add(self::SKILL, self::SOURCE, $this->skill->getVersionDigest(), SkillVersionDigest::fieldsOf($this->skill), 'verified', 1);
    }

    #[Test]
    public function aSuspendedRunCarriesThePinsOfItsInstructions(): void
    {
        $state = $this->suspend();

        self::assertEquals([new SkillPin(self::SKILL, self::SOURCE, $this->skill->getVersionDigest())], $state->skillPins);
        // And they survive the round trip through the stored run row.
        self::assertEquals($state->skillPins, SuspendedRunState::fromArray($state->toArray())->skillPins);
    }

    #[Test]
    public function aRunWhoseInstructionStillHoldsResumesAndExecutes(): void
    {
        $state = $this->suspend();
        $this->queue[] = $this->response('done');

        $result = $this->service()->resume($state, true, $this->configuration(), ToolExecutionContext::none());

        self::assertSame(1, $this->tool->executions);
        self::assertSame('done', $result->finalContent);
    }

    #[Test]
    public function aRevocationWhileTheRunWaitsStopsTheApprovedResumeBeforeTheWrite(): void
    {
        $state = $this->suspend();
        $this->approvals->revoke(self::SKILL, $this->skill->getVersionDigest(), 1);
        $callsBefore = $this->modelCalls;

        try {
            $this->service()->resume($state, true, $this->configuration(), ToolExecutionContext::none());
            self::fail('A revoked instruction must stop the resume.');
        } catch (SkillInstructionWithdrawnException $e) {
            self::assertSame(self::SKILL, $e->pin->skillUid);
            self::assertSame('its approval was revoked', $e->reason);
        }

        self::assertSame(0, $this->tool->executions, 'The approved write must not run under a revoked instruction.');
        self::assertSame($callsBefore, $this->modelCalls, 'The model must not be asked again.');
    }

    #[Test]
    public function aRevocationAlsoStopsADeclinedResume(): void
    {
        $state = $this->suspend();
        $this->approvals->revoke(self::SKILL, $this->skill->getVersionDigest(), 1);
        $callsBefore = $this->modelCalls;

        $this->expectException(SkillInstructionWithdrawnException::class);
        try {
            $this->service()->resume($state, false, $this->configuration(), ToolExecutionContext::none());
        } finally {
            self::assertSame($callsBefore, $this->modelCalls);
        }
    }

    #[Test]
    public function aSourceDowngradeWhileTheRunWaitsStopsTheResume(): void
    {
        $state                               = $this->suspend();
        $this->sources->levels[self::SOURCE] = SkillTrustLevel::COMMUNITY;

        $this->expectException(SkillInstructionWithdrawnException::class);
        $this->expectExceptionMessage('its source is no longer trusted for instructions');
        $this->service()->resume($state, true, $this->configuration(), ToolExecutionContext::none());
    }

    #[Test]
    public function aRevocationStopsAnInputResumeBeforeTheInputReachesTheTool(): void
    {
        $input = new FakeInputTool('ask_user');
        $call  = ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'ask_user', 'arguments' => '{}']];
        $state = new SuspendedRunState(
            [['role' => 'user', 'content' => 'weather?'], ['role' => 'assistant', 'content' => '', 'tool_calls' => [$call]]],
            [$call],
            1,
            0,
            0,
            ['ask_user'],
            [],
            'ask_user',
            ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
            skillPins: [new SkillPin(self::SKILL, self::SOURCE, $this->skill->getVersionDigest())],
        );
        $this->approvals->revoke(self::SKILL, $this->skill->getVersionDigest(), 1);

        try {
            $this->service(new ToolRegistry([$input]))
                ->resumeWithInput($state, ['city' => 'Berlin'], $this->configuration(), ToolExecutionContext::none());
            self::fail('A revoked instruction must stop the input resume.');
        } catch (SkillInstructionWithdrawnException) {
        }

        self::assertNull($input->capturedArguments);
        self::assertSame(0, $this->modelCalls);
    }

    #[Test]
    public function anInputResumeWhoseInstructionHoldsContinues(): void
    {
        $input = new FakeInputTool('ask_user');
        $call  = ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'ask_user', 'arguments' => '{}']];
        $state = new SuspendedRunState(
            [['role' => 'user', 'content' => 'weather?'], ['role' => 'assistant', 'content' => '', 'tool_calls' => [$call]]],
            [$call],
            1,
            0,
            0,
            ['ask_user'],
            [],
            'ask_user',
            ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
            skillPins: [new SkillPin(self::SKILL, self::SOURCE, $this->skill->getVersionDigest())],
        );
        $this->queue[] = $this->response('done');

        $result = $this->service(new ToolRegistry([$input]))
            ->resumeWithInput($state, ['city' => 'Berlin'], $this->configuration(), ToolExecutionContext::none());

        self::assertNotNull($input->capturedArguments);
        self::assertSame('done', $result->finalContent);
    }

    /**
     * A resume skips assembly, so the loop composes no pins of its own. A run
     * that suspends a second time must still carry the first suspend's pins,
     * or the next resume would check nothing.
     */
    #[Test]
    public function aRunThatSuspendsAgainAfterAResumeKeepsItsPins(): void
    {
        $state         = $this->suspend();
        $this->queue[] = $this->response('', [new ToolCall('call_2', 'attach_file', ['uid' => 8])]);

        try {
            $this->service()->resume($state, true, $this->configuration(), ToolExecutionContext::none());
            self::fail('Expected the continuation to suspend again.');
        } catch (ToolApprovalRequiredException $again) {
            self::assertEquals($state->skillPins, $again->state->skillPins);
        }
    }

    #[Test]
    public function aSecondSuspendForInputAfterAResumeKeepsItsPins(): void
    {
        $input         = new FakeInputTool('ask_user');
        $state         = $this->suspend(new ToolRegistry([$this->tool, $input]));
        $this->queue[] = $this->response('', [new ToolCall('call_2', 'ask_user', [])]);

        try {
            $this->service(new ToolRegistry([$this->tool, $input]))
                ->resume($state, true, $this->configuration(), ToolExecutionContext::none());
            self::fail('Expected the continuation to suspend for input.');
        } catch (ToolInputRequiredException $again) {
            self::assertEquals($state->skillPins, $again->state->skillPins);
        }
    }

    /**
     * Fail closed: a loop built without the pin check never resumes a run that
     * holds pins, on either resume path, even when every pin would hold.
     */
    #[Test]
    public function withoutAPinCheckAnApprovalResumeOfAPinnedRunIsRefused(): void
    {
        $state = $this->suspend();

        try {
            $this->service(null, withPinCheck: false)->resume($state, true, $this->configuration(), ToolExecutionContext::none());
            self::fail('A pinned run must not resume unchecked.');
        } catch (SkillInstructionWithdrawnException $e) {
            self::assertSame('the pin check is not available', $e->reason);
        }

        self::assertSame(0, $this->tool->executions);
    }

    #[Test]
    public function withoutAPinCheckAnInputResumeOfAPinnedRunIsRefused(): void
    {
        $input = new FakeInputTool('ask_user');
        $call  = ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'ask_user', 'arguments' => '{}']];
        $state = new SuspendedRunState(
            [['role' => 'user', 'content' => 'weather?'], ['role' => 'assistant', 'content' => '', 'tool_calls' => [$call]]],
            [$call],
            1,
            0,
            0,
            ['ask_user'],
            [],
            'ask_user',
            ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
            skillPins: [new SkillPin(self::SKILL, self::SOURCE, $this->skill->getVersionDigest())],
        );

        try {
            $this->service(new ToolRegistry([$input]), withPinCheck: false)
                ->resumeWithInput($state, ['city' => 'Berlin'], $this->configuration(), ToolExecutionContext::none());
            self::fail('A pinned run must not resume unchecked.');
        } catch (SkillInstructionWithdrawnException) {
        }

        self::assertNull($input->capturedArguments);
    }

    /**
     * A run without pins (composed before ADR-214, or with no approved skill)
     * resumes in a lean construction exactly as before.
     */
    #[Test]
    public function withoutAPinCheckARunWithoutPinsStillResumes(): void
    {
        $state         = $this->suspend()->withSkillPins([]);
        $this->queue[] = $this->response('done');

        $result = $this->service(null, withPinCheck: false)->resume($state, true, $this->configuration(), ToolExecutionContext::none());

        self::assertSame('done', $result->finalContent);
    }

    /**
     * The loop asks the probe about the pins it composed (ADR-214 item 9),
     * with the real probe reading the approval snapshot: a pinned version
     * approved as a process makes the turn suspend on its one write while the
     * read beside it runs; the same pin approved as no process leaves the
     * whole turn pending, as before.
     */
    #[Test]
    public function theLoopDecidesTheProcessRulesFromThePinsItHolds(): void
    {
        $registry = new ToolRegistry([$this->tool, new FakeTool('read_page', 'PAGE')]);
        $turn     = [new ToolCall('call_1', 'read_page', []), new ToolCall('call_2', 'attach_file', ['uid' => 7])];

        foreach ([false, true] as $process) {
            $approvals = new InMemorySkillApprovalRepository();
            $approvals->add(
                self::SKILL,
                self::SOURCE,
                $this->skill->getVersionDigest(),
                ['process' => $process] + SkillVersionDigest::fieldsOf($this->skill),
                'verified',
                1,
            );
            $probe = new ApprovedProcessPinProbe($approvals);

            $this->queue[] = $this->response('', $turn);
            try {
                $this->service($registry, probe: $probe)->runLoop([ChatMessage::user('attach it')], $this->configuration(), ToolExecutionContext::none(), null);
                self::fail('Expected the run to suspend for approval.');
            } catch (ToolApprovalRequiredException $e) {
                $state = $e->state;
            }

            self::assertNotSame([], $state->skillPins, 'the run holds the pin it composed');
            self::assertSame(
                $process ? ['call_2'] : ['call_1', 'call_2'],
                array_map(static fn(ToolCall $c): string => $c->id, $state->toolCalls()),
                $process ? 'a process run suspends on its write alone' : 'any other run keeps the whole turn pending',
            );
        }
    }

    private function suspend(?ToolRegistry $registry = null): SuspendedRunState
    {
        $this->queue[] = $this->response('', [new ToolCall('call_1', 'attach_file', ['uid' => 7])]);

        try {
            $this->service($registry)->runLoop([ChatMessage::user('attach it')], $this->configuration(), ToolExecutionContext::none(), null);
        } catch (ToolApprovalRequiredException $e) {
            return $e->state;
        }

        self::fail('Expected the run to suspend for approval.');
    }

    private function service(?ToolRegistry $registry = null, bool $withPinCheck = true, ?ProcessPinProbe $probe = null): ToolLoopService
    {
        $registry ??= new ToolRegistry([$this->tool]);
        $manager = self::createStub(LlmServiceManagerInterface::class);
        $manager->method('chatWithToolsForConfiguration')->willReturnCallback(
            function (): CompletionResponse {
                $this->modelCalls++;
                $next = array_shift($this->queue);
                if (!$next instanceof CompletionResponse) {
                    throw new RuntimeException('Scripted response queue underflow.', 1791500190);
                }

                return $next;
            },
        );

        $composer = new SkillComposer(
            SkillComposer::DEFAULT_MAX_BYTES,
            SkillTrustLevel::UNTRUSTED,
            new SkillInstructionPolicy($this->approvals, $this->sources, SkillTrustLevel::VERIFIED),
        );
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['skills' => []]);

        return new ToolLoopService(
            $manager,
            $registry,
            new ToolCallPolicy(
                $registry,
                new FakeToolAvailability($registry->names()),
                new AllowedToolsResolver($composer, $registry),
                new ToolDataClassResolver($registry),
                new TrustZoneResolver(),
                new DataClassEnforcementResolver(),
            ),
            skillInjection: new SkillInjectionService($composer, new NullLogger()),
            snippetComposer: new PromptSnippetComposer(),
            previewTranslator: $this->englishTranslator(),
            skillPinCheck: $withPinCheck ? new SkillPinCheck(
                $this->approvals,
                $this->sources,
                $this->records,
                new SkillComposerFactory($extensionConfiguration),
            ) : null,
            processPinProbe: $probe,
        );
    }

    /**
     * @param list<ToolCall>|null $toolCalls
     */
    private function response(string $content, ?array $toolCalls = null): CompletionResponse
    {
        return new CompletionResponse($content, 'test-model', UsageStatistics::fromTokens(1, 1), toolCalls: $toolCalls);
    }

    private function configuration(): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setTrustZoneEnum(TrustZone::LOCAL);

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('skill-pins');
        $configuration->setLlmModel($model);
        $configuration->addSkill($this->skill);

        return $configuration;
    }

    private function skill(): Skill
    {
        $skill = new Skill();
        $skill->_setProperty('uid', self::SKILL);
        $skill->setSource(self::SOURCE);
        $skill->setIdentifier('cfg');
        $skill->setName('Config Skill');
        $skill->setBody(self::BODY);
        $skill->setBodyChecksum(hash('sha256', self::BODY));
        $skill->setSupportStatus('full');
        $skill->setEnabled(true);
        $skill->setVersionDigest(SkillVersionDigest::of($skill));

        return $skill;
    }
}
