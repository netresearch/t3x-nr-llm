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
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\EditorAction;
use Netresearch\NrLlm\Domain\ValueObject\ToolPolicyDecision;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\LlmConfigurationServiceInterface;
use Netresearch\NrLlm\Service\Tool\EditorActionCatalogue;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityServiceInterface;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicyInterface;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

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
            array_map(static fn(object $o): string => $o->toolName, $groups[0]->offers),
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
            array_map(static fn(object $o): string => $o->toolName, $groups[0]->offers),
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
            array_map(static fn(object $o): string => $o->toolName, $groups[0]->offers),
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
            static fn(string $toolName): ToolPolicyDecision => in_array($toolName, $allowed, true)
                ? new ToolPolicyDecision($toolName, true, ToolDataClass::PUBLIC_CONTENT, TrustZone::LOCAL, ToolDataClass::SECRET_ADJACENT)
                : new ToolPolicyDecision($toolName, false, ToolDataClass::PUBLIC_CONTENT, TrustZone::LOCAL, ToolDataClass::SECRET_ADJACENT, ToolDenialReason::TOOL_DISABLED),
        );

        $repository = $this->createMock(LlmConfigurationRepository::class);
        $repository->method('findDefault')->willReturn($configuration);

        $configurations = $this->createMock(LlmConfigurationServiceInterface::class);
        $configurations->method('hasAccess')->willReturn($mayUseConfig);

        return new EditorActionCatalogue($availability, $policy, $repository, $configurations);
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
