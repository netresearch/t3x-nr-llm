<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Service\Tool\Builtin\CreateContentElementDraftTool;
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
 * Argument validation of the fourth writing tool — the first that creates a
 * record (ADR-146).
 *
 * Every assertion here stops the call BEFORE the database is touched, so a stub
 * {@see ConnectionPool} is enough. The creation itself — page permissions, the
 * new uid, the hidden state, the read-back — is exercised against a real
 * database in
 * {@see \Netresearch\NrLlm\Tests\Functional\Service\Tool\CreateContentElementDraftToolTest}.
 */
#[CoversClass(CreateContentElementDraftTool::class)]
final class CreateContentElementDraftToolTest extends AbstractUnitTestCase
{
    private CreateContentElementDraftTool $tool;

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

        // Five declared types, of which two pass the exclusion rule
        // (ADR-196): `header` and `text` hold scalar columns only; `html` is
        // on the deny-list although its form is scalar; `plugin_like` carries
        // a FlexForm column; `record_like` a select backed by a foreign table.
        // `textmedia` and `bullets` are deliberately ABSENT, so the list is
        // genuinely read from this TCA. `header` narrows `subheader` through
        // `columnsOverrides`, the way core narrows `bodytext` per type.
        $GLOBALS['TCA'] = ['tt_content' => [
            'ctrl'    => ['enablecolumns' => ['disabled' => 'hidden']],
            'columns' => [
                'CType' => ['config' => ['type' => 'select', 'items' => [
                    ['label' => 'Header only', 'value' => 'header'],
                    ['label' => 'Text', 'value' => 'text'],
                    ['label' => 'Raw HTML', 'value' => 'html'],
                    ['label' => 'Plugin-like', 'value' => 'plugin_like'],
                    ['label' => 'Record-like', 'value' => 'record_like'],
                ]]],
                'header'       => ['config' => ['type' => 'input']],
                'subheader'    => ['config' => ['type' => 'input', 'max' => 40]],
                'bodytext'     => ['config' => ['type' => 'text']],
                'layout'       => ['config' => ['type' => 'select', 'items' => [
                    ['label' => 'Default', 'value' => '0'],
                    ['label' => 'Layout 1', 'value' => '1'],
                ]]],
                'sectionIndex' => ['config' => ['type' => 'check']],
                'hidden'       => ['config' => ['type' => 'check']],
                'pi_flexform'  => ['config' => ['type' => 'flex']],
                'records'      => ['config' => ['type' => 'select', 'foreign_table' => 'tt_address']],
            ],
            'palettes' => [
                'headers' => ['showitem' => 'header, --linebreak--, subheader'],
            ],
            'types' => [
                'header'      => [
                    'showitem'         => '--palette--;;headers, --div--;Appearance, layout, sectionIndex, hidden',
                    'columnsOverrides' => ['subheader' => ['config' => ['max' => 10]]],
                ],
                'text'        => ['showitem' => '--palette--;;headers, bodytext, --div--;Appearance, layout, sectionIndex, hidden'],
                'html'        => ['showitem' => 'header, bodytext, hidden'],
                'plugin_like' => ['showitem' => 'header, pi_flexform, hidden'],
                'record_like' => ['showitem' => 'header, records, hidden'],
            ],
        ]];
        $GLOBALS['LANG']    = self::createStub(LanguageService::class);
        $GLOBALS['BE_USER'] = $this->liveUser();

        $this->tool = new CreateContentElementDraftTool(self::createStub(ConnectionPool::class));
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
        // A creation with no caller-supplied key: two runs leave two elements.
        // The reaper must not repeat it, and this declaration is what stops it.
        self::assertInstanceOf(ToolEffectInterface::class, $this->tool);
        self::assertSame(ToolEffect::NON_IDEMPOTENT_WRITE, $this->tool->getEffect());
        self::assertTrue($this->tool->getEffect()->isWrite());
        self::assertFalse($this->tool->getEffect()->isSafeToRetry());
    }

    #[Test]
    public function itShipsDisabledAndIsNotAdminOnly(): void
    {
        self::assertFalse($this->tool->isEnabledByDefault(), 'a writing tool is never on by default');
        self::assertFalse($this->tool->requiresAdmin(), 'an editor drafts what the backend already grants them');
        self::assertSame('editing', $this->tool->getGroup());
    }

    #[Test]
    public function itOffersAPreviewSoTheApprovalCardCanShowTheWholeDraft(): void
    {
        self::assertInstanceOf(ToolPreviewInterface::class, $this->tool);
    }

    #[Test]
    public function theSpecOffersNoWayToPublish(): void
    {
        $spec = $this->tool->getSpec();

        self::assertSame('create_content_element_draft', $spec->name);
        self::assertSame(['page', 'type', 'header'], $spec->parameters['required'] ?? null);

        $properties = $spec->parameters['properties'] ?? null;
        self::assertIsArray($properties);
        // The element is always hidden; there is no argument for it, which is
        // the whole proposition of a draft tool.
        self::assertArrayNotHasKey('hidden', $properties);
        self::assertArrayNotHasKey('visible', $properties);
        self::assertArrayNotHasKey('publish', $properties);
    }

    #[Test]
    public function itFailsClosedWithoutAnActingBackendUser(): void
    {
        $result = $this->tool->execute(
            ['page' => 1, 'type' => 'text', 'header' => 'x'],
            ToolExecutionContext::none(),
        );

        self::assertTrue($result->isError);
        self::assertSame('Page not found or not permitted.', $result->content);
    }

    #[Test]
    public function itRefusesOutsideTheLiveWorkspace(): void
    {
        $draftUser            = $this->liveUser();
        $draftUser->workspace = 1;

        $result = $this->tool->execute(
            ['page' => 1, 'type' => 'text', 'header' => 'x'],
            $this->contextFor($draftUser),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('live workspace', $result->content);
    }

    #[Test]
    public function itRefusesAndNamesEachMissingPieceOfTheBackendEnvironment(): void
    {
        unset($GLOBALS['TCA'], $GLOBALS['LANG'], $GLOBALS['BE_USER']);

        $result = $this->tool->execute(
            ['page' => 1, 'type' => 'text', 'header' => 'x'],
            $this->contextFor($this->liveUser()),
        );

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
        $valid = ['page' => 1, 'type' => 'text', 'header' => 'x'];

        yield 'no page'    => [['type' => 'text', 'header' => 'x'], 'exactly one page'];
        yield 'zero page'  => [['page' => 0, 'type' => 'text', 'header' => 'x'], 'exactly one page'];
        yield 'negative page' => [['page' => -1, 'type' => 'text', 'header' => 'x'], 'exactly one page'];
        yield 'no type'    => [['page' => 1, 'header' => 'x'], 'not a content type this tool creates'];
        // Declared in the fixture TCA with a scalar form and still unreachable:
        // the deny-list is asked before the form is read.
        yield 'html type'  => [['page' => 1, 'type' => 'html', 'header' => 'x'], 'not a content type this tool creates'];
        yield 'plugin type' => [['page' => 1, 'type' => 'list', 'header' => 'x'], 'not a content type this tool creates'];
        // Declared, not denied, and excluded by the FlexForm column in its form.
        yield 'type with a flexform column' => [
            ['page' => 1, 'type' => 'plugin_like', 'header' => 'x'],
            'not a content type this tool creates',
        ];
        // Declared, not denied, and excluded by the record-backed select in its
        // form: a `select` counts as scalar only with static items.
        yield 'type with a foreign_table select' => [
            ['page' => 1, 'type' => 'record_like', 'header' => 'x'],
            'not a content type this tool creates',
        ];
        // Absent from the fixture TCA, so this installation cannot render it
        // and the tool must not offer it.
        yield 'type not in this tca' => [
            ['page' => 1, 'type' => 'textmedia', 'header' => 'x'],
            'not a content type this tool creates',
        ];
        yield 'no header'    => [['page' => 1, 'type' => 'text'], '"header" is required'];
        yield 'empty header' => [['page' => 1, 'type' => 'text', 'header' => ''], 'must not be empty'];
        yield 'blank header' => [['page' => 1, 'type' => 'text', 'header' => '   '], 'must not be empty'];
        yield 'array header' => [['page' => 1, 'type' => 'text', 'header' => ['x']], 'must be a string'];
        yield 'long header'  => [
            ['page' => 1, 'type' => 'text', 'header' => str_repeat('a', 256)],
            'exceeds 255 characters',
        ];
        yield 'long body' => [
            ['page' => 1, 'type' => 'text', 'header' => 'x', 'bodytext' => str_repeat('a', 20001)],
            'exceeds 20000 characters',
        ];
        yield 'negative language' => [$valid + ['language' => -1], 'zero or a positive'];
        yield 'negative column'   => [$valid + ['column' => -1], 'zero or a positive'];
        yield 'unknown argument'  => [$valid + ['hidden' => 0], 'not an argument of this tool'];
        // The one that would defeat the tool's single guarantee.
        yield 'pid smuggled in'   => [$valid + ['pid' => 5], 'not an argument of this tool'];
        // The same guarantee, through the new argument: the visibility column
        // is refused by name, before the type's form is consulted.
        yield 'fields not an object' => [$valid + ['fields' => 'layout=1'], '"fields" must be an object'];
        yield 'hidden via fields'    => [$valid + ['fields' => ['hidden' => 0]], '"hidden" cannot be set through "fields"'];
        yield 'pid via fields'       => [$valid + ['fields' => ['pid' => 5]], '"pid" cannot be set through "fields"'];
        yield 'header via fields'    => [$valid + ['fields' => ['header' => 'y']], 'pass it as the "header" argument'];
        yield 'column not in the form' => [
            $valid + ['fields' => ['nope' => 1]],
            '"nope" is not a scalar column of content type "text". Columns this tool sets for it: subheader, layout, sectionIndex.',
        ];
        // A real column of the table, absent from this type's form and not
        // scalar either: the membership test is the type's form, not the table.
        yield 'table column outside the form' => [
            $valid + ['fields' => ['pi_flexform' => '<T3FlexForms/>']],
            '"pi_flexform" is not a scalar column of content type "text"',
        ];
        yield 'select outside its items' => [$valid + ['fields' => ['layout' => '9']], 'must be one of: "0", "1"'];
        yield 'check that is not a boolean' => [$valid + ['fields' => ['sectionIndex' => 'yes']], 'must be true, false, 0 or 1'];
        yield 'input over the tca max' => [$valid + ['fields' => ['subheader' => str_repeat('a', 41)]], 'exceeds 40 characters'];
        // The same column, narrowed by the type's `columnsOverrides`: the bound
        // is the one the DataHandler will apply for THIS type, not the column's own.
        yield 'input over the type-overridden max' => [
            ['page' => 1, 'type' => 'header', 'header' => 'x', 'fields' => ['subheader' => str_repeat('a', 11)]],
            'exceeds 10 characters',
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
    public function theOfferedTypesAreReadFromTheLiveTcaUnderTheExclusionRule(): void
    {
        $properties = $this->tool->getSpec()->parameters['properties'] ?? null;
        self::assertIsArray($properties);
        $type = $properties['type'] ?? null;
        self::assertIsArray($type);
        $description = $type['description'] ?? '';
        self::assertIsString($description);

        // Exactly what THIS installation declares and the rule lets through:
        // not the denied `html`, not the FlexForm-carrying `plugin_like`, and
        // not a type the TCA does not declare.
        self::assertStringContainsString('One of: header, text.', $description);
        self::assertStringNotContainsString('html', $description);
        self::assertStringNotContainsString('plugin_like', $description);
        self::assertStringNotContainsString('record_like', $description);
        self::assertStringNotContainsString('textmedia', $description);
    }

    #[Test]
    public function theSpecDeclaresFieldsAsAnObjectWithFreeScalarKeys(): void
    {
        $properties = $this->tool->getSpec()->parameters['properties'] ?? null;
        self::assertIsArray($properties);
        $fields = $properties['fields'] ?? null;
        self::assertIsArray($fields);

        self::assertSame('object', $fields['type'] ?? null);
        // Free keys — the allowed ones come from the chosen type's TCA and are
        // not enumerated here — with scalar values only.
        self::assertSame(['type' => ['string', 'boolean', 'number']], $fields['additionalProperties'] ?? null);
        $description = $fields['description'] ?? '';
        self::assertIsString($description);
        self::assertStringContainsString('TCA', $description);

        $required = $this->tool->getSpec()->parameters['required'] ?? null;
        self::assertIsArray($required);
        self::assertNotContains('fields', $required);
    }

    #[Test]
    public function withoutATcaNoTypeIsOfferedAndTheSpecSaysSo(): void
    {
        unset($GLOBALS['TCA']);

        $properties = $this->tool->getSpec()->parameters['properties'] ?? null;
        self::assertIsArray($properties);
        $type = $properties['type'] ?? null;
        self::assertIsArray($type);

        self::assertSame('The content type (CType). One of: none in this process.', $type['description'] ?? null);
    }

    /**
     * The tool reads the disabled column's name from `ctrl.enablecolumns` for
     * its own write, so an installation that renamed it must find that name
     * refused as a `fields` key too — under the standard name alone, `fields`
     * could unhide the draft in the same write that hides it.
     */
    #[Test]
    public function theRenamedVisibilityColumnIsRefusedThroughFieldsUnderTheInstallationsName(): void
    {
        $tca = $GLOBALS['TCA'];
        self::assertIsArray($tca);
        $GLOBALS['TCA'] = array_replace_recursive($tca, ['tt_content' => [
            'ctrl'    => ['enablecolumns' => ['disabled' => 'invisible']],
            'columns' => ['invisible' => ['config' => ['type' => 'check']]],
            'types'   => ['text' => ['showitem' => '--palette--;;headers, bodytext, invisible']],
        ]]);

        $result = $this->tool->execute(
            ['page' => 1, 'type' => 'text', 'header' => 'x', 'fields' => ['invisible' => 0]],
            $this->contextFor($this->liveUser()),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('"invisible" cannot be set through "fields"', $result->content);
    }

    #[Test]
    public function anUnknownArgumentNameIsEchoedBackStrippedOfAnythingButItsIdentifierCharacters(): void
    {
        $result = $this->tool->execute(
            ['page' => 1, 'type' => 'text', 'header' => 'x', "colPos\n<script>" => 1],
            $this->contextFor($this->liveUser()),
        );

        self::assertTrue($result->isError);
        self::assertStringNotContainsString('<', $result->content);
        self::assertStringContainsString('colPosscript', $result->content);
    }

    #[Test]
    public function itRefusesALanguageTheActingUserMayNotEdit(): void
    {
        $restricted = $this->liveUser();
        // Not an admin, and no `allowed_languages` — so only the default
        // language is reachable.
        $restricted->user           = ['uid' => 5, 'admin' => 0];
        $restricted->groupData['allowed_languages'] = '0';

        $result = $this->tool->execute(
            ['page' => 1, 'type' => 'text', 'header' => 'x', 'language' => 2],
            $this->contextFor($restricted),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('may not edit content in language 2', $result->content);
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
