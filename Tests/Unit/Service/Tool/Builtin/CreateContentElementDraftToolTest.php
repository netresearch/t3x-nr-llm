<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Service\Tool\Builtin\CreateContentElementDraftTool;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Argument validation of the fourth writing tool — the first that creates a
 * record (ADR-146).
 *
 * Every assertion here stops the call BEFORE the database is touched, so a stub
 * {@see ConnectionPool} is enough. The creation itself — page permissions, the
 * new uid, the hidden state, the read-back — is exercised against a real
 * database in
 * {@see \Netresearch\NrLlm\Tests\Functional\Service\Tool\CreateContentElementDraftToolTest}.
 */
#[CoversClass(CreateContentElementDraftTool::class)]
final class CreateContentElementDraftToolTest extends AbstractUnitTestCase
{
    private CreateContentElementDraftTool $tool;

    /** @var array<string, mixed> */
    private array $globalsBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->globalsBackup = [
            'TCA'     => $GLOBALS['TCA'] ?? null,
            'LANG'    => $GLOBALS['LANG'] ?? null,
            'BE_USER' => $GLOBALS['BE_USER'] ?? null,
        ];

        // `textmedia` and `bullets` come from fluid_styled_content and are
        // deliberately ABSENT, so the allow-list ∩ live-TCA intersection is
        // genuinely exercised. `html` is present and must still be unreachable.
        $GLOBALS['TCA'] = ['tt_content' => [
            'ctrl'    => ['enablecolumns' => ['disabled' => 'hidden']],
            'columns' => [
                'CType' => ['config' => ['type' => 'select', 'items' => [
                    ['label' => 'Header only', 'value' => 'header'],
                    ['label' => 'Text', 'value' => 'text'],
                    ['label' => 'Raw HTML', 'value' => 'html'],
                ]]],
                'header'   => ['config' => ['type' => 'input']],
                'bodytext' => ['config' => ['type' => 'text']],
                'hidden'   => ['config' => ['type' => 'check']],
            ],
        ]];
        $GLOBALS['LANG']    = self::createStub(LanguageService::class);
        $GLOBALS['BE_USER'] = $this->liveUser();

        $this->tool = new CreateContentElementDraftTool(self::createStub(ConnectionPool::class));
    }

    protected function tearDown(): void
    {
        foreach ($this->globalsBackup as $key => $value) {
            if ($value === null) {
                unset($GLOBALS[$key]);

                continue;
            }

            $GLOBALS[$key] = $value;
        }

        parent::tearDown();
    }

    #[Test]
    public function itDeclaresANonIdempotentWriteEffect(): void
    {
        // A creation with no caller-supplied key: two runs leave two elements.
        // The reaper must not repeat it, and this declaration is what stops it.
        self::assertInstanceOf(ToolEffectInterface::class, $this->tool);
        self::assertSame(ToolEffect::NON_IDEMPOTENT_WRITE, $this->tool->getEffect());
        self::assertTrue($this->tool->getEffect()->isWrite());
        self::assertFalse($this->tool->getEffect()->isSafeToRetry());
    }

    #[Test]
    public function itShipsDisabledAndIsNotAdminOnly(): void
    {
        self::assertFalse($this->tool->isEnabledByDefault(), 'a writing tool is never on by default');
        self::assertFalse($this->tool->requiresAdmin(), 'an editor drafts what the backend already grants them');
        self::assertSame('editing', $this->tool->getGroup());
    }

    #[Test]
    public function itOffersAPreviewSoTheApprovalCardCanShowTheWholeDraft(): void
    {
        self::assertInstanceOf(ToolPreviewInterface::class, $this->tool);
    }

    #[Test]
    public function theSpecOffersNoWayToPublish(): void
    {
        $spec = $this->tool->getSpec();

        self::assertSame('create_content_element_draft', $spec->name);
        self::assertSame(['page', 'type', 'header'], $spec->parameters['required'] ?? null);

        $properties = $spec->parameters['properties'] ?? null;
        self::assertIsArray($properties);
        // The element is always hidden; there is no argument for it, which is
        // the whole proposition of a draft tool.
        self::assertArrayNotHasKey('hidden', $properties);
        self::assertArrayNotHasKey('visible', $properties);
        self::assertArrayNotHasKey('publish', $properties);
    }

    #[Test]
    public function itFailsClosedWithoutAnActingBackendUser(): void
    {
        $result = $this->tool->execute(
            ['page' => 1, 'type' => 'text', 'header' => 'x'],
            ToolExecutionContext::none(),
        );

        self::assertTrue($result->isError);
        self::assertSame('Page not found or not permitted.', $result->content);
    }

    #[Test]
    public function itRefusesOutsideTheLiveWorkspace(): void
    {
        $draftUser            = $this->liveUser();
        $draftUser->workspace = 1;

        $result = $this->tool->execute(
            ['page' => 1, 'type' => 'text', 'header' => 'x'],
            $this->contextFor($draftUser),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('live workspace', $result->content);
    }

    #[Test]
    public function itRefusesAndNamesEachMissingPieceOfTheBackendEnvironment(): void
    {
        unset($GLOBALS['TCA'], $GLOBALS['LANG'], $GLOBALS['BE_USER']);

        $result = $this->tool->execute(
            ['page' => 1, 'type' => 'text', 'header' => 'x'],
            $this->contextFor($this->liveUser()),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('TCA', $result->content);
        self::assertStringContainsString('language service', $result->content);
        self::assertStringContainsString('backend user', $result->content);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusedArguments(): iterable
    {
        $valid = ['page' => 1, 'type' => 'text', 'header' => 'x'];

        yield 'no page'    => [['type' => 'text', 'header' => 'x'], 'exactly one page'];
        yield 'zero page'  => [['page' => 0, 'type' => 'text', 'header' => 'x'], 'exactly one page'];
        yield 'negative page' => [['page' => -1, 'type' => 'text', 'header' => 'x'], 'exactly one page'];
        yield 'no type'    => [['page' => 1, 'header' => 'x'], 'not a content type this tool creates'];
        // Present in the fixture TCA and still unreachable: the allow-list is
        // the bar, the TCA only narrows it further.
        yield 'html type'  => [['page' => 1, 'type' => 'html', 'header' => 'x'], 'not a content type this tool creates'];
        yield 'plugin type' => [['page' => 1, 'type' => 'list', 'header' => 'x'], 'not a content type this tool creates'];
        // Allow-listed but absent from the fixture TCA, so this installation
        // cannot render it and the tool must not offer it.
        yield 'type not in this tca' => [
            ['page' => 1, 'type' => 'textmedia', 'header' => 'x'],
            'not a content type this tool creates',
        ];
        yield 'no header'    => [['page' => 1, 'type' => 'text'], '"header" is required'];
        yield 'empty header' => [['page' => 1, 'type' => 'text', 'header' => ''], 'must not be empty'];
        yield 'blank header' => [['page' => 1, 'type' => 'text', 'header' => '   '], 'must not be empty'];
        yield 'array header' => [['page' => 1, 'type' => 'text', 'header' => ['x']], 'must be a string'];
        yield 'long header'  => [
            ['page' => 1, 'type' => 'text', 'header' => str_repeat('a', 256)],
            'exceeds 255 characters',
        ];
        yield 'long body' => [
            ['page' => 1, 'type' => 'text', 'header' => 'x', 'bodytext' => str_repeat('a', 20001)],
            'exceeds 20000 characters',
        ];
        yield 'negative language' => [$valid + ['language' => -1], 'zero or a positive'];
        yield 'negative column'   => [$valid + ['column' => -1], 'zero or a positive'];
        yield 'unknown argument'  => [$valid + ['hidden' => 0], 'not an argument of this tool'];
        // The one that would defeat the tool's single guarantee.
        yield 'pid smuggled in'   => [$valid + ['pid' => 5], 'not an argument of this tool'];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('refusedArguments')]
    public function itRefusesInvalidArguments(array $arguments, string $expectedFragment): void
    {
        $result = $this->tool->execute($arguments, $this->contextFor($this->liveUser()));

        self::assertTrue($result->isError);
        self::assertStringContainsString($expectedFragment, $result->content);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('refusedArguments')]
    public function thePreviewRefusesTheSameArguments(array $arguments, string $expectedFragment): void
    {
        $lines = $this->tool->previewCall($arguments, $this->contextFor($this->liveUser()));

        self::assertCount(1, $lines);
        self::assertStringContainsString($expectedFragment, $lines[0]);
    }

    #[Test]
    public function theOfferedTypesAreTheAllowListNarrowedByTheLiveTca(): void
    {
        $description = $this->tool->getSpec()->parameters['properties']['type']['description'] ?? '';
        self::assertIsString($description);

        // The description names the full allow-list — it is what a model should
        // choose from across installations — while the refusal above names only
        // what THIS installation can render.
        self::assertStringContainsString('textmedia', $description);
        self::assertStringNotContainsString('html', $description);
        self::assertStringNotContainsString('list', $description);
    }

    #[Test]
    public function anUnknownArgumentNameIsEchoedBackStrippedOfAnythingButItsIdentifierCharacters(): void
    {
        $result = $this->tool->execute(
            ['page' => 1, 'type' => 'text', 'header' => 'x', "colPos\n<script>" => 1],
            $this->contextFor($this->liveUser()),
        );

        self::assertTrue($result->isError);
        self::assertStringNotContainsString('<', $result->content);
        self::assertStringContainsString('colPosscript', $result->content);
    }

    #[Test]
    public function itRefusesALanguageTheActingUserMayNotEdit(): void
    {
        $restricted = $this->liveUser();
        // Not an admin, and no `allowed_languages` — so only the default
        // language is reachable.
        $restricted->user           = ['uid' => 5, 'admin' => 0];
        $restricted->groupData['allowed_languages'] = '0';

        $result = $this->tool->execute(
            ['page' => 1, 'type' => 'text', 'header' => 'x', 'language' => 2],
            $this->contextFor($restricted),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('may not edit content in language 2', $result->content);
    }

    private function liveUser(): BackendUserAuthentication
    {
        $user            = new BackendUserAuthentication();
        $user->user      = ['uid' => 1, 'admin' => 1];
        $user->workspace = 0;

        return $user;
    }

    private function contextFor(BackendUserAuthentication $user): ToolExecutionContext
    {
        return ToolExecutionContext::fromBackendUser($user);
    }
}
