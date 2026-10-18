<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\Process;

use Netresearch\NrLlm\Controller\Backend\ProcessSkillCatalogueController;
use Netresearch\NrLlm\Domain\Enum\ServiceAccountScope;
use Netresearch\NrLlm\Domain\Model\BackendUserGroup;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\InvocableProcessSkill;
use Netresearch\NrLlm\Service\Agent\Process\ProcessSkillCatalogue;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

/**
 * The process skills an actor may start on a configuration (ADR-214 item 10,
 * ADR-228), and the AJAX route that serves them to the slash-command menu.
 */
#[CoversClass(ProcessSkillCatalogue::class)]
#[CoversClass(ProcessSkillCatalogueController::class)]
final class ProcessSkillCatalogueTest extends AbstractFunctionalTestCase
{
    use ApprovedSkillsTrait;

    private const SOURCE = 10;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $this->seedSkillSource(self::SOURCE);
    }

    /**
     * Only an approved instruction marked a process is listed: not a guide,
     * not an unapproved process, not a disabled one.
     */
    #[Test]
    public function itListsTheApprovedProcessesOnly(): void
    {
        $disabled = $this->approvedSkill(8, self::SOURCE, process: true);
        $disabled->setEnabled(false);

        $configuration = $this->configuration(
            $this->approvedSkill(5, self::SOURCE, process: true),
            $this->approvedSkill(6, self::SOURCE, process: false),
            $this->approvedSkill(7, self::SOURCE, process: true, approve: false),
            $disabled,
        );

        self::assertSame([5], $this->uids($this->catalogue()->forActor($configuration, AiActorContext::backendUser(2))));
    }

    #[Test]
    public function aSourceBelowTheInstructionThresholdListsNothing(): void
    {
        $configuration = $this->configuration($this->approvedSkill(5, self::SOURCE, process: true));
        $this->pool()->getConnectionForTable('tx_nrllm_skill_source')->update('tx_nrllm_skill_source', ['trust_level' => 'community'], ['uid' => self::SOURCE]);

        self::assertSame([], $this->catalogue()->forActor($configuration, AiActorContext::backendUser(2)));
    }

    #[Test]
    public function aFourEyesConfigurationListsNothing(): void
    {
        $configuration = $this->configuration($this->approvedSkill(5, self::SOURCE, process: true));
        $configuration->setRequireSecondApprover(true);

        self::assertSame([], $this->catalogue()->forActor($configuration, AiActorContext::backendUser(2)));
    }

    #[Test]
    public function aServiceAccountIsOfferedNothing(): void
    {
        $configuration = $this->configuration($this->approvedSkill(5, self::SOURCE, process: true));

        self::assertSame([], $this->catalogue()->forActor($configuration, AiActorContext::serviceAccount('nightly', [ServiceAccountScope::CONFIGURATION_USE])));
    }

    #[Test]
    public function onlyAnActorWhoMayUseTheConfigurationIsOfferedItsProcesses(): void
    {
        $configuration = $this->configuration($this->approvedSkill(5, self::SOURCE, process: true));
        $group         = new BackendUserGroup();
        $group->_setProperty('uid', 7);
        /** @var ObjectStorage<BackendUserGroup> $groups */
        $groups = new ObjectStorage();
        $groups->attach($group);

        $configuration->setBeGroups($groups);

        self::assertSame([], $this->catalogue()->forActor($configuration, AiActorContext::backendUser(2)));
        self::assertSame([5], $this->uids($this->catalogue()->forActor($configuration, AiActorContext::backendUser(2, backendGroupIds: [7]))));
    }

    #[Test]
    public function theRouteServesTheListForTheCurrentUser(): void
    {
        $this->setUpBackendUser(2);
        $configuration = $this->configuration($this->approvedSkill(5, self::SOURCE, process: true));

        $response = $this->controller($configuration)->listAction((new ServerRequest('/'))->withQueryParams(['configuration' => '30']));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        self::assertSame([['uid' => 5, 'identifier' => '10:skills/tour-5/SKILL.md', 'name' => 'Tour 5', 'description' => 'A guided tour.']], $body['skills']);
    }

    /**
     * An unknown, malformed or deactivated configuration answers 404 alike.
     */
    #[Test]
    public function theRouteAnswers404ForAConfigurationItCannotServe(): void
    {
        $this->setUpBackendUser(2);
        $configuration = $this->configuration($this->approvedSkill(5, self::SOURCE, process: true));

        foreach (['99', 'abc', '', '-1'] as $raw) {
            $response = $this->controller($configuration)->listAction((new ServerRequest('/'))->withQueryParams(['configuration' => $raw]));
            self::assertSame(404, $response->getStatusCode(), $raw);
        }

        $configuration->setIsActive(false);
        self::assertSame(404, $this->controller($configuration)->listAction((new ServerRequest('/'))->withQueryParams(['configuration' => '30']))->getStatusCode());
    }

    private function controller(LlmConfiguration $configuration): ProcessSkillCatalogueController
    {
        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findByUid')->willReturnCallback(static fn(int $uid): ?LlmConfiguration => $uid === 30 ? $configuration : null);

        return new ProcessSkillCatalogueController($repository, $this->catalogue());
    }

    private function catalogue(): ProcessSkillCatalogue
    {
        return new ProcessSkillCatalogue(new ConfigurationResolver(), $this->composerFactory()->create());
    }

    private function configuration(Skill ...$skills): LlmConfiguration
    {
        $configuration = new LlmConfiguration();
        $configuration->_setProperty('uid', 30);
        $configuration->setIdentifier('cfg-process');
        $configuration->setIsActive(true);
        foreach ($skills as $skill) {
            $configuration->addSkill($skill);
        }

        return $configuration;
    }

    /**
     * @param list<InvocableProcessSkill> $skills
     *
     * @return list<int>
     */
    private function uids(array $skills): array
    {
        return array_map(static fn(InvocableProcessSkill $skill): int => $skill->uid, $skills);
    }
}
