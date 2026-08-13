<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\UseCasePackController;
use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Service\Preset\ConfigurationPreset;
use Netresearch\NrLlm\Service\Preset\ConfigurationPresetRegistry;
use Netresearch\NrLlm\Service\UseCase\EditorialStarterPackProvider;
use Netresearch\NrLlm\Service\UseCase\PackToolReadinessInterface;
use Netresearch\NrLlm\Service\UseCase\UseCase;
use Netresearch\NrLlm\Service\UseCase\UseCasePack;
use Netresearch\NrLlm\Service\UseCase\UseCasePackInstaller;
use Netresearch\NrLlm\Service\UseCase\UseCasePackRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request as ExtbaseRequest;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * The two use-case screens, rendered (ADR-163).
 *
 * Fluid reaches a getter only through the get/is/has convention, and a wrong
 * name is not an error: `{plan.isInstallable}` resolves to null and the confirm
 * button silently disappears. Nothing below the controller catches that — the
 * plan DTO's unit test passes either way. So the templates are rendered through
 * the real Fluid stack with the variables the controller assigns.
 */
#[CoversNothing]
final class UseCasePackRenderTest extends AbstractFunctionalTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    private function pack(): UseCasePack
    {
        return (new EditorialStarterPackProvider())->getPacks()[0];
    }

    #[Test]
    public function theContainerCanBuildTheController(): void
    {
        // A hand-wired controller in a test would hide an argument production
        // cannot autowire; asking the container cannot. The pack registry's
        // tagged iterator is exactly the wiring worth asserting.
        $this->extbaseRequest('index');

        self::assertInstanceOf(UseCasePackController::class, $this->getService(UseCasePackController::class));
    }

    #[Test]
    public function theShippedPackIsRegisteredThroughTheDiTag(): void
    {
        $registry = $this->getService(UseCasePackRegistry::class);

        self::assertInstanceOf(UseCasePack::class, $registry->findByIdentifier('editorial-starter'));
        self::assertNotSame([], $registry->forUseCase(UseCase::EDITORIAL));
    }

    #[Test]
    public function thePacksPresetReachesTheConfigurationPresetRegistry(): void
    {
        // The bridge is only worth having if the container tags it: this is
        // what puts the pack's configuration into the Configuration module's
        // pending list, and therefore what makes the preset-first install order
        // reachable at all.
        $presets = $this->getService(ConfigurationPresetRegistry::class)->pending();
        $identifiers = array_map(
            static fn(ConfigurationPreset $preset): string => $preset->identifier,
            $presets,
        );

        self::assertContains('nr_llm.editorial_starter', $identifiers);
    }

    #[Test]
    public function theEntryStepOffersEveryUseCaseAndSaysWhichHaveNoPack(): void
    {
        $body = $this->renderIndex();

        self::assertStringContainsString('Editorial assistance', $body);
        self::assertStringContainsString('Media accessibility', $body, 'the question is offered whole');
        self::assertStringContainsString('Editorial Starter', $body, 'the one pack that exists is offered');
        self::assertStringContainsString('No pack yet', $body, 'an unanswered use case says so');
    }

    #[Test]
    public function thePlanScreenOffersTheConfirmButtonWhenSomethingWouldBeCreated(): void
    {
        $this->importFixture('Providers.csv');
        $this->importFixture('Models.csv');

        $body = $this->renderShow();

        self::assertStringContainsString('Would be created', $body);
        self::assertStringContainsString('Create the missing records', $body, 'the confirm button must render');
        // Recommended, not applied — both halves are stated on the page.
        self::assertStringContainsString('Controlled cloud', $body);
        self::assertStringContainsString('does not change any governance setting', $body);
        self::assertStringContainsString('does not enable these', $body);
        // The tag link is not a record and is written anyway, so it is named.
        self::assertStringContainsString('adds these snippet tags', $body);
        self::assertStringContainsString('tone_of_voice', $body);
    }

    #[Test]
    public function thePlanScreenNamesTheConfigurationsThePacksSnippetsWouldReach(): void
    {
        $this->importFixture('Providers.csv');
        $this->importFixture('Models.csv');

        $other = new LlmConfiguration();
        $other->setPid(0);
        $other->setIdentifier('house-blog');
        $other->setName('House blog');
        $other->setSnippetTags('tone_of_voice');
        $other->setIsActive(true);
        $this->getService(LlmConfigurationRepository::class)->add($other);
        $this->getService(PersistenceManagerInterface::class)->persistAll();

        $body = $this->renderShow();

        self::assertStringContainsString('already select one of the', $body);
        self::assertStringContainsString('House blog', $body);
    }

    #[Test]
    public function thePlanScreenNamesTheExistingSnippetsTheAddedTagsWouldPullIn(): void
    {
        $this->importFixture('Providers.csv');
        $this->importFixture('Models.csv');

        $snippet = new PromptSnippet();
        $snippet->setPid(0);
        $snippet->setIdentifier('internal-voice');
        $snippet->setName('Internal voice');
        $snippet->setSnippet('Never mention the client by name.');
        $snippet->setTags('tone_of_voice');
        $snippet->setDataClass(ToolDataClass::SECRET_ADJACENT->value);
        $snippet->setIsActive(true);
        $this->getService(PromptSnippetRepository::class)->add($snippet);
        $this->getService(PersistenceManagerInterface::class)->persistAll();

        $body = $this->renderShow();

        self::assertStringContainsString('already carry one of the tags above', $body);
        self::assertStringContainsString('Internal voice', $body);
        // The data class is the part that can refuse a send, so it has to be on
        // the screen the operator confirms — not only in the plan object.
        self::assertStringContainsString(ToolDataClass::SECRET_ADJACENT->value, $body);
    }

    #[Test]
    public function everyEditorActionAndToolGroupTheShippedPacksNameExistsHere(): void
    {
        // The enum check issue #769 asked for, applied where it is safe: our own
        // packs, against the real registry. It is not a constructor throw —
        // both sets are open for third-party packs, and throwing would fire
        // inside UseCasePackRegistry's constructor (ADR-168).
        $readiness = $this->getService(PackToolReadinessInterface::class);

        foreach ($this->getService(UseCasePackRegistry::class)->all() as $pack) {
            foreach ($readiness->editorActionStates($pack->recommendedEditorActions) as $state) {
                self::assertTrue(
                    $state->declared,
                    sprintf('Pack "%s" names editor action "%s", which no registered tool declares.', $pack->identifier, $state->toolName),
                );
            }

            foreach ($readiness->toolGroupStates($pack->recommendedToolGroups) as $state) {
                self::assertTrue(
                    $state->registered,
                    sprintf('Pack "%s" recommends tool group "%s", which no registered tool carries.', $pack->identifier, $state->group),
                );
            }
        }
    }

    #[Test]
    public function theShippedPackDeclaresNoEditorAction(): void
    {
        // Deliberate, and asserted so it stays deliberate: Editorial Starter's
        // four tasks are text transforms run in the Tasks module. An editor
        // action runs on the DEFAULT configuration, not on the pack's, so its
        // house-style snippet would not reach one — declaring an action here
        // would claim a link the records do not have (ADR-168).
        self::assertSame([], $this->pack()->recommendedEditorActions);
    }

    #[Test]
    public function thePlanScreenShowsADeclaredEditorActionWithItsStateAndRecordTypes(): void
    {
        $this->importFixture('Providers.csv');
        $this->importFixture('Models.csv');

        $body = $this->renderShow($this->packDeclaring(['set_file_alternative_text']));

        self::assertStringContainsString('Editor actions this pack is designed for', $body);
        self::assertStringContainsString('set_file_alternative_text', $body);
        // The record types are the half that says what the action is FOR.
        self::assertStringContainsString('sys_file', $body);
        // The group is the half that says WHERE the switch is: the Tools module
        // lists the action under it (ADR-135 puts the writers in `editing`).
        self::assertStringContainsString('Tool group:', $body);
        self::assertStringContainsString('<code>editing</code>', $body);
        // Disabled by default, and the screen has to say so rather than imply
        // the pack turns it on.
        self::assertStringContainsString('Disabled', $body);
        self::assertStringContainsString('does not enable them and does not run them', $body);
        // Nothing on this screen may offer to enable or run the action.
        self::assertStringNotContainsString('Enable this action', $body);
    }

    #[Test]
    public function thePlanScreenNamesADeclaredEditorActionThisInstallationDoesNotHave(): void
    {
        // The central regression: a typo (or an uninstalled provider extension)
        // must be a visible row, not a silently dropped one.
        $this->importFixture('Providers.csv');
        $this->importFixture('Models.csv');

        $body = $this->renderShow($this->packDeclaring(['set_file_alternativ_text']));

        self::assertStringContainsString('set_file_alternativ_text', $body);
        self::assertStringContainsString('Not available here', $body);
        self::assertStringContainsString('is not registered on this installation', $body);
    }

    #[Test]
    public function thePlanScreenMarksARecommendedToolGroupNoToolCarries(): void
    {
        $this->importFixture('Providers.csv');
        $this->importFixture('Models.csv');

        $body = $this->renderShow($this->packDeclaring([], ['contnet']));

        self::assertStringContainsString('contnet', $body);
        self::assertStringContainsString('Not available here', $body);
        // The badge says the group is missing; only the paragraph says that
        // installing the pack will not produce it. The editor-action card
        // carries the same sentence, and the two screens have to match.
        self::assertStringContainsString('carried by no registered tool on this installation', $body);
    }

    #[Test]
    public function theToolGroupExplanationIsAbsentWhenEveryRecommendedGroupExists(): void
    {
        $this->importFixture('Providers.csv');
        $this->importFixture('Models.csv');

        $body = $this->renderShow($this->packDeclaring([], ['content']));

        self::assertStringNotContainsString('carried by no registered tool on this installation', $body);
        self::assertStringNotContainsString('Not available here', $body);
    }

    #[Test]
    public function thePlanScreenExplainsAnUnsatisfiableSetupInsteadOfOfferingTheButton(): void
    {
        // No providers or models: nothing satisfies `chat`.
        $body = $this->renderShow();

        self::assertStringContainsString('No active model satisfies', $body);
        self::assertStringNotContainsString('Create the missing records', $body);
    }

    private function renderIndex(): string
    {
        $registry = $this->getService(UseCasePackRegistry::class);

        $groups = [];
        foreach (UseCase::cases() as $useCase) {
            $packs = $registry->forUseCase($useCase);
            $groups[] = ['useCase' => $useCase, 'packs' => $packs, 'hasPacks' => $packs !== []];
        }

        return $this->render('Backend/UseCase/Index', 'index', [
            'groups' => $groups,
            'wizardUrl' => '/typo3/module/nrllm/wizard',
        ]);
    }

    /**
     * The shipped pack with a declaration bolted on — the shipped one declares
     * no editor action, and inventing one there to demonstrate the feature
     * would make the pack claim something untrue.
     *
     * @param list<string> $editorActions
     * @param list<string> $toolGroups
     */
    private function packDeclaring(array $editorActions, array $toolGroups = ['content']): UseCasePack
    {
        $pack = $this->pack();

        return new UseCasePack(
            identifier: $pack->identifier,
            useCase: $pack->useCase,
            name: $pack->name,
            description: $pack->description,
            configurationPreset: $pack->configurationPreset,
            recommendedGovernanceProfile: $pack->recommendedGovernanceProfile,
            tasks: $pack->tasks,
            snippets: $pack->snippets,
            recommendedToolGroups: $toolGroups,
            recommendedEditorActions: $editorActions,
        );
    }

    private function renderShow(?UseCasePack $pack = null): string
    {
        $pack ??= $this->pack();

        return $this->render('Backend/UseCase/Show', 'show', [
            'pack' => $pack,
            'plan' => $this->getService(UseCasePackInstaller::class)->plan($pack),
            'wizardUrl' => '/typo3/module/nrllm/wizard',
            'governanceUrl' => '/typo3/module/nrllm/overview',
            'toolsUrl' => '/typo3/module/nrllm/tools',
            'tasksUrl' => '/typo3/module/nrllm/tasks',
            'snippetsUrl' => '/typo3/module/nrllm/snippets',
            'configurationsUrl' => '/typo3/module/nrllm/configurations',
            'editorActionsUrl' => '/typo3/module/web/nrllm-aitasks',
        ]);
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function render(string $template, string $action, array $variables): string
    {
        $view = $this->getService(ViewFactoryInterface::class)->create(new ViewFactoryData(
            templateRootPaths: ['EXT:nr_llm/Resources/Private/Templates/'],
            partialRootPaths: ['EXT:nr_llm/Resources/Private/Partials/'],
            // The templates declare <f:layout name="Module" />, which ships
            // with EXT:backend rather than with this extension.
            layoutRootPaths: [
                'EXT:nr_llm/Resources/Private/Layouts/',
                'EXT:backend/Resources/Private/Layouts/',
            ],
            templatePathAndFilename: GeneralUtility::getFileAbsFileName(
                'EXT:nr_llm/Resources/Private/Templates/' . $template . '.html',
            ),
            request: $this->extbaseRequest($action),
        ));
        $view->assignMultiple($variables);

        return $view->render();
    }

    /**
     * The Module layout renders f:flashMessages, which resolves its queue from
     * an extbase request. A plain PSR-7 request makes the LAYOUT fail before
     * the template's own markup is reached.
     */
    private function extbaseRequest(string $action): ExtbaseRequest
    {
        $parameters = new ExtbaseRequestParameters();
        $parameters->setControllerName('Backend\\UseCasePack');
        $parameters->setControllerActionName($action);
        $parameters->setControllerExtensionName('NrLlm');

        $serverRequest = (new ServerRequest('https://typo3-testing.local/typo3/', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('extbase', $parameters);
        $serverRequest = $serverRequest->withAttribute('normalizedParams', NormalizedParams::createFromRequest($serverRequest));

        // Extbase's ConfigurationManager reads the ambient request, not the one
        // handed to the view.
        $GLOBALS['TYPO3_REQUEST'] = $serverRequest;

        return new ExtbaseRequest($serverRequest);
    }
}
