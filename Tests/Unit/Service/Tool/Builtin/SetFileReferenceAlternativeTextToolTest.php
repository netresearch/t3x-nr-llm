<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewTranslator;
use Netresearch\NrLlm\Service\Tool\Builtin\SetFileReferenceAlternativeTextTool;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * Declaration and argument validation of `set_file_reference_alternative_text`
 * (ADR-230).
 *
 * Every refusal here stops the call BEFORE the database is touched — a stub
 * {@see ConnectionPool} returns null from `getQueryBuilderForTable()`, so a
 * refusal that leaked through to a query would fail loudly. The write itself
 * is exercised in {@see \Netresearch\NrLlm\Tests\Functional\Service\Tool\SetFileReferenceAlternativeTextToolTest}.
 */
#[CoversClass(SetFileReferenceAlternativeTextTool::class)]
final class SetFileReferenceAlternativeTextToolTest extends AbstractUnitTestCase
{
    private SetFileReferenceAlternativeTextTool $tool;

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

        $GLOBALS['TCA']     = ['sys_file_reference' => ['columns' => ['alternative' => ['config' => ['type' => 'input']]]]];
        $GLOBALS['LANG']    = self::createStub(LanguageService::class);
        $GLOBALS['BE_USER'] = $this->liveUser();

        $this->tool = new SetFileReferenceAlternativeTextTool(
            self::createStub(ConnectionPool::class),
            new TableReadAccessService(),
            new ApprovalPreviewTranslator(self::createStub(LanguageServiceFactory::class)),
        );
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
    public function itDeclaresAnIdempotentWriteThatShipsDisabled(): void
    {
        self::assertSame(ToolEffect::IDEMPOTENT_WRITE, $this->tool->getEffect());
        self::assertFalse($this->tool->isEnabledByDefault());
        self::assertFalse($this->tool->requiresAdmin());
        self::assertSame('editing', $this->tool->getGroup());
        self::assertInstanceOf(ToolPreviewInterface::class, $this->tool);
    }

    #[Test]
    public function theSpecTakesTheReferenceAndTheText(): void
    {
        $spec = $this->tool->getSpec();

        self::assertSame('set_file_reference_alternative_text', $spec->name);
        self::assertSame(['reference', 'alternative'], $spec->parameters['required'] ?? null);
        self::assertStringContainsString('An empty text is refused', $spec->description);
    }

    #[Test]
    public function thePendingTargetIsTheReferenceAndItsOneField(): void
    {
        $target = $this->tool->pendingTarget(['reference' => 101, 'alternative' => 'x']);

        self::assertNotNull($target);
        self::assertSame('sys_file_reference', $target->record->table);
        self::assertSame(101, $target->record->uid);
        self::assertSame(['alternative'], $target->fields);
    }

    #[Test]
    public function itFailsClosedWithoutAnActingBackendUser(): void
    {
        $result = $this->tool->execute(['reference' => 1, 'alternative' => 'x'], ToolExecutionContext::none());

        self::assertTrue($result->isError);
        self::assertSame('File reference or its record not found, or not permitted.', $result->content);
        self::assertSame(['File reference or its record not found, or not permitted.'], $this->tool->previewCall(['reference' => 1, 'alternative' => 'x'], ToolExecutionContext::none()));
    }

    #[Test]
    public function itRefusesOutsideTheLiveWorkspace(): void
    {
        $draftUser            = $this->liveUser();
        $draftUser->workspace = 1;

        $result = $this->tool->execute(['reference' => 1, 'alternative' => 'x'], ToolExecutionContext::fromBackendUser($draftUser));

        self::assertTrue($result->isError);
        self::assertSame('Refused: this tool only edits the live workspace. Switch out of the draft workspace and retry.', $result->content);
    }

    #[Test]
    public function itRefusesWithoutABackendEnvironment(): void
    {
        unset($GLOBALS['TCA'], $GLOBALS['LANG'], $GLOBALS['BE_USER']);

        $result = $this->tool->execute(['reference' => 1, 'alternative' => 'x'], ToolExecutionContext::fromBackendUser($this->liveUser()));

        self::assertTrue($result->isError);
        self::assertStringContainsString('TCA', $result->content);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusedArguments(): iterable
    {
        yield 'no reference' => [['alternative' => 'x'], 'Refused: "reference" must be the positive sys_file_reference uid of exactly one reference.'];
        yield 'zero reference' => [['reference' => 0, 'alternative' => 'x'], 'Refused: "reference" must be the positive sys_file_reference uid of exactly one reference.'];
        yield 'no text' => [['reference' => 1], 'Refused: "alternative" is required — pass the new alternative text.'];
        yield 'text not a string' => [['reference' => 1, 'alternative' => ['x']], 'Refused: the value for "alternative" must be a string.'];
        yield 'empty text' => [['reference' => 1, 'alternative' => ''], 'Refused: "alternative" must not be empty. An empty alternative text looks like a forgotten one; marking an image as decorative is a separate decision this tool does not make.'];
        yield 'spaces only' => [['reference' => 1, 'alternative' => " \t "], 'Refused: "alternative" must not be empty. An empty alternative text looks like a forgotten one; marking an image as decorative is a separate decision this tool does not make.'];
        yield 'text too long' => [['reference' => 1, 'alternative' => str_repeat('a', 1001)], 'Refused: "alternative" is longer than 1000 characters.'];
        yield 'another field smuggled in' => [['reference' => 1, 'alternative' => 'x', 'title' => 'y'], 'Refused: "title" is not an argument of this tool. It sets the alternative text of one file reference; allowed: reference, alternative.'];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('refusedArguments')]
    public function itRefusesInvalidArguments(array $arguments, string $expected): void
    {
        $result = $this->tool->execute($arguments, ToolExecutionContext::fromBackendUser($this->liveUser()));

        self::assertTrue($result->isError);
        self::assertSame($expected, $result->content);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('refusedArguments')]
    public function thePreviewRefusesTheSameArguments(array $arguments, string $expected): void
    {
        self::assertSame([$expected], $this->tool->previewCall($arguments, ToolExecutionContext::fromBackendUser($this->liveUser())));
    }

    /**
     * The column's own `max` bounds the text where an installation declares
     * one.
     */
    #[Test]
    public function aMaxTheTcaDeclaresBoundsTheText(): void
    {
        $GLOBALS['TCA'] = ['sys_file_reference' => ['columns' => ['alternative' => ['config' => ['type' => 'input', 'max' => 10]]]]];

        $result = $this->tool->execute(['reference' => 1, 'alternative' => str_repeat('a', 11)], ToolExecutionContext::fromBackendUser($this->liveUser()));

        self::assertSame('Refused: "alternative" is longer than 10 characters.', $result->content);
    }

    private function liveUser(): BackendUserAuthentication
    {
        $user            = new BackendUserAuthentication();
        $user->user      = ['uid' => 1, 'admin' => 1];
        $user->workspace = 0;

        return $user;
    }
}
