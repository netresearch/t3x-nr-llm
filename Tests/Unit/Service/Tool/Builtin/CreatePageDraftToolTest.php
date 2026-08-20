<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Service\Tool\Builtin\CreatePageDraftTool;
use Netresearch\NrLlm\Service\Tool\EditorActionInterface;
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
 * Argument validation of the sixth writing tool — the first that creates a
 * page (ADR-180).
 *
 * Every assertion here stops the call BEFORE the database is touched, so a stub
 * {@see ConnectionPool} is enough. The creation itself — parent permissions,
 * the new uid, the hidden state, the read-back — is exercised against a real
 * database in
 * {@see \Netresearch\NrLlm\Tests\Functional\Service\Tool\CreatePageDraftToolTest}.
 */
#[CoversClass(CreatePageDraftTool::class)]
final class CreatePageDraftToolTest extends AbstractUnitTestCase
{
    private CreatePageDraftTool $tool;

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

        $GLOBALS['TCA'] = ['pages' => [
            'ctrl'    => ['enablecolumns' => ['disabled' => 'hidden']],
            'columns' => [
                'title'     => ['config' => ['type' => 'input']],
                'nav_title' => ['config' => ['type' => 'input']],
                'doktype'   => ['config' => ['type' => 'select']],
                'hidden'    => ['config' => ['type' => 'check']],
                'slug'      => ['config' => ['type' => 'slug']],
            ],
        ]];
        $GLOBALS['LANG']    = self::createStub(LanguageService::class);
        $GLOBALS['BE_USER'] = $this->liveUser();

        $this->tool = new CreatePageDraftTool(self::createStub(ConnectionPool::class));
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
        // A creation with no caller-supplied key: two runs leave two pages.
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
    public function itOffersAPreviewAndAnEditorActionOnTheParentPage(): void
    {
        self::assertInstanceOf(ToolPreviewInterface::class, $this->tool);
        self::assertInstanceOf(EditorActionInterface::class, $this->tool);
        // The record an editor selects is the PARENT page — the only uid the
        // arguments identify.
        self::assertSame(['pages'], $this->tool->getEditorAction()->recordTypes);
    }

    #[Test]
    public function theSpecOffersNoWayToPublishOrToChooseATypeOrALanguage(): void
    {
        $spec = $this->tool->getSpec();

        self::assertSame('create_page_draft', $spec->name);
        self::assertSame(['parent', 'title'], $spec->parameters['required'] ?? null);

        $properties = $spec->parameters['properties'] ?? null;
        self::assertIsArray($properties);
        // The page is always hidden, always a standard page, always in the
        // default language; there is no argument for any of it.
        self::assertArrayNotHasKey('hidden', $properties);
        self::assertArrayNotHasKey('visible', $properties);
        self::assertArrayNotHasKey('publish', $properties);
        self::assertArrayNotHasKey('doktype', $properties);
        self::assertArrayNotHasKey('type', $properties);
        self::assertArrayNotHasKey('language', $properties);
        self::assertArrayNotHasKey('slug', $properties);
    }

    #[Test]
    public function itFailsClosedWithoutAnActingBackendUser(): void
    {
        $result = $this->tool->execute(['parent' => 1, 'title' => 'x'], ToolExecutionContext::none());

        self::assertTrue($result->isError);
        self::assertSame('Page not found or not permitted.', $result->content);
    }

    #[Test]
    public function itRefusesOutsideTheLiveWorkspace(): void
    {
        $draftUser            = $this->liveUser();
        $draftUser->workspace = 1;

        $result = $this->tool->execute(['parent' => 1, 'title' => 'x'], $this->contextFor($draftUser));

        self::assertTrue($result->isError);
        self::assertStringContainsString('live workspace', $result->content);
    }

    #[Test]
    public function itRefusesAndNamesEachMissingPieceOfTheBackendEnvironment(): void
    {
        unset($GLOBALS['TCA'], $GLOBALS['LANG'], $GLOBALS['BE_USER']);

        $result = $this->tool->execute(['parent' => 1, 'title' => 'x'], $this->contextFor($this->liveUser()));

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
        $valid = ['parent' => 1, 'title' => 'x'];

        yield 'no parent'       => [['title' => 'x'], 'exactly one page'];
        yield 'zero parent'     => [['parent' => 0, 'title' => 'x'], 'exactly one page'];
        yield 'negative parent' => [['parent' => -1, 'title' => 'x'], 'exactly one page'];
        yield 'no title'        => [['parent' => 1], '"title" is required'];
        yield 'empty title'     => [['parent' => 1, 'title' => ''], 'must not be empty'];
        yield 'blank title'     => [['parent' => 1, 'title' => '   '], 'must not be empty'];
        yield 'array title'     => [['parent' => 1, 'title' => ['x']], 'must be a string'];
        yield 'long title'      => [['parent' => 1, 'title' => str_repeat('a', 256)], 'exceeds 255 characters'];
        yield 'array nav title' => [$valid + ['nav_title' => ['x']], 'must be a string'];
        yield 'long nav title'  => [$valid + ['nav_title' => str_repeat('a', 256)], 'exceeds 255 characters'];
        yield 'unknown argument' => [$valid + ['subtitle' => 'x'], 'not an argument of this tool'];
        // The ones that would defeat the tool's guarantees.
        yield 'hidden smuggled in'  => [$valid + ['hidden' => 0], 'not an argument of this tool'];
        yield 'doktype smuggled in' => [$valid + ['doktype' => 4], 'not an argument of this tool'];
        yield 'pid smuggled in'     => [$valid + ['pid' => 5], 'not an argument of this tool'];
        yield 'slug smuggled in'    => [$valid + ['slug' => '/x'], 'not an argument of this tool'];
        yield 'language smuggled in' => [$valid + ['language' => 1], 'not an argument of this tool'];
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
    public function anUnknownArgumentNameIsEchoedBackStrippedOfAnythingButItsIdentifierCharacters(): void
    {
        $result = $this->tool->execute(
            ['parent' => 1, 'title' => 'x', "doktype\n<script>" => 1],
            $this->contextFor($this->liveUser()),
        );

        self::assertTrue($result->isError);
        self::assertStringNotContainsString('<', $result->content);
        self::assertStringContainsString('doktypescript', $result->content);
    }

    #[Test]
    public function itRefusesAUserWhoMayNotEditTheDefaultLanguage(): void
    {
        $restricted = $this->liveUser();
        // Not an admin, and only language 2 allowed — so the default language,
        // the only one this tool creates in, is out of reach.
        $restricted->user                           = ['uid' => 5, 'admin' => 0];
        $restricted->groupData['allowed_languages'] = '2';

        $result = $this->tool->execute(['parent' => 1, 'title' => 'x'], $this->contextFor($restricted));

        self::assertTrue($result->isError);
        self::assertStringContainsString('default language', $result->content);
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
