<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\SkillApprovalState;
use Netresearch\NrLlm\Domain\Enum\SkillInclusion;
use Netresearch\NrLlm\Domain\Enum\SkillRole;
use Netresearch\NrLlm\Domain\Enum\SkillTrustLevel;
use Netresearch\NrLlm\Domain\Enum\SkillUsageState;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\SkillPin;
use Netresearch\NrLlm\Domain\ValueObject\SkillUsageEntry;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Exception\SkillInstructionWithdrawnException;
use Netresearch\NrLlm\Service\Agent\Process\ApprovedProcessPinProbe;
use Netresearch\NrLlm\Service\Agent\Process\ProcessPinProbe;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Skill\SkillComposerFactory;
use Netresearch\NrLlm\Service\Skill\SkillInjectionService;
use Netresearch\NrLlm\Service\Skill\SkillInstructionPolicy;
use Netresearch\NrLlm\Service\Skill\SkillLoadService;
use Netresearch\NrLlm\Service\Skill\SkillPinCheck;
use Netresearch\NrLlm\Service\Skill\SkillVersionDigest;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\Builtin\LoadSkillTool;
use Netresearch\NrLlm\Service\Tool\Exception\ToolApprovalRequiredException;
use Netresearch\NrLlm\Service\Tool\Exception\ToolInputRequiredException;
use Netresearch\NrLlm\Service\Tool\RunAugmentation;
use Netresearch\NrLlm\Service\Tool\RunTrace;
use Netresearch\NrLlm\Service\Tool\SkillUsageLedger;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Fixtures\Process\ProcessPinProbeStub;
use Netresearch\NrLlm\Tests\Unit\Language\EnglishPreviewTranslatorTrait;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\FixedSkillLoadLookup;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\FixedSkillRecordLookup;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\FixedSkillSourceLookup;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\InMemorySkillApprovalRepository;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeInputTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeToolAvailability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * The skill usage record of a run (ADR-321): every skill version a model call
 * carried is named at the send that first carries it, with its digest, role,
 * inclusion and the approval that held; a version that was only offered, or
 * loaded and never sent, is never named as passed.
 */
#[CoversClass(ToolLoopService::class)]
#[CoversClass(SkillUsageLedger::class)]
#[CoversClass(SkillUsageEntry::class)]
#[CoversClass(RunTrace::class)]
final class ToolLoopServiceSkillUsageTest extends TestCase
{
    use EnglishPreviewTranslatorTrait;

    private const SOURCE = 1;

    private const INSTRUCTION = 3;

    private const CONTEXT = 5;

    private const ON_DEMAND = 7;

    private const PROCESS = 11;

    private InMemorySkillApprovalRepository $approvals;

    private FixedSkillLoadLookup $loadLookup;

    /** @var array<int, Skill> */
    private array $skills = [];

    /** @var array<int, int> approval uid by skill uid */
    private array $approvalUids = [];

    /** @var list<list<ToolCall>> */
    private array $turns = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvals  = new InMemorySkillApprovalRepository();
        $this->loadLookup = new FixedSkillLoadLookup();
        foreach ([self::INSTRUCTION => 'house-rules', self::CONTEXT => 'glossary', self::ON_DEMAND => 'style', self::PROCESS => 'tour'] as $uid => $identifier) {
            $this->skills[$uid] = $this->skill($uid, $identifier);
        }

        // The context skill has no approval, so it composes into the block.
        foreach ([self::INSTRUCTION, self::ON_DEMAND] as $uid) {
            $skill                    = $this->skills[$uid];
            $this->approvalUids[$uid] = $this->approvals->add($uid, self::SOURCE, $skill->getVersionDigest(), SkillVersionDigest::fieldsOf($skill), 'verified', 1);
        }

        $process = $this->skills[self::PROCESS];
        $process->setProcess(true);
        $process->setVersionDigest(SkillVersionDigest::of($process));
        $this->approvalUids[self::PROCESS] = $this->approvals->add(self::PROCESS, self::SOURCE, $process->getVersionDigest(), ['process' => true] + SkillVersionDigest::fieldsOf($process), 'verified', 1);

        $this->loadLookup->onDemand[9]                  = [self::ON_DEMAND];
        $this->loadLookup->digests[self::ON_DEMAND]     = $this->skills[self::ON_DEMAND]->getVersionDigest();
        $this->loadLookup->trustLevels[self::ON_DEMAND] = $this->skills[self::ON_DEMAND]->getTrustLevel();
        $this->loadLookup->sources[self::ON_DEMAND]     = self::SOURCE;
    }

    /**
     * Case 1 of the lineage acceptance: the composed versions are named with
     * their digests before the first request, and not again.
     */
    #[Test]
    public function theComposedVersionsAreNamedAtTheFirstSendAndNotAgain(): void
    {
        $this->turns = [[new ToolCall('c1', 'noop', [])]];
        $trace       = new RunTrace();

        $this->service()->runLoop([ChatMessage::user('go')], $this->configuration(always: [self::INSTRUCTION, self::CONTEXT]), ToolExecutionContext::none(), null, null, 5, $trace);

        $kinds = array_map(static fn (RunStep $s): string => $s->kind, $trace->getSteps());
        self::assertSame(RunStep::KIND_SKILLS, $kinds[0], 'the usage step precedes the first request');
        self::assertSame(RunStep::KIND_REQUEST, $kinds[1]);
        self::assertCount(1, $this->usageSteps($trace), 'the second send carries nothing new');

        $entries = $this->entries($trace);
        self::assertSame([self::INSTRUCTION, self::CONTEXT], array_map(static fn (SkillUsageEntry $e): int => $e->skillUid, $entries));

        $instruction = $entries[0];
        self::assertSame($this->skills[self::INSTRUCTION]->getVersionDigest(), $instruction->versionDigest);
        self::assertSame('house-rules', $instruction->identifier);
        self::assertSame(SkillRole::INSTRUCTION, $instruction->role);
        self::assertSame(SkillInclusion::COMPOSED, $instruction->inclusion);
        self::assertSame(SkillUsageState::PASSED, $instruction->state);
        self::assertSame(SkillApprovalState::APPROVED, $instruction->approvalState);
        self::assertSame($this->approvalUids[self::INSTRUCTION], $instruction->approvalUid);

        $context = $entries[1];
        self::assertSame($this->skills[self::CONTEXT]->getVersionDigest(), $context->versionDigest);
        self::assertSame(SkillRole::CONTEXT, $context->role);
        self::assertSame(SkillApprovalState::NONE, $context->approvalState);
        self::assertSame(0, $context->approvalUid);
        self::assertSame(1, $this->usageSteps($trace)[0]->round);
    }

    /**
     * Case 1, the other half: a changed skill gives the next run another
     * digest, with the same model and the same input.
     */
    #[Test]
    public function aChangedSkillIsNamedWithItsNewDigest(): void
    {
        $before = new RunTrace();
        $this->service()->runLoop([ChatMessage::user('go')], $this->configuration(always: [self::CONTEXT]), ToolExecutionContext::none(), null, null, 5, $before);

        $this->skills[self::CONTEXT]->setBody('Use the new glossary.');
        $this->skills[self::CONTEXT]->setBodyChecksum(hash('sha256', 'Use the new glossary.'));
        $this->skills[self::CONTEXT]->setVersionDigest(SkillVersionDigest::of($this->skills[self::CONTEXT]));
        $after = new RunTrace();
        $this->service()->runLoop([ChatMessage::user('go')], $this->configuration(always: [self::CONTEXT]), ToolExecutionContext::none(), null, null, 5, $after);

        self::assertNotSame($this->entries($before)[0]->versionDigest, $this->entries($after)[0]->versionDigest);
        self::assertSame($this->skills[self::CONTEXT]->getVersionDigest(), $this->entries($after)[0]->versionDigest);
    }

    #[Test]
    public function aSkillOnlyOfferedInTheCatalogueIsNeverNamed(): void
    {
        $trace = new RunTrace();

        $this->service()->runLoop([ChatMessage::user('go')], $this->configuration(onDemand: true), ToolExecutionContext::none(), null, null, 5, $trace);

        self::assertSame([], $this->usageSteps($trace));
    }

    /**
     * Case 2: a dynamically loaded version is named as loaded in the round of
     * the load and as passed from the next model call on.
     */
    #[Test]
    public function aLoadIsNamedAsLoadedAndThenAsPassedByTheNextCall(): void
    {
        $this->turns = [[$this->loadCall('c1')]];
        $trace       = new RunTrace();

        $this->service()->runLoop([ChatMessage::user('go')], $this->configuration(onDemand: true), ToolExecutionContext::none(), null, null, 5, $trace);

        $steps = $trace->getSteps();
        $kinds = array_map(static fn (RunStep $s): string => $s->kind, $steps);
        $usage = $this->usageSteps($trace);
        self::assertCount(2, $usage);

        [$loaded] = SkillUsageEntry::listFrom($usage[0]->toArray()['skillUsage']);
        self::assertSame(SkillUsageState::LOADED, $loaded->state);
        self::assertSame(SkillInclusion::LOADED, $loaded->inclusion);
        self::assertSame(1, $usage[0]->round);
        self::assertSame('style', $loaded->identifier);
        self::assertSame($this->approvalUids[self::ON_DEMAND], $loaded->approvalUid);
        // Recorded before the tool step that answers the load.
        $loadedAt = array_search($usage[0], $steps, true);
        self::assertIsInt($loadedAt);
        self::assertSame(RunStep::KIND_TOOL, $kinds[$loadedAt + 1]);

        [$passed] = SkillUsageEntry::listFrom($usage[1]->toArray()['skillUsage']);
        self::assertSame(SkillUsageState::PASSED, $passed->state);
        self::assertSame(SkillInclusion::LOADED, $passed->inclusion);
        self::assertSame(2, $usage[1]->round);
        self::assertSame($this->skills[self::ON_DEMAND]->getVersionDigest(), $passed->versionDigest);
        $passedAt = array_search($usage[1], $steps, true);
        self::assertIsInt($passedAt);
        self::assertSame(RunStep::KIND_REQUEST, $kinds[$passedAt + 1]);
        self::assertSame(2, $steps[$passedAt + 1]->round);
    }

    /**
     * A load beside a proposal of a guided process runs before the pause; the
     * run stops before another model call, so the version is loaded and not
     * passed. The resumed segment passes it at its first send, as carried.
     */
    #[Test]
    public function aLoadedVersionWhoseRunPausesBeforeTheNextCallIsNotNamedAsPassed(): void
    {
        $this->turns   = [[$this->loadCall('c1'), new ToolCall('c2', 'write', [])]];
        $probe         = new ProcessPinProbeStub(everyRun: true);
        $configuration = $this->configuration(onDemand: true);
        $trace         = new RunTrace();

        $state = $this->suspendWith($configuration, $trace, $probe);

        $entries = $this->entries($trace);
        self::assertCount(1, $entries);
        self::assertSame(SkillUsageState::LOADED, $entries[0]->state);

        $resumed = new RunTrace();
        $this->service($probe)->resume($state, true, $configuration, ToolExecutionContext::none(), null, $resumed);

        [$passed] = $this->entries($resumed);
        self::assertSame(SkillUsageState::PASSED, $passed->state);
        self::assertSame(SkillInclusion::CARRIED, $passed->inclusion);
        self::assertSame(self::ON_DEMAND, $passed->skillUid);
    }

    /**
     * A load the paused turn holds runs on resume: named as loaded there and
     * passed at the resumed segment's first send, as loaded.
     */
    #[Test]
    public function aLoadHeldInAPausedTurnIsNamedWhenTheResumeRunsIt(): void
    {
        $this->turns   = [[$this->loadCall('c1'), new ToolCall('c2', 'write', [])]];
        $configuration = $this->configuration(onDemand: true);

        $state = $this->suspendWith($configuration, new RunTrace());

        $resumed = new RunTrace();
        $this->service()->resume($state, true, $configuration, ToolExecutionContext::none(), null, $resumed);

        $entries = $this->entries($resumed);
        self::assertSame([SkillUsageState::LOADED, SkillUsageState::PASSED], array_map(static fn (SkillUsageEntry $e): SkillUsageState => $e->state, $entries));
        self::assertSame(SkillInclusion::LOADED, $entries[1]->inclusion);
    }

    /**
     * Case 3: the binding stays traceable across a pause — the resumed
     * segment names the version it carries, with the approval that held at
     * the resume.
     */
    #[Test]
    public function aResumedSegmentNamesTheVersionItCarriesAtItsFirstSend(): void
    {
        $this->turns   = [[new ToolCall('c1', 'write', [])]];
        $configuration = $this->configuration(always: [self::INSTRUCTION]);

        $state   = $this->suspendWith($configuration, new RunTrace());
        $resumed = new RunTrace();
        $this->service()->resume($state, true, $configuration, ToolExecutionContext::none(), null, $resumed);

        [$carried] = $this->entries($resumed);
        self::assertSame(self::INSTRUCTION, $carried->skillUid);
        self::assertSame(SkillInclusion::CARRIED, $carried->inclusion);
        self::assertSame(SkillUsageState::PASSED, $carried->state);
        self::assertSame('house-rules', $carried->identifier);
        self::assertSame($this->approvalUids[self::INSTRUCTION], $carried->approvalUid);
        self::assertSame(2, $this->usageSteps($resumed)[0]->round);
    }

    /**
     * Case 3, the other half: a revocation during the pause still stops the
     * resume, and nothing is named as passed after it.
     */
    #[Test]
    public function aRevocationDuringThePauseStopsTheResumeBeforeAnythingIsNamed(): void
    {
        $this->turns   = [[new ToolCall('c1', 'write', [])]];
        $configuration = $this->configuration(always: [self::INSTRUCTION]);
        $state         = $this->suspendWith($configuration, new RunTrace());
        $this->approvals->revoke(self::INSTRUCTION, $this->skills[self::INSTRUCTION]->getVersionDigest(), 1);

        $resumed = new RunTrace();
        try {
            $this->service()->resume($state, true, $configuration, ToolExecutionContext::none(), null, $resumed);
            self::fail('A revoked instruction must stop the resume.');
        } catch (SkillInstructionWithdrawnException) {
        }

        self::assertSame([], $this->usageSteps($resumed));
    }

    #[Test]
    public function aRunWithoutToolsNamesItsVersionsBeforeItsOnlyCall(): void
    {
        $trace = new RunTrace();

        $this->service()->runLoop([ChatMessage::user('go')], $this->configuration(always: [self::INSTRUCTION]), ToolExecutionContext::none(), [], null, 5, $trace);

        $kinds = array_map(static fn (RunStep $s): string => $s->kind, $trace->getSteps());
        self::assertSame([RunStep::KIND_SKILLS, RunStep::KIND_REQUEST, RunStep::KIND_LLM], $kinds);
        self::assertSame(self::INSTRUCTION, $this->entries($trace)[0]->skillUid);
    }

    /**
     * The record holds identities, a digest and codes. Nothing the skill
     * says — name, description, body — is in it, so the privacy level has
     * nothing to strip.
     */
    #[Test]
    public function theRecordCarriesNoTextOfTheSkill(): void
    {
        $trace = new RunTrace();
        $this->service()->runLoop([ChatMessage::user('go')], $this->configuration(always: [self::INSTRUCTION, self::CONTEXT]), ToolExecutionContext::none(), null, null, 5, $trace);

        $stored = $this->usageSteps($trace)[0]->toArray();
        self::assertSame(['kind', 'round', 'durationMs', 'skillUsage'], array_keys($stored));
        $json = json_encode($stored, JSON_THROW_ON_ERROR);
        foreach ($this->skills as $skill) {
            self::assertStringNotContainsString($skill->getName(), $json);
            self::assertStringNotContainsString($skill->getBody(), $json);
            self::assertStringNotContainsString($skill->getDescription(), $json);
        }

        self::assertIsArray($stored['skillUsage']);
        foreach ($stored['skillUsage'] as $entry) {
            self::assertIsArray($entry);
            self::assertSame(['skill', 'source', 'digest', 'identifier', 'role', 'inclusion', 'state', 'approvalState', 'approval'], array_keys($entry));
        }
    }

    /**
     * A process is attached on demand, so the composition does not find its
     * record among the always-loaded skills. The entry names the record's
     * identifier from the configuration — never the approved name, which is
     * the skill's text.
     */
    #[Test]
    public function aProcessIsNamedByItsRecordIdentifierAndNeverByItsName(): void
    {
        $process       = $this->skills[self::PROCESS];
        $configuration = $this->configuration();
        $configuration->addSkillOnDemand($process);

        $trace = new RunTrace();

        $this->service(new ApprovedProcessPinProbe($this->approvals))->runLoop(
            [ChatMessage::user('start the tour')],
            $configuration,
            ToolExecutionContext::none(),
            null,
            null,
            5,
            $trace,
            RunAugmentation::forProcess(new SkillPin(self::PROCESS, self::SOURCE, $process->getVersionDigest())),
        );

        [$entry] = $this->entries($trace);
        self::assertSame(SkillRole::PROCESS, $entry->role);
        self::assertSame('tour', $entry->identifier);
        self::assertSame(SkillInclusion::COMPOSED, $entry->inclusion);
        self::assertSame($this->approvalUids[self::PROCESS], $entry->approvalUid);
        $json = json_encode($this->usageSteps($trace)[0]->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($process->getName(), $json);
        self::assertStringNotContainsString($process->getBody(), $json);
    }

    /**
     * The closing call at the round limit is a send too: a version loaded in
     * the last tool round is named as passed by it.
     */
    #[Test]
    public function theClosingCallAtTheRoundLimitNamesWhatItCarries(): void
    {
        $this->turns = [[$this->loadCall('c1'), new ToolCall('c2', 'noop', [])]];
        $trace       = new RunTrace();

        $this->service()->runLoop([ChatMessage::user('go')], $this->configuration(onDemand: true), ToolExecutionContext::none(), null, null, 1, $trace);

        $steps = $trace->getSteps();
        $usage = $this->usageSteps($trace);
        self::assertCount(2, $usage);
        self::assertSame([SkillUsageState::LOADED, SkillUsageState::PASSED], array_map(static fn (SkillUsageEntry $e): SkillUsageState => $e->state, $this->entries($trace)));
        self::assertSame(2, $usage[1]->round);
        $at = array_search($usage[1], $steps, true);
        self::assertIsInt($at);
        self::assertSame(RunStep::KIND_REQUEST, $steps[$at + 1]->kind);
        self::assertSame([], $steps[$at + 1]->toolSpecs, 'the closing call offers no tools');
    }

    /**
     * The input resume hands its ledger to the loop as the approval resume
     * does: a load the paused turn holds is passed as loaded.
     */
    #[Test]
    public function aLoadHeldInATurnPausedForInputIsNamedWhenTheResumeRunsIt(): void
    {
        $this->turns   = [[$this->loadCall('c1'), new ToolCall('c2', 'ask_user', [])]];
        $configuration = $this->configuration(onDemand: true);

        try {
            $this->service()->runLoop([ChatMessage::user('go')], $configuration, ToolExecutionContext::none(), null, null, 5, new RunTrace());
            self::fail('The input tool did not suspend the run.');
        } catch (ToolInputRequiredException $e) {
            $state = $e->state;
        }

        $resumed = new RunTrace();
        $this->service()->resumeWithInput($state, ['city' => 'Berlin'], $configuration, ToolExecutionContext::none(), null, $resumed);

        $entries = $this->entries($resumed);
        self::assertSame([SkillUsageState::LOADED, SkillUsageState::PASSED], array_map(static fn (SkillUsageEntry $e): SkillUsageState => $e->state, $entries));
        self::assertSame(SkillInclusion::LOADED, $entries[1]->inclusion);
    }

    private function suspendWith(LlmConfiguration $configuration, RunTrace $trace, ?ProcessPinProbe $probe = null): SuspendedRunState
    {
        try {
            $this->service($probe)->runLoop([ChatMessage::user('go')], $configuration, ToolExecutionContext::none(), null, null, 5, $trace);
        } catch (ToolApprovalRequiredException $e) {
            return $e->state;
        }

        self::fail('Expected the run to suspend for approval.');
    }

    /**
     * @return list<RunStep>
     */
    private function usageSteps(RunTrace $trace): array
    {
        return array_values(array_filter($trace->getSteps(), static fn (RunStep $s): bool => $s->kind === RunStep::KIND_SKILLS));
    }

    /**
     * @return list<SkillUsageEntry>
     */
    private function entries(RunTrace $trace): array
    {
        $entries = [];
        foreach ($this->usageSteps($trace) as $step) {
            $entries = [...$entries, ...SkillUsageEntry::listFrom($step->toArray()['skillUsage'] ?? null)];
        }

        return $entries;
    }

    private function service(?ProcessPinProbe $probe = null): ToolLoopService
    {
        $manager = self::createStub(LlmServiceManagerInterface::class);
        $manager->method('chatWithToolsForConfiguration')->willReturnCallback(
            function (): CompletionResponse {
                $calls = array_shift($this->turns);

                return new CompletionResponse($calls === null ? 'done' : '', 'test-model', UsageStatistics::fromTokens(1, 1), toolCalls: $calls);
            },
        );
        $manager->method('chatWithConfiguration')->willReturn(new CompletionResponse('done', 'test-model', UsageStatistics::fromTokens(1, 1)));

        $sources  = new FixedSkillSourceLookup([self::SOURCE => SkillTrustLevel::VERIFIED]);
        $composer = new SkillComposer(
            SkillComposer::DEFAULT_MAX_BYTES,
            SkillTrustLevel::UNTRUSTED,
            new SkillInstructionPolicy($this->approvals, $sources, SkillTrustLevel::VERIFIED),
        );
        $registry = new ToolRegistry([
            new FakeTool('noop'),
            new FakeTool('write', effect: ToolEffect::IDEMPOTENT_WRITE),
            new LoadSkillTool(),
            new FakeInputTool('ask_user'),
        ]);
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
            previewTranslator: $this->englishTranslator(),
            skillPinCheck: new SkillPinCheck(
                $this->approvals,
                $sources,
                new FixedSkillRecordLookup([self::INSTRUCTION, self::CONTEXT, self::ON_DEMAND, self::PROCESS]),
                new SkillComposerFactory($extensionConfiguration),
            ),
            skillLoad: new SkillLoadService($composer, $this->loadLookup),
            processPinProbe: $probe,
        );
    }

    /**
     * @param list<int> $always
     */
    private function configuration(array $always = [], bool $onDemand = false): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setTrustZoneEnum(TrustZone::LOCAL);

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->_setProperty('uid', 9);
        $configuration->setIdentifier('skill-usage');
        $configuration->setLlmModel($model);
        $configuration->setSystemPrompt('You are the configured assistant.');
        foreach ($always as $uid) {
            $configuration->addSkill($this->skills[$uid]);
        }

        if ($onDemand) {
            $configuration->addSkillOnDemand($this->skills[self::ON_DEMAND]);
        }

        return $configuration;
    }

    private function loadCall(string $id): ToolCall
    {
        return new ToolCall($id, LoadSkillTool::NAME, ['skill_uid' => self::ON_DEMAND]);
    }

    private function skill(int $uid, string $identifier): Skill
    {
        $skill = new Skill();
        $skill->_setProperty('uid', $uid);
        $skill->setSource(self::SOURCE);
        $skill->setIdentifier($identifier);
        $skill->setName('Skill named ' . $identifier);
        $skill->setDescription('Describes ' . $identifier . ' at length.');
        $skill->setBody('The body of ' . $identifier . ': follow these rules.');
        $skill->setBodyChecksum(hash('sha256', $skill->getBody()));
        $skill->setSupportStatus('full');
        $skill->setEnabled(true);
        $skill->setVersionDigest(SkillVersionDigest::of($skill));

        return $skill;
    }
}
