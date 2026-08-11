<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\UseCase;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Preset\ConfigurationPresetImportService;
use Netresearch\NrLlm\Service\Prompt\ConfigurationSnippetResolver;
use Netresearch\NrLlm\Service\UseCase\EditorialStarterPackProvider;
use Netresearch\NrLlm\Service\UseCase\UseCasePack;
use Netresearch\NrLlm\Service\UseCase\UseCasePackInstaller;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * The installer against a real database — where "already installed" has to mean
 * something.
 *
 * The idempotency case is the one worth the functional cost: it installs the
 * shipped pack, edits a created record the way an operator would, installs
 * again, and asserts that the second run created nothing and changed nothing.
 */
#[CoversClass(UseCasePackInstaller::class)]
final class UseCasePackInstallerTest extends AbstractFunctionalTestCase
{
    private UseCasePackInstaller $installer;

    private TaskRepository $taskRepository;

    private PromptSnippetRepository $snippetRepository;

    private LlmConfigurationRepository $configurationRepository;

    private PersistenceManagerInterface $persistenceManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installer = $this->getService(UseCasePackInstaller::class);
        $this->taskRepository = $this->getService(TaskRepository::class);
        $this->snippetRepository = $this->getService(PromptSnippetRepository::class);
        $this->configurationRepository = $this->getService(LlmConfigurationRepository::class);
        $this->persistenceManager = $this->getService(PersistenceManagerInterface::class);
    }

    private function pack(): UseCasePack
    {
        return (new EditorialStarterPackProvider())->getPacks()[0];
    }

    /**
     * Providers and models the preset's `chat` requirement can resolve against.
     */
    private function importModels(): void
    {
        $this->importFixture('Providers.csv');
        $this->importFixture('Models.csv');
    }

    #[Test]
    public function installCreatesTheConfigurationTasksAndSnippets(): void
    {
        $this->importModels();
        $pack = $this->pack();

        $result = $this->installer->install($pack);

        self::assertTrue($result->createdConfiguration);
        self::assertCount(count($pack->tasks), $result->createdTasks);
        self::assertCount(count($pack->snippets), $result->createdSnippets);
        self::assertSame([], $result->skippedTasks);
        self::assertSame([], $result->skippedSnippets);

        foreach ($pack->tasks as $packTask) {
            self::assertInstanceOf(
                Task::class,
                $this->taskRepository->findOneByIdentifier($packTask->identifier),
                $packTask->identifier . ' was reported as created but is not in the database.',
            );
        }

        foreach ($pack->snippets as $packSnippet) {
            self::assertInstanceOf(
                PromptSnippet::class,
                $this->snippetRepository->findOneByIdentifier($packSnippet->identifier),
            );
        }
    }

    #[Test]
    public function theCreatedConfigurationSelectsThePacksSnippetTags(): void
    {
        // Without the tag link the pack's snippets would be installed and then
        // composed into nothing.
        $this->importModels();
        $pack = $this->pack();

        $this->installer->install($pack);

        $configuration = $this->configurationRepository->findOneByIdentifier($pack->configurationPreset->identifier);
        self::assertInstanceOf(LlmConfiguration::class, $configuration);
        self::assertSame($pack->getSnippetTags(), $configuration->getSnippetTagList());
    }

    /**
     * The preset-first sequence, walked end to end.
     *
     * The pack's configuration preset is published to the ConfigurationPreset
     * registry, so the Configuration module's "Pending presets" card offers it
     * and importing it there creates the configuration — with no snippet tags,
     * because a preset does not carry any. If the installer only linked its
     * tags on a record it created itself, this order would install the pack's
     * snippets and compose them into nothing, silently.
     */
    #[Test]
    public function aConfigurationImportedThroughThePresetFirstStillGetsTheSnippetLink(): void
    {
        $this->importModels();
        $pack = $this->pack();

        $this->getService(ConfigurationPresetImportService::class)->import($pack->configurationPreset);
        $this->persistenceManager->persistAll();

        $result = $this->installer->install($pack);
        self::assertFalse($result->createdConfiguration, 'the preset import already created it');

        $configuration = $this->configurationRepository->findOneByIdentifier($pack->configurationPreset->identifier);
        self::assertInstanceOf(LlmConfiguration::class, $configuration);
        self::assertSame($pack->getSnippetTags(), $configuration->getSnippetTagList());

        // The tag list is the means; being read is the end. Ask the one reader
        // that turns a selection into prompt text.
        $selected = $this->getService(ConfigurationSnippetResolver::class)->selectedSnippets($configuration);
        $identifiers = array_map(
            static fn(PromptSnippet $snippet): string => $snippet->getIdentifier(),
            $selected,
        );
        foreach ($pack->snippets as $packSnippet) {
            self::assertContains(
                $packSnippet->identifier,
                $identifiers,
                $packSnippet->identifier . ' was installed but is composed into nothing.',
            );
        }
    }

    #[Test]
    public function planReportsTheMissingTagLinkAndKeepsOfferingTheConfirmButton(): void
    {
        $this->importModels();
        $pack = $this->pack();
        $this->getService(ConfigurationPresetImportService::class)->import($pack->configurationPreset);
        $this->persistenceManager->persistAll();

        $plan = $this->installer->plan($pack);

        self::assertSame($pack->getSnippetTags(), $plan->missingSnippetTags);
        self::assertTrue($plan->isInstallable());
        self::assertFalse($plan->isFullyInstalled());
    }

    #[Test]
    public function afterAFullInstallNothingIsPendingIncludingTheTagLink(): void
    {
        $this->importModels();
        $pack = $this->pack();
        $this->installer->install($pack);

        $plan = $this->installer->plan($pack);

        self::assertSame([], $plan->missingSnippetTags);
        self::assertTrue($plan->isFullyInstalled());
        self::assertFalse($plan->isInstallable());
    }

    #[Test]
    public function theTagLinkAddsToTheOperatorsSelectionInsteadOfReplacingIt(): void
    {
        $this->importModels();
        $pack = $this->pack();

        $imported = $this->getService(ConfigurationPresetImportService::class)->import($pack->configurationPreset);
        $imported->setSnippetTags('legal_notice');

        $this->configurationRepository->update($imported);
        $this->persistenceManager->persistAll();

        $this->installer->install($pack);

        $configuration = $this->configurationRepository->findOneByIdentifier($pack->configurationPreset->identifier);
        self::assertInstanceOf(LlmConfiguration::class, $configuration);
        self::assertSame(
            ['legal_notice', ...$pack->getSnippetTags()],
            $configuration->getSnippetTagList(),
        );
    }

    #[Test]
    public function aTagLinkThatWouldOverflowTheColumnRefusesTheInstall(): void
    {
        // Truncating would silently drop tags the operator selected, and an
        // unguarded write would surface as a database error. Refuse instead,
        // before any task or snippet is created.
        $this->importModels();
        $pack = $this->pack();

        $imported = $this->getService(ConfigurationPresetImportService::class)->import($pack->configurationPreset);
        // 242 characters: fits the varchar(255) column, and adding the pack's
        // `tone_of_voice,audience` (23 more) does not.
        $imported->setSnippetTags(implode(',', array_map(
            static fn(int $i): string => 'tag' . $i,
            range(1, 42),
        )));
        $this->configurationRepository->update($imported);
        $this->persistenceManager->persistAll();

        try {
            $this->installer->install($pack);
            self::fail('Expected the install to be refused rather than truncating the tag selection.');
        } catch (InvalidArgumentException $e) {
            self::assertSame(UseCasePackInstaller::CODE_SNIPPET_TAGS_TOO_LONG, $e->getCode());
        }

        self::assertNull($this->taskRepository->findOneByIdentifier($pack->tasks[0]->identifier));
        self::assertNull($this->snippetRepository->findOneByIdentifier($pack->snippets[0]->identifier));
    }

    #[Test]
    public function planNamesTheExistingConfigurationsThePacksSnippetsWouldReach(): void
    {
        // A snippet is selected by tag, not by owner (ADR-031): this
        // configuration composes the pack's house-style snippet the moment it
        // exists, and the operator confirms a screen that has to say so.
        $this->importModels();
        $other = new LlmConfiguration();
        $other->setPid(0);
        $other->setIdentifier('house-blog');
        $other->setName('House blog');
        $other->setSnippetTags('tone_of_voice');
        $other->setIsActive(true);

        $this->configurationRepository->add($other);
        $this->persistenceManager->persistAll();

        $plan = $this->installer->plan($this->pack());

        self::assertSame(
            [['identifier' => 'house-blog', 'name' => 'House blog']],
            $plan->affectedConfigurations,
        );
    }

    #[Test]
    public function anExistingConfigurationIsNotReportedOnceThePacksSnippetsAreInstalled(): void
    {
        // Nothing new enters its prompt on a second install, so warning about
        // it again would be noise the operator learns to click past.
        $this->importModels();
        $other = new LlmConfiguration();
        $other->setPid(0);
        $other->setIdentifier('house-blog');
        $other->setName('House blog');
        $other->setSnippetTags('tone_of_voice');
        $other->setIsActive(true);

        $this->configurationRepository->add($other);
        $this->persistenceManager->persistAll();

        $this->installer->install($this->pack());

        self::assertSame([], $this->installer->plan($this->pack())->affectedConfigurations);
    }

    #[Test]
    public function theCreatedTasksPointAtThePacksConfiguration(): void
    {
        $this->importModels();
        $pack = $this->pack();

        $this->installer->install($pack);

        $configuration = $this->configurationRepository->findOneByIdentifier($pack->configurationPreset->identifier);
        self::assertInstanceOf(LlmConfiguration::class, $configuration);

        $task = $this->taskRepository->findOneByIdentifier($pack->tasks[0]->identifier);
        self::assertInstanceOf(Task::class, $task);
        self::assertSame($configuration->getUid(), $task->getConfiguration()?->getUid());
        // An ordinary task: nothing marks it as pack-owned.
        self::assertFalse($task->isSystem());
    }

    #[Test]
    public function aSecondInstallCreatesNothingAndLeavesOperatorEditsAlone(): void
    {
        $this->importModels();
        $pack = $this->pack();
        $this->installer->install($pack);

        $identifier = $pack->tasks[0]->identifier;
        $task = $this->taskRepository->findOneByIdentifier($identifier);
        self::assertInstanceOf(Task::class, $task);
        $task->setName('Renamed by the operator');
        $task->setPromptTemplate('Our own prompt {{input}}');

        $this->taskRepository->update($task);
        $this->persistenceManager->persistAll();

        $second = $this->installer->install($pack);

        self::assertFalse($second->createdConfiguration);
        self::assertSame([], $second->createdTasks);
        self::assertSame([], $second->createdSnippets);
        self::assertCount(count($pack->tasks), $second->skippedTasks);
        self::assertSame(0, $second->getCreatedCount());

        $reloaded = $this->taskRepository->findOneByIdentifier($identifier);
        self::assertInstanceOf(Task::class, $reloaded);
        self::assertSame('Renamed by the operator', $reloaded->getName());
        self::assertSame('Our own prompt {{input}}', $reloaded->getPromptTemplate());
    }

    #[Test]
    public function aDisabledPackRecordStillCountsAsInstalled(): void
    {
        // Otherwise "install again" would quietly resurrect what the operator
        // switched off.
        $this->importModels();
        $pack = $this->pack();
        $this->installer->install($pack);

        $identifier = $pack->snippets[0]->identifier;
        $snippet = $this->snippetRepository->findOneByIdentifier($identifier);
        self::assertInstanceOf(PromptSnippet::class, $snippet);
        $snippet->setHidden(true);
        $this->snippetRepository->update($snippet);
        $this->persistenceManager->persistAll();

        // Assert the record really is disabled, so the rest of this test cannot
        // pass for the wrong reason.
        $rows = $this->connectionRows('tx_nrllm_promptsnippet', $identifier);
        self::assertCount(1, $rows);
        $hidden = $rows[0]['hidden'];
        self::assertIsNumeric($hidden);
        self::assertSame(1, (int)$hidden);

        $plan = $this->installer->plan($pack);
        self::assertTrue($plan->isFullyInstalled());

        $second = $this->installer->install($pack);
        self::assertSame([], $second->createdSnippets);
        self::assertCount(
            1,
            $this->connectionRows('tx_nrllm_promptsnippet', $identifier),
            'A second install duplicated the disabled snippet.',
        );
    }

    #[Test]
    public function anUnsatisfiableConfigurationRefusesTheWholeInstall(): void
    {
        // No providers or models imported: nothing can satisfy `chat`. A
        // half-install would leave tasks pointing at a configuration that does
        // not exist.
        $pack = $this->pack();

        try {
            $this->installer->install($pack);
            self::fail('Expected the install to be refused without a satisfying model.');
        } catch (InvalidArgumentException $e) {
            self::assertSame(UseCasePackInstaller::CODE_CONFIGURATION_UNSATISFIABLE, $e->getCode());
        }

        self::assertNull($this->taskRepository->findOneByIdentifier($pack->tasks[0]->identifier));
        self::assertNull($this->snippetRepository->findOneByIdentifier($pack->snippets[0]->identifier));
    }

    #[Test]
    public function planReportsEveryDeclaredRecordAsPendingOnAFreshInstallation(): void
    {
        $this->importModels();
        $pack = $this->pack();

        $plan = $this->installer->plan($pack);

        self::assertSame(1 + count($pack->tasks) + count($pack->snippets), $plan->getPendingCount());
        self::assertTrue($plan->isInstallable());
        self::assertTrue($plan->preflight->satisfiable);
        // plan() is read-only.
        self::assertNull($this->taskRepository->findOneByIdentifier($pack->tasks[0]->identifier));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function connectionRows(string $table, string $identifier): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        /** @var list<array<string, mixed>> $rows */
        $rows = $queryBuilder
            ->select('uid', 'hidden')
            ->from($table)
            ->where($queryBuilder->expr()->eq('identifier', $queryBuilder->createNamedParameter($identifier)))
            ->executeQuery()
            ->fetchAllAssociative();

        return $rows;
    }
}
