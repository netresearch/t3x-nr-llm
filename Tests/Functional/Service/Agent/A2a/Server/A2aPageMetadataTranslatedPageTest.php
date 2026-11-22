<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskStore;
use Netresearch\NrLlm\Service\Tool\Scope\AssignmentScopeHook;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\RegistersTheFailingHookTrait;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\RunsANestedToolDataHandlerHook;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * An approved write of the page metadata skill to a default-language page
 * that has translations, the shape of the A2A acceptance on the demo: the
 * request names language 0, so the run's scope admits language 0 only, and
 * core's data-map processor adds an entry for each translation of the page
 * to the write. The write is stored as a draft and reported as written.
 *
 * The report the A2A client reads matches what is stored in the other two
 * outcomes too: a call the scope refused a part of keeps what was in scope,
 * and the `writes` artifact names it; a call that is rolled back stores
 * nothing and names nothing.
 */
#[CoversClass(AssignmentScopeHook::class)]
#[CoversClass(A2aTaskStore::class)]
final class A2aPageMetadataTranslatedPageTest extends AbstractFunctionalTestCase
{
    use A2aPageMetadataApprovedWrite;
    use A2aPageMetadataSkillFixture;
    use RegistersTheFailingHookTrait;

    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend', 'workspaces'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSkill();
        RunsANestedToolDataHandlerHook::reset();
    }

    protected function tearDown(): void
    {
        $this->unregisterHook('processDatamapClass', RunsANestedToolDataHandlerHook::class);
        RunsANestedToolDataHandlerHook::reset();
        $this->tearDownSkill();
        parent::tearDown();
    }

    #[Test]
    public function aDraftOfAPageWithTranslationsIsStoredAndReportedAsWritten(): void
    {
        $translations = $this->rowsOfTheTranslations();

        [$run, $taskId] = $this->approvedWrite();

        self::assertSame('Eine kurze Beschreibung.', $this->draftDescription(), "the value is a draft in the client's workspace");
        self::assertSame('Old ' . self::PAGE, $this->description(self::PAGE), 'live is unchanged');
        self::assertSame($translations, $this->rowsOfTheTranslations(), 'the translations are not written and get no draft');

        $toolResult = $this->toolResultOf($this->stored($run));
        self::assertStringContainsString('Updated page [' . self::PAGE . ']', $toolResult);
        self::assertStringNotContainsString('did not complete', $toolResult);

        $writes = $this->writesOf($taskId);
        self::assertStringContainsString('Writes executed (1)', $writes);
        self::assertStringContainsString('pages:' . self::PAGE . ' in workspace ' . self::WORKSPACE, $writes);
        self::assertStringNotContainsString('No write was executed', $writes);
        self::assertStringNotContainsString('did not complete', $writes);
    }

    /**
     * What core does with the same write when no run is involved: the
     * technical user saves the page's description in the workspace through a
     * plain DataHandler, with nothing armed, and core gives the translation it
     * may edit a version of its own, which holds no changed value (#1432). The
     * translation in a language the user may not edit is refused by core. The
     * skill's own write differs only because the scope leaves core's entry for
     * the translation out (ADR-295).
     */
    #[Test]
    public function coreVersionsTheTranslationWhenTheUserWritesThePageInTheWorkspace(): void
    {
        $this->db('be_groups')->update('be_groups', ['tables_select' => 'pages', 'tables_modify' => 'pages', 'non_exclude_fields' => 'pages:description', 'pagetypes_select' => '1'], ['uid' => self::GROUP]);
        $user            = $this->setUpBackendUser(7);
        $user->workspace = self::WORKSPACE;

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['pages' => [self::PAGE => ['description' => 'Written by an editor']]], [], $user);
        $dataHandler->process_datamap();

        self::assertSame(['[1.2]: Attempt to modify record pages:' . self::FOREIGN_LANGUAGE_TRANSLATION . ' failed with: ERROR: Language was not allowed.'], $dataHandler->errorLog);
        self::assertSame('Written by an editor', $this->draftDescription());
        self::assertSame([self::PAGE, self::TRANSLATION], $this->versionedPages(), 'core versions the translation the user may edit');
        self::assertSame('Old ' . self::TRANSLATION, $this->draftOf(self::TRANSLATION), 'with no changed value');
    }

    /**
     * An installation hook writes a page outside the scope after the page's
     * write. The scope refuses that write, the call is an error, and the draft
     * it stored before stands: the `writes` artifact names it.
     */
    #[Test]
    public function aDraftThatStandsBesideARefusedWriteIsNamedInTheWritesArtifact(): void
    {
        RunsANestedToolDataHandlerHook::$datamap = ['pages' => [self::NEIGHBOUR => ['description' => 'Written by a hook']]];

        [$run, $taskId] = $this->approvedWrite(RunsANestedToolDataHandlerHook::class);

        self::assertSame('Eine kurze Beschreibung.', $this->draftDescription(), 'the draft stands');
        self::assertFalse($this->draftOf(self::NEIGHBOUR), 'the hook did not write');

        $toolResult = $this->toolResultOf($this->stored($run));
        self::assertStringContainsString('did not complete', $toolResult);
        self::assertStringContainsString('pages:' . self::NEIGHBOUR, $toolResult);

        $writes = $this->writesOf($taskId);
        self::assertStringNotContainsString('No write was executed', $writes);
        self::assertStringContainsString('Records written by tool calls that did not complete (1)', $writes);
        // The action names how core stored the draft: a new version row reads
        // "created" on TYPO3 14 and "updated" on 13, so only the record and the
        // workspace are asserted.
        self::assertMatchesRegularExpression('/^- pages:' . self::PAGE . ' in workspace ' . self::WORKSPACE . ' \((created|updated)\)$/m', $writes);
    }

    /**
     * The same hook with the DataHandler layer of the scope taken away: the
     * check of the effect finds the write outside the scope and rolls the call
     * back, the draft included, and the `writes` artifact names no write, but
     * the record the scope rolled the call back for and its rule (#1438).
     */
    #[Test]
    public function aCallThatIsRolledBackStoresNothingAndIsReportedAsNoWrite(): void
    {
        $this->unregisterHook('processDatamapClass', AssignmentScopeHook::class);
        $this->unregisterHook('processCmdmapClass', AssignmentScopeHook::class);
        RunsANestedToolDataHandlerHook::$datamap = ['pages' => [self::NEIGHBOUR => ['description' => 'Written by a hook']]];

        [$run, $taskId] = $this->approvedWrite(RunsANestedToolDataHandlerHook::class);

        self::assertFalse($this->draftDescription(), 'the rollback took the draft');
        self::assertFalse($this->draftOf(self::NEIGHBOUR), 'and the write of the hook');
        self::assertStringContainsString('All database changes of this call were rolled back.', $this->toolResultOf($this->stored($run)));
        $writes = $this->writesOf($taskId);
        self::assertStringStartsWith('No write was executed.', $writes);
        // The draft the hook wrote of the neighbouring page, by its version's uid.
        self::assertMatchesRegularExpression("/\\nWrites the scope of the task's assignment refused or rolled back \\(\\d+\\), each with the rule it broke:\\n(- .+\\n)*- effect_(created|updated) of pages:\\d+ \\(outside_scope\\)/", $writes);
    }

    /**
     * A failed call whose capture missed writes, or whose list was cut, is not
     * reported as no write: the artifact says the list may miss records. A
     * completed call's record that no announced write names is listed in a
     * block of its own (#1432), never among the failed calls' records.
     */
    #[Test]
    public function aFailedCallWhoseChangesWereNotAllCapturedIsNotReportedAsNoWrite(): void
    {
        $events = [
            ['tool', ['toolIsError' => false]],
            ['tool_changes', ['changes' => [['table' => 'pages', 'uid' => 7, 'action' => 'updated', 'workspace' => 0]], 'changeCount' => 1, 'changeCoverage' => 'datahandler', 'changesOmitted' => 0]],
            ['tool', ['toolIsError' => true]],
            ['tool_changes', ['changes' => [], 'changeCount' => 0, 'changeCoverage' => 'not_captured', 'changesOmitted' => 0]],
            ['tool', ['toolIsError' => true]],
            ['tool_changes', ['changes' => [['table' => 'pages', 'uid' => 9, 'action' => 'created', 'workspace' => 3, 'liveUid' => 8]], 'changeCount' => 1, 'changeCoverage' => 'datahandler', 'changesOmitted' => 0]],
        ];
        foreach ($events as $sequence => [$kind, $payload]) {
            $this->db('tx_nrllm_agentrun_event')->insert('tx_nrllm_agentrun_event', ['run' => 900, 'sequence' => $sequence, 'kind' => $kind, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
        }

        $artifact = (new ReflectionMethod(A2aTaskStore::class, 'writesArtifact'))->invoke($this->getService(A2aTaskStore::class), 900, false);
        $text     = self::text(self::j(self::objectOf($artifact), 'parts.0.text'));

        self::assertStringNotContainsString('No write was executed', $text);
        self::assertStringContainsString("did not complete (1), each in the workspace named (0 is live); whether each change is stored has to be checked:\n- pages:8 in workspace 3 (created)", $text);
        self::assertStringContainsString('may have written records this list does not name', $text);
        self::assertStringContainsString("completed tool calls wrote (1), each in the workspace named (0 is live), for example a version core gave a translation of a record written in a workspace:\n- pages:7 in workspace 0 (updated)\nRecords written by tool calls that did not complete (1)", $text, 'the record of a completed call is listed apart from those of the failed ones');
        self::assertSame(1, substr_count($text, 'pages:7'));
    }

    /**
     * The refusals of a failed call are cut at the limit of its set like its
     * records, and the artifact says so as it does for them. A call that wrote
     * outside the scope and whose rollback could not be confirmed (the database
     * ended the transaction itself) may have kept those writes: the heading
     * does not call them rolled back, and a line says what to check (#1448).
     */
    #[Test]
    public function theRefusedBlockSaysWhenItIsCutAndWhenARollbackIsUnconfirmed(): void
    {
        $refused = static fn (int $from, int $count): array => array_map(static fn (int $uid): array => ['operation' => 'effect_updated', 'table' => 'pages', 'uid' => $uid, 'pid' => 0, 'reason' => 'outside_scope'], range($from, $from + $count - 1));
        $runs    = [
            901 => ['changes' => [], 'changeCount' => 0, 'changeCoverage' => 'datahandler', 'changesOmitted' => 0, 'refused' => $refused(100, 50), 'refusedOmitted' => 7],
            902 => ['changes' => [['table' => 'pages', 'uid' => 7, 'action' => 'updated', 'workspace' => 0]], 'changeCount' => 1, 'changeCoverage' => 'datahandler', 'changesOmitted' => 0, 'refused' => $refused(200, 1), 'rollbackUnconfirmed' => true],
            903 => ['changes' => [], 'changeCount' => 0, 'changeCoverage' => 'datahandler', 'changesOmitted' => 0, 'refused' => $refused(300, 1)],
        ];
        $sequence = 0;
        foreach ($runs as $run => $changes) {
            foreach ([['tool', ['toolIsError' => true]], ['tool_changes', $changes]] as [$kind, $payload]) {
                $this->db('tx_nrllm_agentrun_event')->insert('tx_nrllm_agentrun_event', ['run' => $run, 'sequence' => ++$sequence, 'kind' => $kind, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
            }
        }

        $text = fn (int $run): string => self::text(self::j(self::objectOf((new ReflectionMethod(A2aTaskStore::class, 'writesArtifact'))->invoke($this->getService(A2aTaskStore::class), $run, false)), 'parts.0.text'));

        $cut = $text(901);
        self::assertStringContainsString("Writes the scope of the task's assignment refused or rolled back (50)", $cut);
        self::assertStringContainsString('more writes refused or found outside the scope than its list keeps; this list may be incomplete.', $cut);
        self::assertStringNotContainsString('could not be confirmed', $cut);

        $kept = $text(902);
        self::assertStringNotContainsString('refused or rolled back', $kept, 'a write that may stand is not called rolled back');
        self::assertStringContainsString("Writes the scope of the task's assignment refused or found outside it (1), each with the rule it broke:\n- effect_updated of pages:200 (outside_scope)", $kept);
        self::assertStringContainsString('The rollback of a tool call that wrote outside the scope could not be confirmed: what it wrote may stand. Check the records.', $kept);
        self::assertStringNotContainsString('may be incomplete', $kept);

        // The control: a call that was rolled back.
        $rolledBack = $text(903);
        self::assertStringContainsString("Writes the scope of the task's assignment refused or rolled back (1), each with the rule it broke:\n- effect_updated of pages:300 (outside_scope)", $rolledBack);
        self::assertStringNotContainsString('could not be confirmed', $rolledBack);
        self::assertStringNotContainsString('may be incomplete', $rolledBack);
    }

    private function draftDescription(): mixed
    {
        return $this->draftOf(self::PAGE);
    }

    /**
     * The translations of the page and their workspace versions, as stored.
     *
     * @return list<array<string, mixed>>
     */
    private function rowsOfTheTranslations(): array
    {
        return $this->db('pages')
            ->executeQuery('SELECT * FROM pages WHERE l10n_parent = ? OR t3ver_oid IN (?, ?) ORDER BY uid', [self::PAGE, self::TRANSLATION, self::FOREIGN_LANGUAGE_TRANSLATION])
            ->fetchAllAssociative();
    }
}
