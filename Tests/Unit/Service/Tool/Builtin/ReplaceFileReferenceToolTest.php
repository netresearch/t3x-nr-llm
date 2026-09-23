<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Service\Tool\Builtin\ReplaceFileReferenceTool;
use Netresearch\NrLlm\Service\Tool\FalStorageGate;
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
 * Argument validation of `replace_file_reference` (ADR-198).
 *
 * Every assertion stops the call BEFORE the database is touched — a stub
 * {@see ConnectionPool} returns null from `getQueryBuilderForTable()`, so a
 * refusal that leaked through to a query would fail loudly. The write itself
 * is exercised in {@see \Netresearch\NrLlm\Tests\Functional\Service\Tool\ReplaceFileReferenceToolTest}.
 */
#[CoversClass(ReplaceFileReferenceTool::class)]
final class ReplaceFileReferenceToolTest extends AbstractUnitTestCase
{
    private ReplaceFileReferenceTool $tool;

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

        $GLOBALS['TCA'] = ['sys_file_reference' => ['columns' => ['title' => ['config' => ['type' => 'input']]]]];
        $GLOBALS['LANG']    = self::createStub(LanguageService::class);
        $GLOBALS['BE_USER'] = $this->liveUser();

        $this->tool = new ReplaceFileReferenceTool(self::createStub(ConnectionPool::class), new FalStorageGate());
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
    public function itDeclaresANonIdempotentWriteAndShipsDisabled(): void
    {
        self::assertSame(ToolEffect::NON_IDEMPOTENT_WRITE, $this->tool->getEffect());
        self::assertFalse($this->tool->isEnabledByDefault());
        self::assertFalse($this->tool->requiresAdmin());
        self::assertSame('editing', $this->tool->getGroup());
        self::assertInstanceOf(ToolPreviewInterface::class, $this->tool);
    }

    #[Test]
    public function theSpecNamesBothActions(): void
    {
        $spec = $this->tool->getSpec();

        self::assertSame('replace_file_reference', $spec->name);
        self::assertSame(['reference', 'action'], $spec->parameters['required'] ?? null);

        $properties = $spec->parameters['properties'] ?? null;
        self::assertIsArray($properties);
        $action = $properties['action'] ?? null;
        self::assertIsArray($action);
        self::assertSame(['replace', 'remove'], $action['enum'] ?? null);
    }

    #[Test]
    public function itFailsClosedWithoutAnActingBackendUser(): void
    {
        $result = $this->tool->execute(['reference' => 1, 'action' => 'remove'], ToolExecutionContext::none());

        self::assertTrue($result->isError);
        self::assertSame('File reference, content element or file not found, or not permitted.', $result->content);
    }

    #[Test]
    public function itRefusesOutsideTheLiveWorkspace(): void
    {
        $draftUser            = $this->liveUser();
        $draftUser->workspace = 1;

        $result = $this->tool->execute(['reference' => 1, 'action' => 'remove'], ToolExecutionContext::fromBackendUser($draftUser));

        self::assertTrue($result->isError);
        self::assertStringContainsString('live workspace', $result->content);
    }

    #[Test]
    public function itRefusesWithoutABackendEnvironment(): void
    {
        unset($GLOBALS['TCA'], $GLOBALS['LANG'], $GLOBALS['BE_USER']);

        $result = $this->tool->execute(['reference' => 1, 'action' => 'remove'], ToolExecutionContext::fromBackendUser($this->liveUser()));

        self::assertTrue($result->isError);
        self::assertStringContainsString('TCA', $result->content);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusedArguments(): iterable
    {
        yield 'no reference'       => [['action' => 'remove'], 'exactly one reference'];
        yield 'zero reference'     => [['reference' => 0, 'action' => 'remove'], 'exactly one reference'];
        yield 'no action'          => [['reference' => 1], '"action" must be "replace" or "remove"'];
        yield 'unknown action'     => [['reference' => 1, 'action' => 'delete'], '"action" must be "replace" or "remove"'];
        yield 'replace, no file'   => [['reference' => 1, 'action' => 'replace'], '"replace" needs "file"'];
        yield 'remove with file'   => [['reference' => 1, 'action' => 'remove', 'file' => 2], '"file" belongs to "replace"'];
        yield 'remove with text'   => [['reference' => 1, 'action' => 'remove', 'title' => 'x'], '"title" belongs to "replace"'];
        yield 'text too long'      => [['reference' => 1, 'action' => 'replace', 'file' => 2, 'alternative' => str_repeat('a', 1001)], 'longer than 1000'];
        yield 'text not a string'  => [['reference' => 1, 'action' => 'replace', 'file' => 2, 'title' => ['x']], 'must be a string'];
        // A crop describes the old file; it is never an argument.
        yield 'crop smuggled in'   => [['reference' => 1, 'action' => 'replace', 'file' => 2, 'crop' => '{}'], 'not an argument of this tool'];
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
