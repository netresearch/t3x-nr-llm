<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Repository;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\SkillRepository;
use Netresearch\NrLlm\Service\Skill\SkillLoadLookup;
use Netresearch\NrLlm\Service\Skill\SkillSourceLookup;
use Netresearch\NrLlm\Service\Skill\SkillVersionDigest;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * Load modes on the configuration's skill attachments (ADR-214 item 4): two
 * fields share one MM table and are told apart by its load_mode column. A row
 * that existed before the column — written without it — is an 'always' one;
 * each field reads and writes only its own mode, through Extbase and through
 * DataHandler; and the load lookup reads the stored facts a load re-checks.
 */
#[CoversClass(SkillLoadLookup::class)]
final class SkillLoadModeTest extends AbstractFunctionalTestCase
{
    private const MM = 'tx_nrllm_configuration_skill_mm';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('SkillLoadModes.csv');
        // Written without load_mode, like every row before the column existed.
        $this->pool()->getConnectionForTable(self::MM)->insert(self::MM, [
            'uid_local'   => 130,
            'uid_foreign' => 101,
            'sorting'     => 1,
        ]);
    }

    #[Test]
    public function aRowWrittenWithoutALoadModeIsAnAlwaysAttachment(): void
    {
        self::assertSame('always', $this->loadModeOf(130, 101));
    }

    #[Test]
    public function eachFieldReadsOnlyItsOwnMode(): void
    {
        $configuration = $this->configuration();

        self::assertSame(['Always Skill'], $this->names($configuration->getSkills()));
        self::assertSame(['On-demand Skill'], $this->names($configuration->getSkillsOnDemand()));
        self::assertSame(['Always Skill', 'On-demand Skill'], $this->names($configuration->getAttachedSkills()));
    }

    #[Test]
    public function extbaseWritesTheModeOfTheFieldItPersists(): void
    {
        $configuration = $this->configuration();
        $skills        = $this->get(SkillRepository::class);
        self::assertInstanceOf(SkillRepository::class, $skills);
        $disabled = $skills->findByUid(104);
        self::assertInstanceOf(Skill::class, $disabled);

        $configuration->addSkillOnDemand($disabled);
        $repository = $this->get(LlmConfigurationRepository::class);
        self::assertInstanceOf(LlmConfigurationRepository::class, $repository);
        $repository->update($configuration);
        $persistence = $this->get(PersistenceManagerInterface::class);
        self::assertInstanceOf(PersistenceManagerInterface::class, $persistence);
        $persistence->persistAll();

        self::assertSame('on_demand', $this->loadModeOf(130, 104));
        self::assertSame('always', $this->loadModeOf(130, 101), 'the always row is untouched');
    }

    #[Test]
    public function dataHandlerWritesEachFieldsModeAndLeavesTheOtherFieldsRows(): void
    {
        $this->importFixture('BeUsers.csv');
        $backendUser     = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['tx_nrllm_configuration' => [130 => ['skills' => '107']]], []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog, 'DataHandler reported: ' . var_export($dataHandler->errorLog, true));

        self::assertSame('always', $this->loadModeOf(130, 107));
        self::assertNull($this->loadModeOf(130, 101), 'saving the always field replaced its own rows');
        self::assertSame('on_demand', $this->loadModeOf(130, 102), "and left the on-demand field's rows");

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['tx_nrllm_configuration' => [130 => ['skills_on_demand' => '102,101']]], []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog, 'DataHandler reported: ' . var_export($dataHandler->errorLog, true));

        self::assertSame('on_demand', $this->loadModeOf(130, 101));
        self::assertSame('always', $this->loadModeOf(130, 107), 'saving the on-demand field left the always rows');
    }

    #[Test]
    public function theLookupSeesOnlyOnDemandAttachments(): void
    {
        $lookup = new SkillLoadLookup($this->pool(), new SkillSourceLookup($this->pool()));

        self::assertTrue($lookup->isAttachedOnDemand(130, 102));
        self::assertFalse($lookup->isAttachedOnDemand(130, 101), 'an always attachment is not an on-demand one');
        self::assertFalse($lookup->isAttachedOnDemand(131, 102), 'another configuration');
    }

    #[Test]
    public function theLookupReturnsTheDigestOfALoadableRecordOnly(): void
    {
        $lookup = new SkillLoadLookup($this->pool(), new SkillSourceLookup($this->pool()));

        self::assertSame('1:' . str_repeat('b', 64), $lookup->loadableDigest(102));
        self::assertNull($lookup->loadableDigest(103), 'a process skill');
        self::assertNull($lookup->loadableDigest(104), 'a disabled skill');
        self::assertNull($lookup->loadableDigest(105), 'a hidden skill');
        self::assertNull($lookup->loadableDigest(106), 'an orphaned skill');
        self::assertNull($lookup->loadableDigest(999), 'a missing skill');
    }

    #[Test]
    public function theLookupReadsTheStoredTrustLevel(): void
    {
        $lookup = new SkillLoadLookup($this->pool(), new SkillSourceLookup($this->pool()));
        $this->pool()->getConnectionForTable('tx_nrllm_skill')->update('tx_nrllm_skill', ['trust_level' => 'verified'], ['uid' => 102]);

        self::assertSame('verified', $lookup->trustLevel(102));

        $this->pool()->getConnectionForTable('tx_nrllm_skill')->update('tx_nrllm_skill', ['trust_level' => 'untrusted'], ['uid' => 102]);

        self::assertSame('untrusted', $lookup->trustLevel(102));
        self::assertNull($lookup->trustLevel(999), 'a missing skill');
    }

    #[Test]
    public function theLookupReadsTheStoredSource(): void
    {
        $lookup = new SkillLoadLookup($this->pool(), new SkillSourceLookup($this->pool()));
        $this->pool()->getConnectionForTable('tx_nrllm_skill')->update('tx_nrllm_skill', ['source' => 3], ['uid' => 102]);

        self::assertSame(3, $lookup->storedSource(102));

        $this->pool()->getConnectionForTable('tx_nrllm_skill')->update('tx_nrllm_skill', ['source' => 4], ['uid' => 102]);

        self::assertSame(4, $lookup->storedSource(102));

        $this->pool()->getConnectionForTable('tx_nrllm_skill')->update('tx_nrllm_skill', ['deleted' => 1], ['uid' => 102]);

        self::assertNull($lookup->storedSource(102), 'a deleted skill');
        self::assertNull($lookup->storedSource(999), 'a missing skill');
    }

    /**
     * A skill written in the backend stores no digest; the lookup computes
     * it from the current fields, as the catalogue does, so it can be loaded.
     */
    #[Test]
    public function theLookupComputesTheDigestOfABackendSkillFromItsFields(): void
    {
        $this->pool()->getConnectionForTable('tx_nrllm_skill_source')->insert('tx_nrllm_skill_source', [
            'uid'         => 2,
            'title'       => 'Written here',
            'type'        => 'backend',
            'trust_level' => 'verified',
        ]);
        $this->pool()->getConnectionForTable('tx_nrllm_skill')->insert('tx_nrllm_skill', [
            'uid'            => 108,
            'source'         => 2,
            'identifier'     => 'lm:backend',
            'name'           => 'Backend Skill',
            'description'    => 'Written here.',
            'body'           => 'Do it this way.',
            'support_status' => 'full',
            'enabled'        => 1,
        ]);

        $skills = $this->get(SkillRepository::class);
        self::assertInstanceOf(SkillRepository::class, $skills);
        $skill = $skills->findByUid(108);
        self::assertInstanceOf(Skill::class, $skill);

        $lookup = new SkillLoadLookup($this->pool(), new SkillSourceLookup($this->pool()));

        self::assertSame(SkillVersionDigest::of($skill), $lookup->loadableDigest(108));

        $this->pool()->getConnectionForTable('tx_nrllm_skill')->update('tx_nrllm_skill', ['body' => 'Edited.'], ['uid' => 108]);

        self::assertNotSame(SkillVersionDigest::of($skill), $lookup->loadableDigest(108), 'an edit is another version');
    }

    private function configuration(): LlmConfiguration
    {
        $repository = $this->get(LlmConfigurationRepository::class);
        self::assertInstanceOf(LlmConfigurationRepository::class, $repository);
        $configuration = $repository->findByUid(130);
        self::assertInstanceOf(LlmConfiguration::class, $configuration);

        return $configuration;
    }

    private function loadModeOf(int $configurationUid, int $skillUid): ?string
    {
        $mode = $this->pool()->getConnectionForTable(self::MM)
            ->select(['load_mode'], self::MM, ['uid_local' => $configurationUid, 'uid_foreign' => $skillUid])
            ->fetchOne();

        return is_string($mode) ? $mode : null;
    }

    /**
     * @param iterable<Skill> $skills
     *
     * @return list<string>
     */
    private function names(iterable $skills): array
    {
        $names = [];
        foreach ($skills as $skill) {
            $names[] = $skill->getName();
        }

        return $names;
    }

    private function pool(): ConnectionPool
    {
        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);

        return $pool;
    }
}
