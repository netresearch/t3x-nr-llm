<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Service\Tool\Builtin\AttachFileToRecordTool;
use Netresearch\NrLlm\Service\Tool\EditorActionInterface;
use Netresearch\NrLlm\Service\Tool\FalStorageGate;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Declarations and argument validation of the generic file attacher
 * (ADR-212). Every refusal here stops the call BEFORE the database is touched,
 * so a stub {@see ConnectionPool} is enough; the write itself is exercised
 * against a real database in
 * {@see \Netresearch\NrLlm\Tests\Functional\Service\Tool\AttachFileToRecordToolTest}.
 */
#[CoversClass(AttachFileToRecordTool::class)]
final class AttachFileToRecordToolTest extends AbstractUnitTestCase
{
    private AttachFileToRecordTool $tool;

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
            'tx_news_domain_model_news' => [
                'ctrl'    => ['title' => 'News'],
                'columns' => ['fal_media' => ['exclude' => true, 'config' => ['type' => 'file']]],
            ],
            'tx_locked_thing' => [
                'ctrl'    => ['title' => 'Locked', 'readOnly' => true],
                'columns' => ['pictures' => ['config' => ['type' => 'file']]],
            ],
            'pages'      => ['ctrl' => ['title' => 'Pages'], 'columns' => ['media' => ['config' => ['type' => 'file']]]],
            'tt_content' => ['ctrl' => ['title' => 'Content'], 'columns' => ['image' => ['config' => ['type' => 'file']]]],
            'sys_file_metadata' => ['ctrl' => ['title' => 'Metadata'], 'columns' => []],
            // The write guard proves a loaded TCA through the reference table.
            'sys_file_reference' => ['ctrl' => ['title' => 'Reference'], 'columns' => []],
        ];
        $GLOBALS['LANG']    = self::createStub(LanguageService::class);
        $GLOBALS['BE_USER'] = $this->liveUser();

        // The gate is final and never reached here: every case refuses before
        // a file is resolved.
        $this->tool = new AttachFileToRecordTool(
            self::createStub(ConnectionPool::class),
            new FalStorageGate(),
            new TableReadAccessService(),
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
    public function itDeclaresANonIdempotentWriteDisabledByDefaultInTheEditingGroup(): void
    {
        self::assertSame(ToolEffect::NON_IDEMPOTENT_WRITE, $this->tool->getEffect());
        self::assertFalse($this->tool->isEnabledByDefault(), 'a writing tool is never on by default');
        self::assertFalse($this->tool->requiresAdmin());
        self::assertSame('editing', $this->tool->getGroup());
    }

    /**
     * An editor action is offered on a record of one table (ADR-152); this tool
     * has no such subject and is reached through the assistant only.
     */
    #[Test]
    public function itDeclaresNoEditorAction(): void
    {
        self::assertNotInstanceOf(EditorActionInterface::class, $this->tool);
    }

    #[Test]
    public function theSpecNamesTheTableTheRecordTheFileAndTheTextsAndNothingElse(): void
    {
        $spec = $this->tool->getSpec();

        self::assertSame('attach_file_to_record', $spec->name);
        self::assertSame(['table', 'record', 'file'], $spec->parameters['required'] ?? null);

        $properties = $spec->parameters['properties'] ?? null;
        self::assertIsArray($properties);
        self::assertSame(['table', 'record', 'file', 'field', 'title', 'alternative', 'description'], array_keys($properties));
        foreach (['hidden', 'copyright', 'language', 'sys_language_uid', 'uid_local'] as $notAnArgument) {
            self::assertArrayNotHasKey($notAnArgument, $properties);
        }

        // Where the neighbouring concerns live is stated to the model.
        self::assertStringContainsString('set_page_social_image', $spec->description);
        self::assertStringContainsString('attach_file_to_content_element', $spec->description);
        self::assertStringContainsString('update_fal_asset_meta', $spec->description);
    }

    #[Test]
    public function itFailsClosedWithoutAnActingBackendUser(): void
    {
        $result = $this->tool->execute($this->valid(), ToolExecutionContext::none());

        self::assertTrue($result->isError);
        self::assertSame('Record or file not found, or not permitted.', $result->content);
    }

    #[Test]
    public function itRefusesOutsideTheLiveWorkspace(): void
    {
        $user            = $this->liveUser();
        $user->workspace = 1;

        $result = $this->tool->execute($this->valid(), ToolExecutionContext::fromBackendUser($user));

        self::assertTrue($result->isError);
        self::assertStringContainsString('live workspace', $result->content);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusedArguments(): iterable
    {
        $valid = ['table' => 'tx_news_domain_model_news', 'record' => 5, 'file' => 7, 'field' => 'fal_media'];

        yield 'no table'            => [['record' => 5, 'file' => 7], '"table" must be the name of one TCA table'];
        yield 'a table with a dash' => [['table' => 'tx-news'] + $valid, '"table" must be the name of one TCA table'];
        yield 'an array table'      => [['table' => ['x']] + $valid, '"table" must be the name of one TCA table'];
        yield 'an undeclared table' => [['table' => 'tx_nothing_here'] + $valid, 'not a table this installation declares'];
        yield 'pages'               => [['table' => 'pages'] + $valid, 'use set_page_social_image'];
        yield 'tt_content'          => [['table' => 'tt_content'] + $valid, 'use attach_file_to_content_element'];
        yield 'a system table'      => [['table' => 'sys_file_metadata'] + $valid, 'system or sensitive'];
        yield 'a read-only table'   => [['table' => 'tx_locked_thing'] + $valid, 'declared readOnly'];
        yield 'no record'           => [['record' => 0] + $valid, '"record" must be the positive uid'];
        yield 'a negative record'   => [['record' => -3] + $valid, '"record" must be the positive uid'];
        yield 'no file'             => [['file' => 0] + $valid, '"file" must be the positive sys_file uid'];
        yield 'a long title'        => [$valid + ['title' => str_repeat('a', 1001)], '"title" is longer than 1000 characters'];
        yield 'a long alternative'  => [$valid + ['alternative' => str_repeat('a', 1001)], '"alternative" is longer than 1000 characters'];
        yield 'hidden smuggled in'  => [$valid + ['hidden' => 0], '"hidden" is not an argument of this tool'];
        yield 'copyright'           => [$valid + ['copyright' => 'x'], '"copyright" is not an argument of this tool'];
        yield 'a file uid injection' => [$valid + ['uid_local' => 9], '"uid_local" is not an argument of this tool'];
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

    /**
     * @return array<string, mixed>
     */
    private function valid(): array
    {
        return ['table' => 'tx_news_domain_model_news', 'record' => 5, 'file' => 7, 'field' => 'fal_media'];
    }

    private function liveUser(): BackendUserAuthentication
    {
        $user            = new BackendUserAuthentication();
        $user->user      = ['uid' => 1, 'admin' => 1];
        $user->workspace = 0;

        return $user;
    }
}
