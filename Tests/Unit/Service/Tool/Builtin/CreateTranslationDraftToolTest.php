<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Service\Tool\Builtin\CreateTranslationDraftTool;
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
 * Argument validation of the fifth writing tool (ADR-146).
 *
 * Every assertion here stops the call BEFORE the database is touched, so a stub
 * {@see ConnectionPool} is enough. The localisation itself — core's `localize`
 * command, the hidden pass, the destructive `overwrite` path and the read-back
 * — is exercised against a real database in
 * {@see \Netresearch\NrLlm\Tests\Functional\Service\Tool\CreateTranslationDraftToolTest}.
 */
#[CoversClass(CreateTranslationDraftTool::class)]
final class CreateTranslationDraftToolTest extends AbstractUnitTestCase
{
    private CreateTranslationDraftTool $tool;

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

        $GLOBALS['TCA'] = [
            'pages' => [
                'ctrl' => [
                    'label'                 => 'title',
                    'languageField'         => 'sys_language_uid',
                    'transOrigPointerField' => 'l10n_parent',
                    'enablecolumns'         => ['disabled' => 'hidden'],
                ],
                'columns' => ['title' => ['config' => ['type' => 'input']]],
            ],
            'tt_content' => [
                'ctrl' => [
                    'label'                 => 'header',
                    'languageField'         => 'sys_language_uid',
                    'transOrigPointerField' => 'l18n_parent',
                    'enablecolumns'         => ['disabled' => 'hidden'],
                ],
                'columns' => ['header' => ['config' => ['type' => 'input']]],
            ],
        ];
        $GLOBALS['LANG']    = self::createStub(LanguageService::class);
        $GLOBALS['BE_USER'] = $this->liveUser();

        $this->tool = new CreateTranslationDraftTool(self::createStub(ConnectionPool::class));
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
        // Not repeatable in either direction: without `overwrite` a second run
        // refuses (so a reaped run would report failure for a write that
        // happened), with it a second run discards work a human may have done
        // in between.
        self::assertInstanceOf(ToolEffectInterface::class, $this->tool);
        self::assertSame(ToolEffect::NON_IDEMPOTENT_WRITE, $this->tool->getEffect());
        self::assertTrue($this->tool->getEffect()->isWrite());
        self::assertFalse($this->tool->getEffect()->isSafeToRetry());
    }

    #[Test]
    public function itShipsDisabledAndIsNotAdminOnly(): void
    {
        self::assertFalse($this->tool->isEnabledByDefault(), 'a writing tool is never on by default');
        self::assertFalse($this->tool->requiresAdmin(), 'an editor translates what the backend already grants them');
        self::assertSame('editing', $this->tool->getGroup());
    }

    #[Test]
    public function itOffersAPreviewSoTheApprovalCardCanNameWhatIsDiscarded(): void
    {
        self::assertInstanceOf(ToolPreviewInterface::class, $this->tool);
    }

    #[Test]
    public function theSpecOffersNoWayToPublish(): void
    {
        $spec = $this->tool->getSpec();

        self::assertSame('create_translation_draft', $spec->name);
        self::assertSame(['table', 'uid', 'language'], $spec->parameters['required'] ?? null);

        $properties = $spec->parameters['properties'] ?? null;
        self::assertIsArray($properties);
        self::assertArrayHasKey('overwrite', $properties);
        self::assertArrayNotHasKey('hidden', $properties);
        self::assertArrayNotHasKey('publish', $properties);

        // The destructive argument must say so where a model reads it, not only
        // where a maintainer does.
        $overwrite = $properties['overwrite']['description'] ?? '';
        self::assertIsString($overwrite);
        self::assertStringContainsString('Destructive', $overwrite);
    }

    #[Test]
    public function itFailsClosedWithoutAnActingBackendUser(): void
    {
        $result = $this->tool->execute(
            ['table' => 'pages', 'uid' => 1, 'language' => 2],
            ToolExecutionContext::none(),
        );

        self::assertTrue($result->isError);
        self::assertSame('Record not found or not permitted.', $result->content);
    }

    #[Test]
    public function itRefusesOutsideTheLiveWorkspace(): void
    {
        $draftUser            = $this->liveUser();
        $draftUser->workspace = 1;

        $result = $this->tool->execute(
            ['table' => 'pages', 'uid' => 1, 'language' => 2],
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
            ['table' => 'pages', 'uid' => 1, 'language' => 2],
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
        yield 'no table'      => [['uid' => 1, 'language' => 2], 'not a table this tool translates'];
        yield 'foreign table' => [
            ['table' => 'be_users', 'uid' => 1, 'language' => 2],
            'not a table this tool translates',
        ];
        yield 'sys_file_metadata is not one either' => [
            ['table' => 'sys_file_metadata', 'uid' => 1, 'language' => 2],
            'not a table this tool translates',
        ];
        yield 'no uid'        => [['table' => 'pages', 'language' => 2], 'exactly one record'];
        yield 'zero uid'      => [['table' => 'pages', 'uid' => 0, 'language' => 2], 'exactly one record'];
        yield 'negative uid'  => [['table' => 'pages', 'uid' => -1, 'language' => 2], 'exactly one record'];
        yield 'no language'   => [['table' => 'pages', 'uid' => 1], 'positive sys_language_uid'];
        // The default language is the SOURCE of a translation, never a target.
        yield 'default language as target' => [
            ['table' => 'pages', 'uid' => 1, 'language' => 0],
            'positive sys_language_uid',
        ];
        yield 'negative language' => [['table' => 'pages', 'uid' => 1, 'language' => -1], 'positive sys_language_uid'];
        yield 'overwrite is not a string' => [
            ['table' => 'pages', 'uid' => 1, 'language' => 2, 'overwrite' => 'yes'],
            'must be true or false',
        ];
        yield 'overwrite is not a number' => [
            ['table' => 'pages', 'uid' => 1, 'language' => 2, 'overwrite' => 1],
            'must be true or false',
        ];
        yield 'unknown argument' => [
            ['table' => 'pages', 'uid' => 1, 'language' => 2, 'hidden' => 0],
            'not an argument of this tool',
        ];
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
    public function aTableNameIsEchoedBackStrippedOfAnythingButItsIdentifierCharacters(): void
    {
        $result = $this->tool->execute(
            ['table' => "pages;DROP\n<script>", 'uid' => 1, 'language' => 2],
            $this->contextFor($this->liveUser()),
        );

        self::assertTrue($result->isError);
        self::assertStringNotContainsString('<', $result->content);
        self::assertStringNotContainsString(';', $result->content);
    }

    #[Test]
    public function itRefusesALanguageTheActingUserMayNotEdit(): void
    {
        $restricted                                 = $this->liveUser();
        $restricted->user                           = ['uid' => 5, 'admin' => 0];
        $restricted->groupData['allowed_languages'] = '1';

        $result = $this->tool->execute(
            ['table' => 'pages', 'uid' => 1, 'language' => 2],
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
