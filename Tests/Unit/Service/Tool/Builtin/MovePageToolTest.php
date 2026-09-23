<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Service\Tool\Builtin\MovePageTool;
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
 * Argument validation of `move_page` (ADR-198).
 *
 * Every assertion stops the call BEFORE the database is touched — a stub
 * {@see ConnectionPool} returns null from `getQueryBuilderForTable()`, so a
 * refusal that leaked through to a query would fail loudly. The write itself
 * is exercised in {@see \Netresearch\NrLlm\Tests\Functional\Service\Tool\MovePageToolTest}.
 */
#[CoversClass(MovePageTool::class)]
final class MovePageToolTest extends AbstractUnitTestCase
{
    private MovePageTool $tool;

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

        $GLOBALS['TCA'] = ['pages' => ['columns' => ['title' => ['config' => ['type' => 'input']]]]];
        $GLOBALS['LANG']    = self::createStub(LanguageService::class);
        $GLOBALS['BE_USER'] = $this->liveUser();

        $this->tool = new MovePageTool(self::createStub(ConnectionPool::class));
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
    public function itDeclaresAnIdempotentWriteAndShipsDisabled(): void
    {
        self::assertSame(ToolEffect::IDEMPOTENT_WRITE, $this->tool->getEffect());
        self::assertFalse($this->tool->isEnabledByDefault());
        self::assertFalse($this->tool->requiresAdmin());
        self::assertSame('editing', $this->tool->getGroup());
        self::assertInstanceOf(ToolPreviewInterface::class, $this->tool);
    }

    #[Test]
    public function theSpecRequiresOnlyThePage(): void
    {
        $spec = $this->tool->getSpec();

        self::assertSame('move_page', $spec->name);
        self::assertSame(['uid'], $spec->parameters['required'] ?? null);

        $properties = $spec->parameters['properties'] ?? null;
        self::assertIsArray($properties);
        self::assertArrayHasKey('parent', $properties);
        self::assertArrayHasKey('after_page_uid', $properties);
        // A move does not change what the page IS.
        self::assertArrayNotHasKey('title', $properties);
    }

    #[Test]
    public function itFailsClosedWithoutAnActingBackendUser(): void
    {
        $result = $this->tool->execute(['uid' => 1, 'parent' => 2], ToolExecutionContext::none());

        self::assertTrue($result->isError);
        self::assertSame('Page not found or not permitted.', $result->content);
    }

    #[Test]
    public function itRefusesOutsideTheLiveWorkspace(): void
    {
        $draftUser            = $this->liveUser();
        $draftUser->workspace = 1;

        $result = $this->tool->execute(['uid' => 1, 'parent' => 2], ToolExecutionContext::fromBackendUser($draftUser));

        self::assertTrue($result->isError);
        self::assertStringContainsString('live workspace', $result->content);
    }

    #[Test]
    public function itRefusesWithoutABackendEnvironment(): void
    {
        unset($GLOBALS['TCA'], $GLOBALS['LANG'], $GLOBALS['BE_USER']);

        $result = $this->tool->execute(['uid' => 1, 'parent' => 2], ToolExecutionContext::fromBackendUser($this->liveUser()));

        self::assertTrue($result->isError);
        self::assertStringContainsString('TCA', $result->content);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusedArguments(): iterable
    {
        yield 'no uid'              => [['parent' => 2], 'exactly one page'];
        yield 'zero uid'            => [['uid' => 0, 'parent' => 2], 'exactly one page'];
        yield 'no destination'      => [['uid' => 3], 'give "parent", "after_page_uid", or both'];
        yield 'root level'          => [['uid' => 3, 'parent' => 0], 'root level'];
        yield 'negative anchor'     => [['uid' => 3, 'after_page_uid' => -1], 'root level'];
        yield 'own parent'          => [['uid' => 3, 'parent' => 3], 'its own parent'];
        yield 'own anchor'          => [['uid' => 3, 'after_page_uid' => 3], 'its own anchor'];
        yield 'field smuggled in'   => [['uid' => 3, 'parent' => 2, 'title' => 'Renamed'], 'not an argument of this tool'];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('refusedArguments')]
    public function itRefusesInvalidArguments(array $arguments, string $expectedFragment): void
    {
        $result = $this->tool->execute($arguments, ToolExecutionContext::fromBackendUser($this->liveUser()));

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
        $lines = $this->tool->previewCall($arguments, ToolExecutionContext::fromBackendUser($this->liveUser()));

        self::assertCount(1, $lines);
        self::assertStringContainsString($expectedFragment, $lines[0]);
    }

    private function liveUser(): BackendUserAuthentication
    {
        $user            = new BackendUserAuthentication();
        $user->user      = ['uid' => 1, 'admin' => 1];
        $user->workspace = 0;

        return $user;
    }
}
