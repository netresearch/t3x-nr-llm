<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Service\Tool\Builtin\SetFileAlternativeTextTool;
use Netresearch\NrLlm\Service\Tool\FalStorageGate;
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
 * Argument validation of the second writing tool (ADR-135).
 *
 * Every assertion here stops the call BEFORE the database is touched, which is
 * why a stub {@see ConnectionPool} and a gate without a storage repository are
 * enough: an argument the tool refuses must never reach a query, let alone the
 * DataHandler. The write itself — the storage gate, the missing metadata record,
 * `errorLog`, the read-back — is exercised against a real database and real file
 * mounts in
 * {@see \Netresearch\NrLlm\Tests\Functional\Service\Tool\SetFileAlternativeTextToolFileMountTest}.
 */
#[CoversClass(SetFileAlternativeTextTool::class)]
final class SetFileAlternativeTextToolTest extends AbstractUnitTestCase
{
    private SetFileAlternativeTextTool $tool;

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

        // The narrowest TCA the tool reads: the column exists and declares no
        // length bound, exactly as core ships it.
        $GLOBALS['TCA'] = ['sys_file_metadata' => ['columns' => [
            'alternative' => ['config' => ['type' => 'input', 'size' => 30]],
            'title'       => ['exclude' => true, 'config' => ['type' => 'input']],
        ]]];
        $GLOBALS['LANG']    = self::createStub(LanguageService::class);
        $GLOBALS['BE_USER'] = $this->liveUser();

        $this->tool = new SetFileAlternativeTextTool(self::createStub(ConnectionPool::class), new FalStorageGate());
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
        self::assertInstanceOf(ToolEffectInterface::class, $this->tool);
        self::assertSame(ToolEffect::IDEMPOTENT_WRITE, $this->tool->getEffect());
        self::assertTrue($this->tool->getEffect()->isWrite());
    }

    #[Test]
    public function itShipsDisabledAndIsNotAdminOnly(): void
    {
        self::assertFalse($this->tool->isEnabledByDefault(), 'a writing tool is never on by default');
        self::assertFalse($this->tool->requiresAdmin(), 'an editor writes what their file mounts already grant them');
        // The writers' group, not `files` — the read-only FAL tools must not
        // carry write capability into a configuration that listed their group.
        self::assertSame('editing', $this->tool->getGroup());
    }

    #[Test]
    public function itPreviewsItsCallsForTheApprovalCard(): void
    {
        self::assertInstanceOf(ToolPreviewInterface::class, $this->tool);
    }

    #[Test]
    public function theSpecRequiresBothTheFileAndTheText(): void
    {
        $spec = $this->tool->getSpec();

        self::assertSame('set_file_alternative_text', $spec->name);
        self::assertSame(['uid', 'alternative'], $spec->parameters['required'] ?? null);

        $properties = $spec->parameters['properties'] ?? null;
        self::assertIsArray($properties);
        self::assertArrayHasKey('uid', $properties);
        self::assertArrayHasKey('alternative', $properties);
        // The tool sets one field. Anything else belongs to another tool.
        self::assertArrayNotHasKey('title', $properties);
        self::assertArrayNotHasKey('description', $properties);
        // No language argument: the tool addresses the default-language record
        // only, which is the record read_fal_asset_meta reads back (ADR-135).
        self::assertArrayNotHasKey('language', $properties);
        self::assertArrayNotHasKey('sys_language_uid', $properties);
    }

    #[Test]
    public function itFailsClosedWithoutAnActingBackendUser(): void
    {
        $result = $this->tool->execute(['uid' => 1, 'alternative' => 'x'], ToolExecutionContext::none());

        self::assertTrue($result->isError);
        self::assertSame('Asset not found or not permitted.', $result->content);
        // The preview must not be the softer surface of the two.
        self::assertSame(
            ['Asset not found or not permitted.'],
            $this->tool->previewCall(['uid' => 1, 'alternative' => 'x'], ToolExecutionContext::none()),
        );
    }

    #[Test]
    public function itRefusesOutsideTheLiveWorkspace(): void
    {
        $draftUser            = $this->liveUser();
        $draftUser->workspace = 1;

        $result = $this->tool->execute(['uid' => 1, 'alternative' => 'x'], $this->contextFor($draftUser));

        self::assertTrue($result->isError);
        self::assertStringContainsString('live workspace', $result->content);
    }

    #[Test]
    public function itRefusesAndNamesEachMissingPieceOfTheBackendEnvironment(): void
    {
        // The DataHandler declares $GLOBALS['TCA'] and $GLOBALS['LANG'] as
        // prerequisites and start() only sets its OWN $BE_USER — core's own
        // FileMetadataPermissionsAspect runs as a hook inside the write and
        // reads the ambient user. The tool refuses rather than mutating globals
        // it does not own (ADR-135).
        unset($GLOBALS['TCA'], $GLOBALS['LANG'], $GLOBALS['BE_USER']);

        $result = $this->tool->execute(['uid' => 1, 'alternative' => 'x'], $this->contextFor($this->liveUser()));

        self::assertTrue($result->isError);
        self::assertStringContainsString('TCA', $result->content);
        self::assertStringContainsString('language service', $result->content);
        self::assertStringContainsString('backend user', $result->content);
    }

    #[Test]
    public function itRefusesWhenOnlyTheLanguageServiceIsMissing(): void
    {
        unset($GLOBALS['LANG']);

        $result = $this->tool->execute(['uid' => 1, 'alternative' => 'x'], $this->contextFor($this->liveUser()));

        self::assertTrue($result->isError);
        self::assertStringContainsString('language service', $result->content);
        self::assertStringNotContainsString('TCA', $result->content);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusedArguments(): iterable
    {
        yield 'no uid'          => [['alternative' => 'x'], 'exactly one file'];
        yield 'zero uid'        => [['uid' => 0, 'alternative' => 'x'], 'exactly one file'];
        yield 'negative uid'    => [['uid' => -3, 'alternative' => 'x'], 'exactly one file'];
        yield 'non-numeric uid' => [['uid' => 'all', 'alternative' => 'x'], 'exactly one file'];
        yield 'no text at all'  => [['uid' => 1], '"alternative" is required'];
        yield 'null text'       => [['uid' => 1, 'alternative' => null], '"alternative" is required'];
        yield 'array value'     => [['uid' => 1, 'alternative' => ['a']], 'must be a string'];
        yield 'boolean value'   => [['uid' => 1, 'alternative' => true], 'must be a string'];
        // Another field of the same table is still another tool's business.
        yield 'foreign field title'       => [['uid' => 1, 'alternative' => 'x', 'title' => 'y'], 'not an argument of this tool'];
        yield 'foreign field description' => [['uid' => 1, 'alternative' => 'x', 'description' => 'y'], 'not an argument of this tool'];
        yield 'a language argument'       => [['uid' => 1, 'alternative' => 'x', 'sys_language_uid' => 1], 'not an argument of this tool'];
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
     * A refusal is a legitimate preview: the approver has to learn that
     * releasing this call would achieve nothing.
     *
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('refusedArguments')]
    public function thePreviewOfARefusableCallIsThatRefusal(array $arguments, string $expectedFragment): void
    {
        $lines = $this->tool->previewCall($arguments, $this->contextFor($this->liveUser()));

        self::assertCount(1, $lines);
        self::assertStringContainsString($expectedFragment, $lines[0]);
    }

    #[Test]
    public function anUnknownArgumentNameIsEchoedBackStrippedOfAnythingButItsIdentifierCharacters(): void
    {
        $result = $this->tool->execute(
            ['uid' => 1, 'alternative' => 'x', "title\n<script>" => 'y'],
            $this->contextFor($this->liveUser()),
        );

        self::assertTrue($result->isError);
        self::assertStringNotContainsString('<', $result->content);
        self::assertStringContainsString('titlescript', $result->content);
    }

    #[Test]
    public function anUnboundedColumnStillHasTheToolsOwnCeiling(): void
    {
        // Core ships `alternative` as a `text` column with no TCA `max`; an
        // untrusted, model-chosen argument must not become an arbitrary blob.
        $result = $this->tool->execute(
            ['uid' => 1, 'alternative' => str_repeat('a', 1001)],
            $this->contextFor($this->liveUser()),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('exceeds 1000 characters', $result->content);
    }

    #[Test]
    public function anInstallationsOwnTcaBoundWinsOverTheToolsCeiling(): void
    {
        $GLOBALS['TCA']['sys_file_metadata']['columns']['alternative']['config']['max'] = 120;

        $result = $this->tool->execute(
            ['uid' => 1, 'alternative' => str_repeat('a', 121)],
            $this->contextFor($this->liveUser()),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('exceeds 120 characters', $result->content);
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
