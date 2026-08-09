<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Feature;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Context\ContextWindowManager;
use Netresearch\NrLlm\Service\Context\TranscriptEstimator;
use Netresearch\NrLlm\Service\Feature\ConversationService;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Session\AiSessionRepository;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The conversation budget must bind the list that is actually SENT (#625).
 *
 * A criteria-mode configuration carries no model relation, so the window falls
 * back to 8192 tokens — against which the skill block the manager injects after
 * the fit is a large share. This exercises the real collaboration: the session
 * store on the real schema, the real configuration↔skill MM relation, the real
 * composer and the real context-window manager.
 */
#[CoversClass(ConversationService::class)]
final class ConversationSkillBudgetTest extends AbstractFunctionalTestCase
{
    /** Unknown context length -> UNKNOWN_WINDOW_FALLBACK, minus the 1000 reserve and the 3 % safety. */
    private const EXPECTED_BUDGET = 8192 - 1000 - 246;

    private const HISTORY_TURNS = 8;

    private const HISTORY_MESSAGE_BYTES = 800;

    private const SKILL_BODY_BYTES = 12000;

    private AiSessionRepository $sessions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sessions = new AiSessionRepository($this->getService(ConnectionPool::class));
        $this->seedConfigurations();
    }

    #[Test]
    public function aLongSessionWithALargeSkillBlockDropsTurnsInsteadOfOverflowing(): void
    {
        $sent = $this->runTurnAgainst('skill-budget-skilled');

        // Turns were dropped from the send: 2 * n messages fewer than the
        // 2 * HISTORY_TURNS history messages plus the new user turn.
        self::assertLessThan(2 * self::HISTORY_TURNS + 1, count($sent));

        // And it dropped ENOUGH: transcript plus the block the manager will
        // inject now fits the window instead of overflowing it.
        $estimator = new TranscriptEstimator();
        $onTheWire = $estimator->estimate($sent, [], 1.15)
            + $estimator->estimate([ChatMessage::user($this->composedBlockFor('skill-budget-skilled'))], [], 1.15);
        self::assertLessThanOrEqual(self::EXPECTED_BUDGET, $onTheWire);
    }

    #[Test]
    public function theSameTranscriptWithoutASkillBlockKeepsEveryTurn(): void
    {
        // The control: identical history, identical window — nothing is dropped
        // when there is no block to make room for. Without it the first test
        // would also pass on a manager that simply prunes too eagerly.
        $sent = $this->runTurnAgainst('skill-budget-plain');

        self::assertCount(2 * self::HISTORY_TURNS + 1, $sent);
    }

    #[Test]
    public function theStoredHistoryIsNeverShortenedByTheFit(): void
    {
        $session = $this->openSeededSession('skill-budget-skilled');
        $this->service()->send($this->actor(), $session['uuid'], 'and now?');

        // Dropping applies to what the model sees, never to the audit record:
        // the seeded history plus this turn's user and assistant rows.
        self::assertCount(2 * self::HISTORY_TURNS + 2, $this->sessions->findMessages($session['uid']));
    }

    /**
     * Run one turn against a seeded session and return what the provider saw.
     *
     * @return list<ChatMessage|array<string, mixed>>
     */
    private function runTurnAgainst(string $identifier): array
    {
        $session = $this->openSeededSession($identifier);
        $sent    = [];

        $this->service($sent)->send($this->actor(), $session['uuid'], 'and now?');

        return $sent;
    }

    /**
     * @param list<ChatMessage|array<string, mixed>> $sent captures what the provider was handed
     */
    private function service(array &$sent = []): ConversationService
    {
        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatForConfiguration')->willReturnCallback(
            static function (array $messages) use (&$sent): CompletionResponse {
                $sent = $messages;

                return new CompletionResponse('answered', 'test-model', UsageStatistics::fromTokens(5, 3));
            },
        );

        return new ConversationService(
            $llmManager,
            $this->sessions,
            new ConfigurationResolver($this->getService(LlmConfigurationRepository::class)),
            new ContextWindowManager(),
            null,
            new SkillComposer(),
        );
    }

    /**
     * A session bound to the configuration, carrying a history long enough to
     * fit the window on its own but not once the block is counted too.
     *
     * @return array{uid: int, uuid: string}
     */
    private function openSeededSession(string $identifier): array
    {
        $uuid = sprintf('%s-0000-4000-8000-000000000000', substr(md5($identifier), 0, 8));
        $uid  = $this->sessions->startSession($uuid, 42, $identifier, 'long chat');

        $sequence = 0;
        for ($turn = 0; $turn < self::HISTORY_TURNS; ++$turn) {
            $this->sessions->appendMessage($uid, $sequence++, 'user', str_repeat('u', self::HISTORY_MESSAGE_BYTES), '', 0, 0, 0);
            $this->sessions->appendMessage($uid, $sequence++, 'assistant', str_repeat('a', self::HISTORY_MESSAGE_BYTES), 'test-model', 0, 0, 0);
        }

        $this->sessions->touch($uid, $sequence);

        return ['uid' => $uid, 'uuid' => $uuid];
    }

    private function composedBlockFor(string $identifier): string
    {
        $configuration = $this->getService(LlmConfigurationRepository::class)->findOneByIdentifier($identifier);
        self::assertInstanceOf(LlmConfiguration::class, $configuration);

        $skills = [];
        foreach ($configuration->getSkills() as $skill) {
            $skills[] = $skill;
        }

        return (new SkillComposer())->composeBlock($skills, [])->block;
    }

    private function actor(): AiActorContext
    {
        return AiActorContext::backendUser(42);
    }

    /**
     * Two configurations without a model relation (criteria mode, `model_uid`
     * 0), one with a large skill attached and one without.
     */
    private function seedConfigurations(): void
    {
        $pool = $this->getService(ConnectionPool::class);

        $pool->getConnectionForTable('tx_nrllm_configuration')->insert('tx_nrllm_configuration', [
            'uid'                  => 620,
            'pid'                  => 0,
            'identifier'           => 'skill-budget-skilled',
            'name'                 => 'Skilled conversation',
            'model_uid'            => 0,
            'model_selection_mode' => 'criteria',
            'is_active'            => 1,
            'skills'               => 1,
        ]);
        $pool->getConnectionForTable('tx_nrllm_configuration')->insert('tx_nrllm_configuration', [
            'uid'                  => 621,
            'pid'                  => 0,
            'identifier'           => 'skill-budget-plain',
            'name'                 => 'Plain conversation',
            'model_uid'            => 0,
            'model_selection_mode' => 'criteria',
            'is_active'            => 1,
            'skills'               => 0,
        ]);

        // Generated rather than kept in a fixture file: the body has to be big
        // enough to matter against the 8192 fallback, and its checksum must
        // match or the composer fails closed and drops it.
        $body = str_repeat('Answer in short, verifiable sentences. ', (int)ceil(self::SKILL_BODY_BYTES / 38));
        $pool->getConnectionForTable('tx_nrllm_skill')->insert('tx_nrllm_skill', [
            'uid'           => 610,
            'pid'           => 0,
            'source'        => 1,
            'identifier'    => 'cfg:budget',
            'name'          => 'Budget Skill',
            'body'          => $body,
            'body_checksum' => hash('sha256', $body),
            'enabled'       => 1,
            'orphaned'      => 0,
        ]);

        $pool->getConnectionForTable('tx_nrllm_configuration_skill_mm')->insert('tx_nrllm_configuration_skill_mm', [
            'uid_local'       => 620,
            'uid_foreign'     => 610,
            'sorting'         => 1,
            'sorting_foreign' => 0,
        ]);
    }
}
