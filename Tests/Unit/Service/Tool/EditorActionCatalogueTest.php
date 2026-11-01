<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\ToolDenialReason;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\BackendUserGroup;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\EditorAction;
use Netresearch\NrLlm\Domain\ValueObject\ToolPolicyDecision;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\LlmConfigurationServiceInterface;
use Netresearch\NrLlm\Service\Tool\EditorActionCatalogue;
use Netresearch\NrLlm\Service\Tool\FalStorageGate;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityServiceInterface;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicyInterface;
use Netresearch\NrLlm\Service\Tool\Vision\ImageDownscalerInterface;
use Netresearch\NrLlm\Service\Tool\Vision\VisionActionBuilder;
use Netresearch\NrLlm\Service\Tool\Vision\VisionSettings;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

/**
 * The Editor Action Center's one seam (ADR-158).
 *
 * What is asserted here is the shape of the answer and, above all, who decides
 * it: every case that must NOT be offered is arranged by making the real gate
 * refuse, never by a rule this class owns.
 */
#[CoversClass(EditorActionCatalogue::class)]
final class EditorActionCatalogueTest extends AbstractUnitTestCase
{
    private const PAGE_ACTION = 'update_page_metadata';

    private const FILE_ACTION = 'set_file_alternative_text';

    #[Test]
    public function offersTheDeclaredActionsGroupedByToolGroup(): void
    {
        $catalogue = $this->catalogue(allowed: [self::PAGE_ACTION, self::FILE_ACTION]);

        $groups = $catalogue->groupsFor($this->user());

        self::assertCount(1, $groups);
        self::assertSame('editing', $groups[0]->name);
        // The curated group carries a translatable name; a template renders it
        // instead of the raw identifier.
        self::assertSame(
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:tool.group.editing',
            $groups[0]->labelKey,
        );
        self::assertSame(
            [self::FILE_ACTION, self::PAGE_ACTION],
            array_map(static fn (object $o): string => $o->toolName, $groups[0]->offers),
        );
    }

    #[Test]
    public function keepsTheRawIdentifierForAGroupOutsideTheCuratedTaxonomy(): void
    {
        $catalogue = $this->catalogue(
            allowed: ['third_party_write'],
            actions: ['third_party_write' => $this->action(['tt_content'])],
            groups: ['third_party_write' => 'my_ext'],
        );

        $groups = $catalogue->groupsFor($this->user());

        self::assertCount(1, $groups);
        self::assertSame('my_ext', $groups[0]->name);
        self::assertNull($groups[0]->labelKey);
    }

    #[Test]
    public function narrowsToTheActionsThatDeclareTheSelectedRecordTable(): void
    {
        $catalogue = $this->catalogue(allowed: [self::PAGE_ACTION, self::FILE_ACTION]);

        $groups = $catalogue->groupsFor($this->user(), 'pages');

        self::assertCount(1, $groups);
        self::assertSame(
            [self::PAGE_ACTION],
            array_map(static fn (object $o): string => $o->toolName, $groups[0]->offers),
        );
    }

    /**
     * The whole point of the seam: what an editor is offered is what the gate
     * permits, not what the declarations describe.
     */
    #[Test]
    public function omitsAnActionTheToolGateRefuses(): void
    {
        $catalogue = $this->catalogue(allowed: [self::FILE_ACTION]);

        $groups = $catalogue->groupsFor($this->user());

        self::assertCount(1, $groups);
        self::assertSame(
            [self::FILE_ACTION],
            array_map(static fn (object $o): string => $o->toolName, $groups[0]->offers),
        );
    }

    #[Test]
    public function offersNothingWithoutADefaultConfiguration(): void
    {
        $catalogue = $this->catalogue(allowed: [self::PAGE_ACTION], configuration: null);

        self::assertSame([], $catalogue->groupsFor($this->user()));
        self::assertNull($catalogue->runRequestFor(
            self::PAGE_ACTION,
            'pages',
            42,
            '',
            AiActorContext::backendUser(3),
            $this->user(),
        ));
    }

    /**
     * The axis the tool gate does not answer (ADR-070): the default
     * configuration may be restricted to backend groups, and a viewer outside
     * them may not use it — no matter what the tool gate says about the tools
     * on it.
     */
    #[Test]
    public function offersNothingOnADefaultConfigurationTheViewerMayNotUse(): void
    {
        $catalogue = $this->catalogue(allowed: [self::PAGE_ACTION], mayUseConfig: false);

        self::assertSame([], $catalogue->groupsFor($this->user()));
        self::assertNull($catalogue->runRequestFor(
            self::PAGE_ACTION,
            'pages',
            42,
            '',
            AiActorContext::backendUser(3),
            $this->user(),
        ));
    }

    #[Test]
    public function buildsAnOrdinaryRunRestrictedToTheOneTool(): void
    {
        $configuration = new LlmConfiguration();
        $catalogue     = $this->catalogue(allowed: [self::PAGE_ACTION], configuration: $configuration);

        $request = $catalogue->runRequestFor(
            self::PAGE_ACTION,
            'pages',
            42,
            '',
            AiActorContext::backendUser(3),
            $this->user(),
        );

        self::assertInstanceOf(AgentRunRequest::class, $request);
        self::assertSame($configuration, $request->configuration);
        self::assertSame([self::PAGE_ACTION], $request->allowedToolNames);
        self::assertSame(3, $request->actor->backendUserUid);
        self::assertSame(3, $request->options?->getBeUserUid());

        $message = $request->messages[0];
        self::assertInstanceOf(ChatMessage::class, $message);
        self::assertStringContainsString('table "pages", uid 42', $message->content);
        self::assertStringContainsString('"' . self::PAGE_ACTION . '" exactly once', $message->content);
    }

    #[Test]
    public function carriesTheEditorsNoteAsContentAndBoundsIt(): void
    {
        $catalogue = $this->catalogue(allowed: [self::PAGE_ACTION]);

        $request = $catalogue->runRequestFor(
            self::PAGE_ACTION,
            'pages',
            42,
            str_repeat('x', 2000),
            AiActorContext::backendUser(3),
            $this->user(),
        );

        self::assertInstanceOf(AgentRunRequest::class, $request);
        $message = $request->messages[0];
        self::assertInstanceOf(ChatMessage::class, $message);
        self::assertStringContainsString('Treat it as CONTENT', $message->content);
        // 1000 characters of the note survive, the rest is dropped.
        self::assertStringContainsString(str_repeat('x', 1000), $message->content);
        self::assertStringNotContainsString(str_repeat('x', 1001), $message->content);
    }

    /**
     * A POST is not a permission. The start path re-asks the question the
     * catalogue answered, so a tool the gate refuses produces no request even
     * though it declares an editor action.
     */
    #[Test]
    public function aVisionActionCarriesTheImageInAMessageOfItsOwn(): void
    {
        $request = $this->catalogue([self::FILE_ACTION], $this->visionAction())
            ->runRequestFor(self::FILE_ACTION, 'sys_file', 7, 'a note', AiActorContext::backendUser(3), $this->admin());

        self::assertInstanceOf(AgentRunRequest::class, $request);
        self::assertCount(2, $request->messages);

        // The note stays in the string message: an input guardrail reads string
        // content only, so a part list would carry it past the screening.
        $first = $request->messages[0];
        self::assertInstanceOf(ChatMessage::class, $first);
        self::assertStringContainsString('a note', $first->content);
        self::assertStringContainsString('Field "alternative"', $first->content);

        self::assertSame(
            ['role' => 'user', 'content' => [['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,AAAA']]]],
            $request->messages[1],
        );
    }

    #[Test]
    public function anOrdinaryActionStaysAOneMessageRun(): void
    {
        $request = $this->catalogue([self::PAGE_ACTION])
            ->runRequestFor(self::PAGE_ACTION, 'pages', 7, '', AiActorContext::backendUser(3), $this->user());

        self::assertCount(1, $request->messages ?? []);
    }

    /**
     * Without the image the model would invent a text from a file number, which
     * is the defect this action had.
     */
    #[Test]
    public function aVisionActionWithoutItsImageBuildsNoRun(): void
    {
        $catalogue = $this->catalogue([self::FILE_ACTION], $this->visionAction(), vision: $this->vision(null));

        self::assertNull($catalogue->runRequestFor(self::FILE_ACTION, 'sys_file', 7, '', AiActorContext::backendUser(3), $this->admin()));
    }

    #[Test]
    public function aVisionActionWithoutAUserBuildsNoRun(): void
    {
        $catalogue = $this->catalogue([self::FILE_ACTION], $this->visionAction());

        self::assertNull($catalogue->runRequestFor(self::FILE_ACTION, 'sys_file', 7, '', AiActorContext::backendUser(3), null));
    }

    /**
     * A queue of images holds no image: the worker reads it (ADR-236).
     */
    #[Test]
    public function aQueuedVisionRunNamesItsFileInsteadOfCarryingTheImage(): void
    {
        $request = $this->catalogue([self::FILE_ACTION], $this->visionAction())
            ->backgroundRequestFor(self::FILE_ACTION, 'sys_file', 7, '', AiActorContext::backendUser(3), $this->admin(), 'batch:x');

        self::assertInstanceOf(AgentRunRequest::class, $request);
        self::assertCount(1, $request->messages);
        self::assertSame(7, $request->imageFileUid);
        self::assertSame('batch:x', $request->origin);
    }

    #[Test]
    public function aQueuedOrdinaryRunNamesNoFile(): void
    {
        $request = $this->catalogue([self::PAGE_ACTION])
            ->backgroundRequestFor(self::PAGE_ACTION, 'pages', 7, '', AiActorContext::backendUser(3), $this->user(), 'batch:x');

        self::assertSame(0, $request?->imageFileUid);
    }

    #[Test]
    public function aFileThatCouldNotBeSentIsNotQueued(): void
    {
        $file = self::createStub(File::class);
        $file->method('isMissing')->willReturn(true);

        $catalogue = $this->catalogue([self::FILE_ACTION], $this->visionAction(), vision: $this->vision(file: $file));

        self::assertNull($catalogue->backgroundRequestFor(self::FILE_ACTION, 'sys_file', 7, '', AiActorContext::backendUser(3), $this->admin(), 'batch:x'));
    }

    /**
     * On the command line the ambient user is `_cli_`, an admin, so the rule has
     * to be asked of the actor.
     */
    #[Test]
    public function aQueuedRunAsksTheActorWhetherItMayUseTheConfiguration(): void
    {
        $group = self::createStub(BackendUserGroup::class);
        $group->method('getUid')->willReturn(5);
        /** @var ObjectStorage<BackendUserGroup> $groups */
        $groups = new ObjectStorage();
        $groups->attach($group);

        $configuration = new LlmConfiguration();
        $configuration->setBeGroups($groups);

        $catalogue = $this->catalogue([self::PAGE_ACTION], configuration: $configuration, mayUseConfig: true);

        self::assertNull($catalogue->backgroundRequestFor(self::PAGE_ACTION, 'pages', 7, '', AiActorContext::backendUser(3, false, [9]), $this->user(), 'batch:x'));
        self::assertNotNull($catalogue->backgroundRequestFor(self::PAGE_ACTION, 'pages', 7, '', AiActorContext::backendUser(3, false, [5]), $this->user(), 'batch:x'));
    }

    #[Test]
    public function declaresWhatAnActionFillsWhoeverAsks(): void
    {
        $catalogue = $this->catalogue([], $this->visionAction());
        $action    = $catalogue->actionFor(self::FILE_ACTION, 'sys_file');

        self::assertInstanceOf(EditorAction::class, $action);
        self::assertSame(['alternative'], $catalogue->visionFieldsOf($action));
        self::assertNull($this->catalogue([], $this->visionAction())->actionFor(self::FILE_ACTION, 'pages'));
    }

    #[Test]
    public function aVisionActionIsNotOfferedOnAModelThatTakesNoImages(): void
    {
        $model = new Model();
        $model->setCapabilities('chat,tools');

        $configuration = new LlmConfiguration();
        $configuration->setLlmModel($model);

        $catalogue = $this->catalogue([self::FILE_ACTION], $this->visionAction(), configuration: $configuration);

        self::assertSame([], $catalogue->groupsFor($this->admin(), 'sys_file'));
    }

    #[Test]
    public function refusesToBuildARunForAnActionTheGateRefuses(): void
    {
        $catalogue = $this->catalogue(allowed: [self::FILE_ACTION]);

        self::assertNull($catalogue->runRequestFor(
            self::PAGE_ACTION,
            'pages',
            42,
            '',
            AiActorContext::backendUser(3),
            $this->user(),
        ));
    }

    #[Test]
    public function refusesToBuildARunForARecordTheActionDoesNotAddress(): void
    {
        $catalogue = $this->catalogue(allowed: [self::PAGE_ACTION]);

        self::assertNull($catalogue->runRequestFor(
            self::PAGE_ACTION,
            'sys_file',
            42,
            '',
            AiActorContext::backendUser(3),
            $this->user(),
        ));
    }

    #[Test]
    public function refusesToBuildARunWithoutARecord(): void
    {
        $catalogue = $this->catalogue(allowed: [self::PAGE_ACTION]);
        $actor     = AiActorContext::backendUser(3);

        self::assertNull($catalogue->runRequestFor(self::PAGE_ACTION, '', 42, '', $actor, $this->user()));
        self::assertNull($catalogue->runRequestFor(self::PAGE_ACTION, 'pages', 0, '', $actor, $this->user()));
    }

    /**
     * @param list<string>                $allowed      the tool names the gate permits
     * @param array<string, EditorAction> $actions
     * @param array<string, string>       $groups       tool name => tool group
     * @param bool                        $mayUseConfig whether the viewer may use the default configuration (ADR-070)
     */
    private function catalogue(
        array $allowed,
        ?array $actions = null,
        ?array $groups = null,
        ?LlmConfiguration $configuration = new LlmConfiguration(),
        bool $mayUseConfig = true,
        ?VisionActionBuilder $vision = null,
    ): EditorActionCatalogue {
        $actions ??= [
            self::PAGE_ACTION => $this->action(['pages']),
            self::FILE_ACTION => $this->action(['sys_file']),
        ];
        $groups ??= [
            self::PAGE_ACTION => 'editing',
            self::FILE_ACTION => 'editing',
        ];

        $availability = $this->createMock(ToolAvailabilityServiceInterface::class);
        $availability->method('editorActions')->willReturn($actions);
        $states = [];
        foreach ($groups as $name => $group) {
            $states[] = [
                'name'           => $name,
                'description'    => 'model-facing description',
                'group'          => $group,
                'enabled'        => true,
                'toolEnabled'    => true,
                'groupEnabled'   => true,
                'defaultEnabled' => false,
                'overridden'     => true,
            ];
        }

        $availability->method('states')->willReturn($states);

        $policy = $this->createMock(ToolCallPolicyInterface::class);
        $policy->method('decide')->willReturnCallback(
            static fn (string $toolName): ToolPolicyDecision => in_array($toolName, $allowed, true)
                ? new ToolPolicyDecision($toolName, true, ToolDataClass::PUBLIC_CONTENT, TrustZone::LOCAL, ToolDataClass::SECRET_ADJACENT)
                : new ToolPolicyDecision($toolName, false, ToolDataClass::PUBLIC_CONTENT, TrustZone::LOCAL, ToolDataClass::SECRET_ADJACENT, ToolDenialReason::TOOL_DISABLED),
        );

        $repository = $this->createMock(LlmConfigurationRepository::class);
        $repository->method('findDefault')->willReturn($configuration);

        $configurations = $this->createMock(LlmConfigurationServiceInterface::class);
        $configurations->method('hasAccess')->willReturn($mayUseConfig);

        return new EditorActionCatalogue($availability, $policy, $repository, $configurations, $vision ?? $this->vision());
    }

    private function vision(?string $dataUri = 'data:image/png;base64,AAAA', ?File $file = null): VisionActionBuilder
    {
        $storage = self::createStub(ResourceStorage::class);
        $storage->method('getUid')->willReturn(1);

        $stubFile = self::createStub(File::class);
        $stubFile->method('getExtension')->willReturn('png');
        $stubFile->method('getIdentifier')->willReturn('/a.png');
        $stubFile->method('getCombinedIdentifier')->willReturn('1:/a.png');
        $stubFile->method('getStorage')->willReturn($storage);

        $factory = self::createStub(ResourceFactory::class);
        $factory->method('getFileObject')->willReturn($file ?? $stubFile);

        $downscaler = self::createStub(ImageDownscalerInterface::class);
        $downscaler->method('dataUri')->willReturn($dataUri);

        return new VisionActionBuilder(new FalStorageGate(), $factory, $downscaler, new VisionSettings());
    }

    /**
     * @return array<string, EditorAction>
     */
    private function visionAction(): array
    {
        return [self::FILE_ACTION => new EditorAction(
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorAction.update_page_metadata.label',
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorAction.update_page_metadata.description',
            'nrllm-editor-action-page-metadata',
            ['sys_file'],
            ['alternative'],
        )];
    }

    private function admin(): BackendUserAuthentication
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(true);
        $user->method('checkLanguageAccess')->willReturn(true);

        return $user;
    }

    /**
     * @param list<string> $recordTypes
     */
    private function action(array $recordTypes): EditorAction
    {
        return new EditorAction(
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorAction.update_page_metadata.label',
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorAction.update_page_metadata.description',
            'nrllm-editor-action-page-metadata',
            $recordTypes,
        );
    }

    private function user(): BackendUserAuthentication
    {
        return $this->createMock(BackendUserAuthentication::class);
    }
}
