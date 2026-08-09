<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Service\Tool\Builtin\UpdatePageMetadataTool;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Argument validation of the first writing tool (ADR-135).
 *
 * Every assertion here stops the call BEFORE the database is touched, which is
 * why a stub {@see ConnectionPool} is enough: an argument the tool refuses must
 * never reach a query, let alone the DataHandler. The write itself — permissions,
 * `sys_log`, `errorLog` — is exercised against a real database in
 * {@see \Netresearch\NrLlm\Tests\Functional\Service\Tool\UpdatePageMetadataToolTest}.
 */
#[CoversClass(UpdatePageMetadataTool::class)]
final class UpdatePageMetadataToolTest extends AbstractUnitTestCase
{
    private UpdatePageMetadataTool $tool;

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

        // The narrowest TCA the tool reads: which columns exist, and their
        // declared length bounds. EXT:seo is deliberately NOT represented, so
        // the "field exists" intersection is genuinely exercised.
        $GLOBALS['TCA'] = ['pages' => ['columns' => [
            // `required` mirrors the core TCA for `pages.title`; it is what makes
            // the DataHandler drop an empty value silently.
            'title'       => ['config' => ['type' => 'input', 'max' => 255, 'required' => true]],
            'subtitle'    => ['config' => ['type' => 'input', 'max' => 255]],
            'nav_title'   => ['config' => ['type' => 'input', 'max' => 255]],
            'abstract'    => ['config' => ['type' => 'text']],
            'description' => ['config' => ['type' => 'text']],
            'keywords'    => ['config' => ['type' => 'text']],
            'slug'        => ['config' => ['type' => 'slug']],
            'hidden'      => ['config' => ['type' => 'check']],
        ]]];
        $GLOBALS['LANG']    = self::createStub(LanguageService::class);
        $GLOBALS['BE_USER'] = $this->liveUser();

        $this->tool = new UpdatePageMetadataTool(self::createStub(ConnectionPool::class));
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
        self::assertFalse($this->tool->requiresAdmin(), 'an editor writes what the backend already grants them');
        self::assertSame('editing', $this->tool->getGroup());
    }

    #[Test]
    public function theSpecOffersOnlyFieldsThatExistInTheTca(): void
    {
        $spec = $this->tool->getSpec();

        self::assertSame('update_page_metadata', $spec->name);
        self::assertSame(['uid'], $spec->parameters['required'] ?? null);

        $properties = $spec->parameters['properties'] ?? null;
        self::assertIsArray($properties);

        self::assertArrayHasKey('uid', $properties);
        self::assertArrayHasKey('title', $properties);
        self::assertArrayHasKey('description', $properties);
        // EXT:seo is absent from the fixture TCA, so its fields must not be
        // offered — a call naming them could only ever fail.
        self::assertArrayNotHasKey('og_title', $properties);
        // Never offered at all, TCA or not.
        self::assertArrayNotHasKey('slug', $properties);
        self::assertArrayNotHasKey('hidden', $properties);
    }

    #[Test]
    public function itFailsClosedWithoutAnActingBackendUser(): void
    {
        $result = $this->tool->execute(['uid' => 1, 'title' => 'x'], ToolExecutionContext::none());

        self::assertTrue($result->isError);
        self::assertSame('Page not found or not permitted.', $result->content);
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
        // The DataHandler declares $GLOBALS['TCA'] and $GLOBALS['LANG'] as
        // prerequisites and start() only sets its OWN $BE_USER — a hook inside
        // the write still reads the ambient one. The tool refuses rather than
        // mutating globals it does not own (ADR-135).
        unset($GLOBALS['TCA'], $GLOBALS['LANG'], $GLOBALS['BE_USER']);

        $result = $this->tool->execute(['uid' => 1, 'title' => 'x'], $this->contextFor($this->liveUser()));

        self::assertTrue($result->isError);
        self::assertStringContainsString('TCA', $result->content);
        self::assertStringContainsString('language service', $result->content);
        self::assertStringContainsString('backend user', $result->content);
    }

    #[Test]
    public function itRefusesWhenOnlyTheLanguageServiceIsMissing(): void
    {
        unset($GLOBALS['LANG']);

        $result = $this->tool->execute(['uid' => 1, 'title' => 'x'], $this->contextFor($this->liveUser()));

        self::assertTrue($result->isError);
        self::assertStringContainsString('language service', $result->content);
        self::assertStringNotContainsString('TCA', $result->content);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusedArguments(): iterable
    {
        yield 'no uid'            => [['title' => 'x'], 'exactly one page'];
        yield 'zero uid'          => [['uid' => 0, 'title' => 'x'], 'exactly one page'];
        yield 'negative uid'      => [['uid' => -3, 'title' => 'x'], 'exactly one page'];
        yield 'non-numeric uid'   => [['uid' => 'all', 'title' => 'x'], 'exactly one page'];
        yield 'no field at all'   => [['uid' => 1], 'name at least one field'];
        yield 'field not in TCA'  => [['uid' => 1, 'og_title' => 'x'], 'not an editable page metadata field'];
        yield 'never-allowed slug'   => [['uid' => 1, 'slug' => '/evil'], 'not an editable page metadata field'];
        yield 'never-allowed hidden' => [['uid' => 1, 'hidden' => '1'], 'not an editable page metadata field'];
        yield 'never-allowed perms'  => [['uid' => 1, 'perms_everybody' => '31'], 'not an editable page metadata field'];
        yield 'array value'       => [['uid' => 1, 'title' => ['a']], 'must be a string'];
        yield 'boolean value'     => [['uid' => 1, 'title' => true], 'must be a string'];
        yield 'empty required field'      => [['uid' => 1, 'title' => ''], 'is required and cannot be emptied'];
        yield 'blank required field'      => [['uid' => 1, 'title' => '   '], 'is required and cannot be emptied'];
        yield 'empty alongside a good one' => [
            ['uid' => 1, 'description' => 'fine', 'title' => ''],
            'is required and cannot be emptied',
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

    #[Test]
    public function anUnknownFieldNameIsEchoedBackStrippedOfAnythingButItsIdentifierCharacters(): void
    {
        $result = $this->tool->execute(
            ['uid' => 1, "og_title\n<script>" => 'x'],
            $this->contextFor($this->liveUser()),
        );

        self::assertTrue($result->isError);
        self::assertStringNotContainsString('<', $result->content);
        self::assertStringContainsString('og_titlescript', $result->content);
    }

    #[Test]
    public function aValueLongerThanTheTcaBoundIsRefused(): void
    {
        $result = $this->tool->execute(
            ['uid' => 1, 'title' => str_repeat('a', 256)],
            $this->contextFor($this->liveUser()),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('exceeds 255 characters', $result->content);
    }

    #[Test]
    public function anUnboundedTextFieldStillHasTheToolsOwnCeiling(): void
    {
        // `description` is a TCA `text` column with no `max`; an untrusted,
        // model-chosen argument must not be able to write an arbitrary blob.
        $result = $this->tool->execute(
            ['uid' => 1, 'description' => str_repeat('a', 2001)],
            $this->contextFor($this->liveUser()),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('exceeds 2000 characters', $result->content);
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
