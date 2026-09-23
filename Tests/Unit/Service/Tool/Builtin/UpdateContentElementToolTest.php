<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Service\Tool\Builtin\UpdateContentElementTool;
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
 * Argument validation of `update_content_element` (ADR-198).
 *
 * Every assertion stops the call BEFORE the database is touched — a stub
 * {@see ConnectionPool} returns null from `getQueryBuilderForTable()`, so a
 * refusal that leaked through to a query would fail loudly. The write itself
 * is exercised in {@see \Netresearch\NrLlm\Tests\Functional\Service\Tool\UpdateContentElementToolTest}.
 */
#[CoversClass(UpdateContentElementTool::class)]
final class UpdateContentElementToolTest extends AbstractUnitTestCase
{
    private UpdateContentElementTool $tool;

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

        $GLOBALS['TCA'] = ['tt_content' => ['columns' => ['header' => ['config' => ['type' => 'input']]]]];
        $GLOBALS['LANG']    = self::createStub(LanguageService::class);
        $GLOBALS['BE_USER'] = $this->liveUser();

        $this->tool = new UpdateContentElementTool(self::createStub(ConnectionPool::class));
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
    public function theSpecRequiresTheElementAndItsFields(): void
    {
        $spec = $this->tool->getSpec();

        self::assertSame('update_content_element', $spec->name);
        self::assertSame(['uid', 'fields'], $spec->parameters['required'] ?? null);
    }

    #[Test]
    public function itFailsClosedWithoutAnActingBackendUser(): void
    {
        $result = $this->tool->execute(['uid' => 1, 'fields' => ['header' => 'x']], ToolExecutionContext::none());

        self::assertTrue($result->isError);
        self::assertSame('Content element not found or not permitted.', $result->content);
    }

    #[Test]
    public function itRefusesOutsideTheLiveWorkspace(): void
    {
        $draftUser            = $this->liveUser();
        $draftUser->workspace = 1;

        $result = $this->tool->execute(['uid' => 1, 'fields' => ['header' => 'x']], ToolExecutionContext::fromBackendUser($draftUser));

        self::assertTrue($result->isError);
        self::assertStringContainsString('live workspace', $result->content);
    }

    #[Test]
    public function itRefusesWithoutABackendEnvironment(): void
    {
        unset($GLOBALS['TCA'], $GLOBALS['LANG'], $GLOBALS['BE_USER']);

        $result = $this->tool->execute(['uid' => 1, 'fields' => ['header' => 'x']], ToolExecutionContext::fromBackendUser($this->liveUser()));

        self::assertTrue($result->isError);
        self::assertStringContainsString('TCA', $result->content);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusedArguments(): iterable
    {
        yield 'no uid'            => [['fields' => ['header' => 'x']], 'exactly one content element'];
        yield 'zero uid'          => [['uid' => 0, 'fields' => ['header' => 'x']], 'exactly one content element'];
        yield 'no fields'         => [['uid' => 1], 'at least one column'];
        yield 'empty fields'      => [['uid' => 1, 'fields' => []], 'at least one column'];
        yield 'fields as a list'  => [['uid' => 1, 'fields' => ['x']], 'at least one column'];
        yield 'fields as a string' => [['uid' => 1, 'fields' => 'header=x'], 'at least one column'];
        // A column outside "fields" is an unknown argument, not a shortcut.
        yield 'column at the top' => [['uid' => 1, 'header' => 'x'], 'not an argument of this tool'];
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
