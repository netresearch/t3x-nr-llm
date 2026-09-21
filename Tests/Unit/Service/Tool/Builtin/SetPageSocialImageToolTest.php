<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Service\Tool\Builtin\SetPageSocialImageTool;
use Netresearch\NrLlm\Service\Tool\EditorActionInterface;
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
 * Declarations and argument validation of the ninth writing tool (ADR-195).
 *
 * Every assertion here stops the call BEFORE the database is touched, so a stub
 * {@see ConnectionPool} is enough. The write itself — the reference row, the
 * page's counter, the replaced reference, the read-back — is exercised against
 * a real database in
 * {@see \Netresearch\NrLlm\Tests\Functional\Service\Tool\SetPageSocialImageToolTest}.
 */
#[CoversClass(SetPageSocialImageTool::class)]
final class SetPageSocialImageToolTest extends AbstractUnitTestCase
{
    private SetPageSocialImageTool $tool;

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

        $GLOBALS['TCA'] = ['pages' => [
            'columns' => [
                'title'         => ['config' => ['type' => 'input']],
                'og_image'      => ['exclude' => true, 'config' => ['type' => 'file', 'allowed' => 'jpg,png']],
                'twitter_image' => ['exclude' => true, 'config' => ['type' => 'file', 'allowed' => 'jpg,png']],
            ],
        ]];
        $GLOBALS['LANG']    = self::createStub(LanguageService::class);
        $GLOBALS['BE_USER'] = $this->liveUser();

        // The gate is final and never reached here: every case refuses before
        // a file is resolved, so a gate without a storage repository suffices.
        $this->tool = new SetPageSocialImageTool(self::createStub(ConnectionPool::class), new FalStorageGate());
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
        // Without `replace` a second run refuses; with it a second run discards
        // a reference an editor may have set in between. Neither is a repeat
        // an at-least-once runtime may perform on its own.
        self::assertInstanceOf(ToolEffectInterface::class, $this->tool);
        self::assertSame(ToolEffect::NON_IDEMPOTENT_WRITE, $this->tool->getEffect());
        self::assertTrue($this->tool->getEffect()->isWrite());
        self::assertFalse($this->tool->getEffect()->isSafeToRetry());
    }

    #[Test]
    public function itShipsDisabledAndIsNotAdminOnly(): void
    {
        self::assertFalse($this->tool->isEnabledByDefault(), 'a writing tool is never on by default');
        self::assertFalse($this->tool->requiresAdmin(), 'an editor sets what the backend already grants them');
        self::assertSame('editing', $this->tool->getGroup());
    }

    #[Test]
    public function itOffersAPreviewAndAnEditorActionOnThePage(): void
    {
        self::assertInstanceOf(ToolPreviewInterface::class, $this->tool);
        self::assertInstanceOf(EditorActionInterface::class, $this->tool);
        self::assertSame(['pages'], $this->tool->getEditorAction()->recordTypes);
    }

    #[Test]
    public function theSpecNamesThePageTheFieldAndTheFileAndNothingElse(): void
    {
        $spec = $this->tool->getSpec();

        self::assertSame('set_page_social_image', $spec->name);
        self::assertSame(['page', 'field', 'file'], $spec->parameters['required'] ?? null);

        $properties = $spec->parameters['properties'] ?? null;
        self::assertIsArray($properties);
        self::assertSame(['page', 'field', 'file', 'replace'], array_keys($properties));
        self::assertIsArray($properties['field']);
        self::assertSame(['og_image', 'twitter_image'], $properties['field']['enum'] ?? null);
        self::assertIsArray($properties['replace']);
        self::assertSame('boolean', $properties['replace']['type'] ?? null);
    }

    /**
     * The `field` enum follows the live TCA, like the content types of
     * `create_content_element_draft`: an installation whose TCA carries only one
     * of the two columns is offered that one.
     */
    #[Test]
    public function theFieldEnumIsNarrowedToTheColumnsTheLiveTcaDeclares(): void
    {
        $tca = $GLOBALS['TCA'];
        self::assertIsArray($tca);
        self::assertIsArray($tca['pages']);
        self::assertIsArray($tca['pages']['columns']);
        unset($tca['pages']['columns']['twitter_image']);
        $GLOBALS['TCA'] = $tca;

        $properties = $this->tool->getSpec()->parameters['properties'] ?? null;
        self::assertIsArray($properties);
        self::assertIsArray($properties['field']);
        self::assertSame(['og_image'], $properties['field']['enum'] ?? null);
    }

    #[Test]
    public function itFailsClosedWithoutAnActingBackendUser(): void
    {
        $result = $this->tool->execute(['page' => 1, 'field' => 'og_image', 'file' => 1], ToolExecutionContext::none());

        self::assertTrue($result->isError);
        self::assertSame('Page or file not found, or not permitted.', $result->content);
    }

    #[Test]
    public function itRefusesOutsideTheLiveWorkspace(): void
    {
        $draftUser            = $this->liveUser();
        $draftUser->workspace = 1;

        $result = $this->tool->execute(['page' => 1, 'field' => 'og_image', 'file' => 1], $this->contextFor($draftUser));

        self::assertTrue($result->isError);
        self::assertStringContainsString('live workspace', $result->content);
    }

    #[Test]
    public function itRefusesAndNamesEachMissingPieceOfTheBackendEnvironment(): void
    {
        unset($GLOBALS['TCA'], $GLOBALS['LANG'], $GLOBALS['BE_USER']);

        $result = $this->tool->execute(['page' => 1, 'field' => 'og_image', 'file' => 1], $this->contextFor($this->liveUser()));

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
        $valid = ['page' => 1, 'field' => 'og_image', 'file' => 1];

        yield 'no page'        => [['field' => 'og_image', 'file' => 1], 'exactly one page'];
        yield 'zero page'      => [['page' => 0, 'field' => 'og_image', 'file' => 1], 'exactly one page'];
        yield 'negative page'  => [['page' => -1, 'field' => 'og_image', 'file' => 1], 'exactly one page'];
        yield 'no field'       => [['page' => 1, 'file' => 1], 'not a page field this tool sets'];
        yield 'unknown field'  => [['page' => 1, 'field' => 'media', 'file' => 1], 'not a page field this tool sets'];
        yield 'no file'        => [['page' => 1, 'field' => 'og_image'], 'exactly one existing file'];
        yield 'zero file'      => [['page' => 1, 'field' => 'og_image', 'file' => 0], 'exactly one existing file'];
        yield 'string replace' => [$valid + ['replace' => 'yes'], '"replace" must be true or false'];
        yield 'unknown argument' => [$valid + ['title' => 'x'], 'not an argument of this tool'];
        // The ones that would defeat the tool's guarantees.
        yield 'uid smuggled in'      => [$valid + ['uid' => 5], 'not an argument of this tool'];
        yield 'language smuggled in' => [$valid + ['language' => 1], 'not an argument of this tool'];
        yield 'crop smuggled in'     => [$valid + ['crop' => '{}'], 'not an argument of this tool'];
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
    public function anUnknownArgumentNameIsEchoedBackStrippedOfAnythingButItsIdentifierCharacters(): void
    {
        $result = $this->tool->execute(
            ['page' => 1, 'field' => 'og_image', 'file' => 1, "crop\n<script>" => 1],
            $this->contextFor($this->liveUser()),
        );

        self::assertTrue($result->isError);
        self::assertStringNotContainsString('<', $result->content);
        self::assertStringContainsString('cropscript', $result->content);
    }

    /**
     * The column vanishes from the live TCA when EXT:seo is not installed. The
     * refusal names the extension, so the model stops retrying a field this
     * installation does not have.
     */
    #[Test]
    public function aFieldTheLiveTcaDoesNotDeclareIsRefusedNamingExtSeo(): void
    {
        $GLOBALS['TCA'] = ['pages' => ['columns' => ['title' => ['config' => ['type' => 'input']]]]];

        $result = $this->tool->execute(['page' => 1, 'field' => 'og_image', 'file' => 1], $this->contextFor($this->liveUser()));

        self::assertTrue($result->isError);
        self::assertStringContainsString('"og_image" is not a page field this tool sets', $result->content);
        self::assertStringContainsString('EXT:seo', $result->content);
    }

    #[Test]
    public function itRefusesAUserWhoMayNotEditTheDefaultLanguage(): void
    {
        $restricted = $this->liveUser();
        // Not an admin, and only language 2 allowed — so the default language,
        // the only one this tool writes in, is out of reach.
        $restricted->user                           = ['uid' => 5, 'admin' => 0];
        $restricted->groupData['allowed_languages'] = '2';

        $result = $this->tool->execute(['page' => 1, 'field' => 'og_image', 'file' => 1], $this->contextFor($restricted));

        self::assertTrue($result->isError);
        self::assertStringContainsString('default language', $result->content);
    }

    /**
     * Both columns are exclude fields, so the DataHandler would drop the page's
     * side of the relation silently for a user without the grant. Asked before
     * anything is written, through the same method the DataHandler asks it with.
     */
    #[Test]
    public function itRefusesAUserWithoutTheExcludeFieldGrantBeforeTouchingTheDatabase(): void
    {
        $ungranted = $this->liveUser();
        // Not an admin (an admin passes every grant), and granted the other
        // field only.
        $ungranted->user                            = ['uid' => 5, 'admin' => 0];
        $ungranted->groupData['non_exclude_fields'] = 'pages:twitter_image';

        $result = $this->tool->execute(['page' => 1, 'field' => 'og_image', 'file' => 1], $this->contextFor($ungranted));

        self::assertTrue($result->isError);
        self::assertStringContainsString('exclude field', $result->content);
        self::assertStringContainsString('pages:og_image', $result->content);
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
