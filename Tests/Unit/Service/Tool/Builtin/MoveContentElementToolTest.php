<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Service\Tool\Builtin\MoveContentElementTool;
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
 * Argument validation of the third writing tool (ADR-146).
 *
 * Every assertion here stops the call BEFORE the database is touched, which is
 * why a stub {@see ConnectionPool} is enough — a stub returns null from
 * `getQueryBuilderForTable()`, so a refusal that leaked through to a query
 * would fail loudly rather than silently. The move itself — both pages'
 * permissions, the anchor, the read-back — is exercised against a real database
 * in {@see \Netresearch\NrLlm\Tests\Functional\Service\Tool\MoveContentElementToolTest}.
 */
#[CoversClass(MoveContentElementTool::class)]
final class MoveContentElementToolTest extends AbstractUnitTestCase
{
    private MoveContentElementTool $tool;

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

        // The narrowest TCA the tool reads: enough of `tt_content` to prove a
        // TCA is loaded at all, which is what the environment guard asks.
        $GLOBALS['TCA'] = ['tt_content' => ['columns' => [
            'header' => ['config' => ['type' => 'input']],
            'colPos' => ['config' => ['type' => 'number']],
        ]]];
        $GLOBALS['LANG']    = self::createStub(LanguageService::class);
        $GLOBALS['BE_USER'] = $this->liveUser();

        $this->tool = new MoveContentElementTool(self::createStub(ConnectionPool::class));
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
    public function itDeclaresAnIdempotentWriteEffect(): void
    {
        // Moving a record that keeps its uid converges: the second move puts it
        // in the same place. This is the one new writer that is repeatable.
        self::assertInstanceOf(ToolEffectInterface::class, $this->tool);
        self::assertSame(ToolEffect::IDEMPOTENT_WRITE, $this->tool->getEffect());
        self::assertTrue($this->tool->getEffect()->isWrite());
    }

    #[Test]
    public function itShipsDisabledAndIsNotAdminOnly(): void
    {
        self::assertFalse($this->tool->isEnabledByDefault(), 'a writing tool is never on by default');
        self::assertFalse($this->tool->requiresAdmin(), 'an editor moves what the backend already grants them');
        self::assertSame('editing', $this->tool->getGroup());
    }

    #[Test]
    public function itOffersAPreviewSoTheApprovalCardCanShowBothSides(): void
    {
        self::assertInstanceOf(ToolPreviewInterface::class, $this->tool);
    }

    #[Test]
    public function theSpecRequiresBothEndsOfTheMove(): void
    {
        $spec = $this->tool->getSpec();

        self::assertSame('move_content_element', $spec->name);
        self::assertSame(['uid', 'target_page'], $spec->parameters['required'] ?? null);

        $properties = $spec->parameters['properties'] ?? null;
        self::assertIsArray($properties);
        self::assertArrayHasKey('column', $properties);
        self::assertArrayHasKey('after_content_uid', $properties);
        // The move does not change what the element IS.
        self::assertArrayNotHasKey('header', $properties);
        self::assertArrayNotHasKey('language', $properties);
    }

    #[Test]
    public function itFailsClosedWithoutAnActingBackendUser(): void
    {
        $result = $this->tool->execute(['uid' => 1, 'target_page' => 2], ToolExecutionContext::none());

        self::assertTrue($result->isError);
        self::assertSame('Content element not found or not permitted.', $result->content);
    }

    #[Test]
    public function itRefusesOutsideTheLiveWorkspace(): void
    {
        $draftUser            = $this->liveUser();
        $draftUser->workspace = 1;

        $result = $this->tool->execute(['uid' => 1, 'target_page' => 2], $this->contextFor($draftUser));

        self::assertTrue($result->isError);
        self::assertStringContainsString('live workspace', $result->content);
    }

    #[Test]
    public function itRefusesAndNamesEachMissingPieceOfTheBackendEnvironment(): void
    {
        unset($GLOBALS['TCA'], $GLOBALS['LANG'], $GLOBALS['BE_USER']);

        $result = $this->tool->execute(['uid' => 1, 'target_page' => 2], $this->contextFor($this->liveUser()));

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
        yield 'no uid'              => [['target_page' => 2], 'exactly one content element'];
        yield 'zero uid'            => [['uid' => 0, 'target_page' => 2], 'exactly one content element'];
        yield 'negative uid'        => [['uid' => -3, 'target_page' => 2], 'exactly one content element'];
        yield 'non-numeric uid'     => [['uid' => 'all', 'target_page' => 2], 'exactly one content element'];
        yield 'no target page'      => [['uid' => 1], 'exactly one page'];
        yield 'zero target page'    => [['uid' => 1, 'target_page' => 0], 'exactly one page'];
        yield 'negative target page' => [['uid' => 1, 'target_page' => -2], 'exactly one page'];
        yield 'negative column'     => [['uid' => 1, 'target_page' => 2, 'column' => -1], 'zero or a positive'];
        yield 'unknown argument'    => [['uid' => 1, 'target_page' => 2, 'hidden' => 1], 'not an argument of this tool'];
        // The one that would be a silent data loss: a model asking to move an
        // element AND rename it must hear "no" rather than get half of it.
        yield 'field write smuggled in' => [
            ['uid' => 1, 'target_page' => 2, 'header' => 'Renamed'],
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
     * The preview refuses on the same arguments as the write, so an approval
     * card can never show a move the run could not have performed.
     *
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
            ['uid' => 1, 'target_page' => 2, "colPos\n<script>" => 3],
            $this->contextFor($this->liveUser()),
        );

        self::assertTrue($result->isError);
        self::assertStringNotContainsString('<', $result->content);
        self::assertStringContainsString('colPosscript', $result->content);
    }

    #[Test]
    public function thePreviewWithoutAnActingUserRefusesNeutrally(): void
    {
        self::assertSame(
            ['Content element not found or not permitted.'],
            $this->tool->previewCall(['uid' => 1, 'target_page' => 2], ToolExecutionContext::none()),
        );
    }

    /**
     * A backend user in the live workspace, admin, with no session — enough for
     * every path that stops before the database.
     */
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
