<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Service\Tool\Builtin\UpdateFalAssetMetaTool;
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
 * Argument validation of the eighth writing tool.
 *
 * Every assertion here stops the call BEFORE the database is touched, which is
 * why a stub {@see ConnectionPool} and a gate without a storage repository are
 * enough: an argument the tool refuses must never reach a query, let alone the
 * DataHandler. The write itself — the storage gate, the missing metadata
 * record, `errorLog`, the field-level grant and the read-back — is exercised
 * against a real database and real file mounts in
 * {@see \Netresearch\NrLlm\Tests\Functional\Service\Tool\UpdateFalAssetMetaToolFileMountTest}.
 */
#[CoversClass(UpdateFalAssetMetaTool::class)]
final class UpdateFalAssetMetaToolTest extends AbstractUnitTestCase
{
    private UpdateFalAssetMetaTool $tool;

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

        // The narrowest TCA the tool reads, and it is core's shape rather than a
        // convenient one: `title` carries the `exclude` flag and `description`
        // does not, which is the asymmetry the grant check exists for. Neither
        // declares a length bound, exactly as core ships them.
        $GLOBALS['TCA'] = ['sys_file_metadata' => ['columns' => [
            'title'       => ['exclude' => true, 'config' => ['type' => 'input']],
            'description' => ['config' => ['type' => 'text']],
            'alternative' => ['config' => ['type' => 'input']],
        ]]];
        $GLOBALS['LANG']    = self::createStub(LanguageService::class);
        $GLOBALS['BE_USER'] = $this->liveUser();

        $this->tool = new UpdateFalAssetMetaTool(self::createStub(ConnectionPool::class), new FalStorageGate());
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
        self::assertSame('editing', $this->tool->getGroup());
    }

    #[Test]
    public function itPreviewsItsCallsForTheApprovalCard(): void
    {
        self::assertInstanceOf(ToolPreviewInterface::class, $this->tool);
    }

    /**
     * The two writable fields are optional and the uid is not: a call may set
     * either or both, and one that names neither is refused rather than
     * silently doing nothing.
     */
    #[Test]
    public function theSpecRequiresOnlyTheFileAndOffersBothFields(): void
    {
        $spec = $this->tool->getSpec();

        self::assertSame('update_fal_asset_meta', $spec->name);
        self::assertSame(['uid'], $spec->parameters['required'] ?? null);

        $properties = $spec->parameters['properties'] ?? null;
        self::assertIsArray($properties);
        self::assertArrayHasKey('uid', $properties);
        self::assertArrayHasKey('title', $properties);
        self::assertArrayHasKey('description', $properties);
        // The disjointness that keeps one field from having two writers: the
        // alternative text belongs to set_file_alternative_text and to nothing
        // else, so an approver never has to work out which of two cards won.
        self::assertArrayNotHasKey('alternative', $properties);
        // No language argument: the tool addresses the default-language record
        // only, which is the record the preview reads back (ADR-135).
        self::assertArrayNotHasKey('language', $properties);
        self::assertArrayNotHasKey('sys_language_uid', $properties);
    }

    /**
     * The description points at the tool that does write the alternative text.
     * A model told only "not this one" picks something else at random.
     */
    #[Test]
    public function theSpecSendsTheAlternativeTextToItsOwnTool(): void
    {
        self::assertStringContainsString('set_file_alternative_text', $this->tool->getSpec()->description);
    }

    #[Test]
    public function itFailsClosedWithoutAnActingBackendUser(): void
    {
        $arguments = ['uid' => 1, 'title' => 'x'];

        $result = $this->tool->execute($arguments, ToolExecutionContext::none());

        self::assertTrue($result->isError);
        self::assertSame('Asset not found or not permitted.', $result->content);
        // The preview must not be the softer surface of the two.
        self::assertSame(
            ['Asset not found or not permitted.'],
            $this->tool->previewCall($arguments, ToolExecutionContext::none()),
        );
    }

    #[Test]
    public function itRefusesOutsideTheLiveWorkspace(): void
    {
        $draftUser            = $this->liveUser();
        $draftUser->workspace = 1;

        $result = $this->tool->execute(['uid' => 1, 'title' => 'x'], $this->contextFor($draftUser));

        self::assertTrue($result->isError);
        self::assertStringContainsString('live workspace', $result->content);
    }

    #[Test]
    public function itRefusesAndNamesEachMissingPieceOfTheBackendEnvironment(): void
    {
        unset($GLOBALS['TCA'], $GLOBALS['LANG'], $GLOBALS['BE_USER']);

        $result = $this->tool->execute(['uid' => 1, 'title' => 'x'], $this->contextFor($this->liveUser()));

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
        yield 'no uid'          => [['title' => 'x'], 'exactly one file'];
        yield 'zero uid'        => [['uid' => 0, 'title' => 'x'], 'exactly one file'];
        yield 'negative uid'    => [['uid' => -3, 'title' => 'x'], 'exactly one file'];
        yield 'non-numeric uid' => [['uid' => 'all', 'title' => 'x'], 'exactly one file'];

        // Neither field: the call would change nothing and still cost a human an
        // approval, so it is refused rather than run.
        yield 'no field at all' => [['uid' => 1], 'at least one of'];

        // null is not the same as omitted and not the same as empty. Guessing
        // which one was meant is how a metadata writer erases work.
        yield 'null title'       => [['uid' => 1, 'title' => null], 'passed as null'];
        yield 'null description' => [['uid' => 1, 'description' => null], 'passed as null'];

        yield 'array title'       => [['uid' => 1, 'title' => ['a']], 'must be a string'];
        yield 'boolean title'     => [['uid' => 1, 'title' => true], 'must be a string'];
        yield 'array description' => [['uid' => 1, 'description' => ['a']], 'must be a string'];

        // The alternative text has its own tool, and this one says so by
        // refusing rather than by quietly writing the fields it does know.
        yield 'the alternative text'      => [['uid' => 1, 'title' => 'x', 'alternative' => 'y'], 'not an argument of this tool'];
        yield 'a language argument'       => [['uid' => 1, 'title' => 'x', 'sys_language_uid' => 1], 'not an argument of this tool'];
        yield 'an unrelated table column' => [['uid' => 1, 'title' => 'x', 'file' => 9], 'not an argument of this tool'];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('refusedArguments')]
    public function itRefusesInvalidArguments(array $arguments, string $expectedFragment): void
    {
        $result = $this->tool->execute($arguments, $this->contextFor($this->grantedUser()));

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
        $lines = $this->tool->previewCall($arguments, $this->contextFor($this->grantedUser()));

        self::assertCount(1, $lines);
        self::assertStringContainsString($expectedFragment, $lines[0]);
    }

    #[Test]
    public function anUnknownArgumentNameIsEchoedBackStrippedOfAnythingButItsIdentifierCharacters(): void
    {
        $result = $this->tool->execute(
            ['uid' => 1, 'title' => 'x', "caption\n<script>" => 'y'],
            $this->contextFor($this->grantedUser()),
        );

        self::assertTrue($result->isError);
        self::assertStringNotContainsString('<', $result->content);
        self::assertStringContainsString('captionscript', $result->content);
    }

    /**
     * Core marks `title` as an exclude field and `description` not, so a user
     * granted one and not the other is the ordinary case rather than a remote
     * one. The whole call is refused BEFORE the write: the DataHandler would
     * have dropped `title` in silence and applied `description`, leaving an
     * asset described by half of an approved call.
     */
    #[Test]
    public function aMissingFieldLevelGrantRefusesTheWholeCallBeforeAnythingIsWritten(): void
    {
        $user                                  = $this->liveUser();
        $user->user['admin']                   = 0;
        $user->groupData['non_exclude_fields'] = 'sys_file_metadata:description';

        $result = $this->tool->execute(
            ['uid' => 1, 'title' => 'New title', 'description' => 'New description'],
            $this->contextFor($user),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('sys_file_metadata:title', $result->content);
        // Only the ungranted field is named — the grant the user does hold is
        // not a finding.
        self::assertStringNotContainsString('sys_file_metadata:description', $result->content);
        self::assertStringContainsString('Nothing was written', $result->content);
    }

    /**
     * The field without the flag needs no grant, so a user holding none at all
     * gets past this check when the call stays off `title`.
     */
    #[Test]
    public function aFieldCoreDoesNotMarkExcludeNeedsNoGrant(): void
    {
        $user                                  = $this->liveUser();
        $user->user['admin']                   = 0;
        $user->groupData['non_exclude_fields'] = '';

        $result = $this->tool->execute(['uid' => 1, 'description' => 'New description'], $this->contextFor($user));

        self::assertTrue($result->isError, 'the stub connection cannot resolve a file');
        // Past the grant check and refused by the file lookup instead.
        self::assertStringNotContainsString('exclude field', $result->content);
    }

    /**
     * The column is `tinytext`, which holds 255 BYTES. A title of 200 accented
     * characters is 400 bytes and would be truncated by the database, so the
     * refusal has to be measured the way the column is.
     */
    #[Test]
    public function theTitleIsBoundedInBytesBecauseTheColumnIs(): void
    {
        $accented = str_repeat('ä', 200);
        self::assertSame(200, mb_strlen($accented), 'the fixture is under any character bound');
        self::assertGreaterThan(255, strlen($accented), 'and over the byte bound — that is the point');

        $result = $this->tool->execute(['uid' => 1, 'title' => $accented], $this->contextFor($this->grantedUser()));

        self::assertTrue($result->isError);
        self::assertStringContainsString('byte limit', $result->content);
    }

    #[Test]
    public function anUnboundedDescriptionStillHasTheToolsOwnCeiling(): void
    {
        $result = $this->tool->execute(
            ['uid' => 1, 'description' => str_repeat('a', 2001)],
            $this->contextFor($this->grantedUser()),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('exceeds 2000 characters', $result->content);
    }

    #[Test]
    public function anInstallationsOwnTcaBoundWinsOverTheToolsCeiling(): void
    {
        $GLOBALS['TCA']['sys_file_metadata']['columns']['description']['config']['max'] = 120;

        $result = $this->tool->execute(
            ['uid' => 1, 'description' => str_repeat('a', 121)],
            $this->contextFor($this->grantedUser()),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('exceeds the 120 characters', $result->content);
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

    /**
     * The same user with group data present.
     *
     * `BackendUserAuthentication::check()` tests `isset($groupData[$type])`
     * BEFORE `isAdmin()`, so an object assembled without it fails the grant
     * check whatever its admin flag says — which is faithful to the
     * DataHandler and would otherwise mask every assertion after it.
     */
    private function grantedUser(): BackendUserAuthentication
    {
        $user                                  = $this->liveUser();
        $user->groupData['non_exclude_fields'] = '';

        return $user;
    }

    private function contextFor(BackendUserAuthentication $user): ToolExecutionContext
    {
        return ToolExecutionContext::fromBackendUser($user);
    }
}
