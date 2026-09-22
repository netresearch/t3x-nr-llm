<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use DateTimeImmutable;
use DateTimeZone;
use Netresearch\NrLlm\Service\Tool\Builtin\CreateContentElementDraftTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * The content types `create_content_element_draft` offers are read from the
 * live TCA under an exclusion rule, and `fields` carries the scalar columns of
 * the chosen type (ADR-196).
 *
 * Core's EXT:frontend declares the stock types; four test types are added to
 * the TCA at runtime — one scalar-only, one with a FlexForm column, one with
 * an inline column, and `list` with a scalar showitem — so both directions of
 * the rule are exercised against the real {@see \TYPO3\CMS\Core\DataHandling\DataHandler}.
 * {@see CreateContentElementDraftToolTest} holds the behaviour of the four
 * original types unchanged.
 */
#[CoversClass(CreateContentElementDraftTool::class)]
final class CreateContentElementDraftToolTcaTypesTest extends AbstractFunctionalTestCase
{
    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend', 'indexed_search'];

    /** A page every backend user may edit content on. */
    private const PAGE = 2;

    private const SCALAR_TYPE = 'nrllm_scalar';

    private const FLEX_TYPE = 'nrllm_flex';

    private const INLINE_TYPE = 'nrllm_inline';

    /** Scalar as well, with a `number` column whose override the DataHandler cannot evaluate. */
    private const DROPPING_TYPE = 'nrllm_dropping';

    /** Scalar as well, in the `plugins` item group — what `addPlugin()` leaves without a FlexForm. */
    private const PLUGIN_TYPE = 'nrllm_plugin';

    /**
     * Scalar as well, with columns the DataHandler rewrites on purpose: an
     * `input` with `eval` and `min`, a `number` stored as a decimal, a
     * `datetime` stored as seconds of the day, and one stored in a native
     * `TIME` column.
     */
    private const REWRITTEN_TYPE = 'nrllm_rewritten';

    /** The native `TIME` column; core declares none on `tt_content`. */
    private const NATIVE_TIME_COLUMN = 'nrllm_opens';

    /** A page whose TSconfig narrows the form, created per test by {@see self::pageWithTsConfig()}. */
    private const TSCONFIG_PAGE = 3;

    /** The upper bound of the `number` range the scalar test type declares. */
    private const WIDTH_UPPER = 4000;

    private CreateContentElementDraftTool $tool;

    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->connectionPool = $connectionPool;

        $this->importFixture('BeUsers.csv');

        $this->connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid' => self::PAGE, 'pid' => 0, 'title' => 'Open', 'doktype' => 1, 'slug' => '/open',
            'sorting' => 1,
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
        ]);

        $groups = $this->connectionPool->getConnectionForTable('be_groups');
        $groups->insert('be_groups', [
            'uid' => 7, 'pid' => 0, 'title' => 'Editors', 'db_mountpoints' => (string)self::PAGE,
        ]);
        $groups->update('be_users', ['usergroup' => '7', 'options' => 3], ['uid' => 2]);

        $this->addNativeTimeColumn();
        $this->declareTestContentTypes();

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');

        $this->tool = new CreateContentElementDraftTool($this->connectionPool);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function theSpecOffersTheStockProseTypesAndTheScalarTestTypeAndNothingElse(): void
    {
        $spec = $this->tool->getSpec();

        $properties = $spec->parameters['properties'] ?? null;
        self::assertIsArray($properties);
        $typeProperty = $properties['type'] ?? null;
        self::assertIsArray($typeProperty);
        $description = $typeProperty['description'] ?? null;
        self::assertIsString($description);

        // `textmedia`, `textpic` and `image` carry a `file` column and stay
        // offered; `uploads` carries `file_collections` (type `group`) and does not.
        foreach (['header', 'text', 'textmedia', 'textpic', 'image', 'bullets', 'table', self::SCALAR_TYPE, self::REWRITTEN_TYPE] as $offered) {
            self::assertMatchesRegularExpression('/\b' . $offered . '\b/', $description, $offered . ' must be offered');
            self::assertMatchesRegularExpression('/\b' . $offered . '\b/', $spec->description, $offered . ' must be offered');
        }

        // The plugin type has `header`'s scalar form and is excluded by its item group.
        // indexed_search registers its plugin into the `forms` group, without
        // a FlexForm, so its form is `header`'s as well.
        foreach (['shortcut', 'div', 'html', 'list', 'uploads', 'menu_pages', 'indexedsearch_pi2', self::FLEX_TYPE, self::INLINE_TYPE, self::PLUGIN_TYPE] as $excluded) {
            self::assertDoesNotMatchRegularExpression('/\b' . $excluded . '\b/', $description, $excluded . ' must not be offered');
        }

        $fields = $properties['fields'] ?? null;
        self::assertIsArray($fields);
        self::assertSame('object', $fields['type'] ?? null);
        self::assertTrue($fields['additionalProperties'] ?? null);
        $fieldsDescription = $fields['description'] ?? null;
        self::assertIsString($fieldsDescription);
        self::assertStringContainsString('TCA', $fieldsDescription);
    }

    #[Test]
    public function aScalarOnlyTypeIsCreatedWithItsFields(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            [
                'page'   => self::PAGE,
                'type'   => self::SCALAR_TYPE,
                'header' => 'Scalar draft',
                'fields' => [
                    // select, validated against the static items (0, 1, 2)
                    'bullets_type'  => 2,
                    // check, as a boolean
                    'sectionIndex'  => true,
                    // input, bounded by the TCA `max`
                    'table_caption' => ' A caption ',
                    // number with a TCA range
                    'imagewidth'    => 320,
                    // datetime, format `date`
                    'date'          => '2026-09-21',
                ],
            ],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('Created hidden ' . self::SCALAR_TYPE . ' element', $result->content);
        self::assertStringContainsString('bullets_type, sectionIndex, table_caption, imagewidth, date', $result->content);

        $row = $this->createdElement();
        self::assertSame(1, (int)($row['hidden'] ?? 0), 'a drafted element must never be visible');
        self::assertSame(self::SCALAR_TYPE, $row['CType'] ?? null);
        self::assertSame(2, (int)($row['bullets_type'] ?? -1));
        self::assertSame(1, (int)($row['sectionIndex'] ?? -1));
        self::assertSame('A caption', $row['table_caption'] ?? null);
        self::assertSame(320, (int)($row['imagewidth'] ?? -1));
        self::assertGreaterThan(0, (int)($row['date'] ?? 0), 'the date must have landed');
    }

    #[Test]
    public function textmediaIsStillCreatedWithHeaderAndBodyOnly(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['page' => self::PAGE, 'type' => 'textmedia', 'header' => 'Media later', 'bodytext' => '<p>Text first.</p>'],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);

        $row = $this->createdElement();
        self::assertSame('textmedia', $row['CType'] ?? null);
        self::assertSame(1, (int)($row['hidden'] ?? 0));
        self::assertSame(0, (int)($row['assets'] ?? -1), 'no media is attached by this tool');
    }

    #[Test]
    public function aTypeWithAFlexFormColumnIsRefused(): void
    {
        $this->assertRefusedAndNothingCreated(
            ['page' => self::PAGE, 'type' => self::FLEX_TYPE, 'header' => 'x'],
            'is not a content type this tool creates',
        );
    }

    #[Test]
    public function aTypeWithAnInlineColumnIsRefused(): void
    {
        $this->assertRefusedAndNothingCreated(
            ['page' => self::PAGE, 'type' => self::INLINE_TYPE, 'header' => 'x'],
            'is not a content type this tool creates',
        );
    }

    /**
     * A plugin registered without a FlexForm has `header`'s scalar form —
     * `ExtensionManagementUtility::addPlugin()` copies it — and sits in the
     * `plugins` item group. The group excludes it; the form would not.
     */
    #[Test]
    public function aPluginWithAScalarFormIsRefused(): void
    {
        $this->assertRefusedAndNothingCreated(
            ['page' => self::PAGE, 'type' => self::PLUGIN_TYPE, 'header' => 'x'],
            'is not a content type this tool creates',
        );
    }

    /**
     * Core's indexed_search registers `indexedsearch_pi2` into the `forms`
     * item group without a FlexForm, so its form is `header`'s. It is a
     * plugin by its Extbase registration and by that group, and it stays out.
     */
    #[Test]
    public function coreIndexedSearchPluginIsRefusedAndNothingIsWritten(): void
    {
        $this->assertRefusedAndNothingCreated(
            ['page' => self::PAGE, 'type' => 'indexedsearch_pi2', 'header' => 'Search'],
            'is not a content type this tool creates',
        );
    }

    /**
     * Both carry a scalar-only showitem in this instance — `html` from core,
     * `list` from this test — and both stay out of reach: the deny-list is
     * checked before the showitem is read.
     */
    #[Test]
    public function listAndHtmlAreRefusedAlthoughTheirShowitemIsScalar(): void
    {
        $this->assertRefusedAndNothingCreated(
            ['page' => self::PAGE, 'type' => 'list', 'header' => 'x'],
            'is not a content type this tool creates',
        );
        $this->assertRefusedAndNothingCreated(
            ['page' => self::PAGE, 'type' => 'html', 'header' => 'x', 'bodytext' => '<script></script>'],
            'is not a content type this tool creates',
        );
    }

    #[Test]
    public function aSelectValueOutsideTheItemsIsRefusedAndNothingIsWritten(): void
    {
        $this->assertRefusedAndNothingCreated(
            ['page' => self::PAGE, 'type' => self::SCALAR_TYPE, 'header' => 'x', 'fields' => ['bullets_type' => 7]],
            'the value for "bullets_type" must be one of: "0", "1", "2"',
        );
    }

    /**
     * The range is the one the type declares through `columnsOverrides`, not
     * the column's own — core's differs between 13.4 and 14.3.
     */
    #[Test]
    public function aNumberOutsideTheTcaRangeIsRefused(): void
    {
        $this->assertRefusedAndNothingCreated(
            ['page' => self::PAGE, 'type' => self::SCALAR_TYPE, 'header' => 'x', 'fields' => ['imagewidth' => 0]],
            'the value for "imagewidth" must be at least 1',
        );
        $this->assertRefusedAndNothingCreated(
            ['page' => self::PAGE, 'type' => self::SCALAR_TYPE, 'header' => 'x', 'fields' => ['imagewidth' => self::WIDTH_UPPER + 1]],
            'the value for "imagewidth" must be at most ' . self::WIDTH_UPPER,
        );
    }

    #[Test]
    public function aColumnNotInTheTypesShowitemIsRefused(): void
    {
        // `table_class` is a scalar tt_content column, but the scalar test type
        // does not show it; the refusal names the columns the type does offer.
        $this->assertRefusedAndNothingCreated(
            ['page' => self::PAGE, 'type' => self::SCALAR_TYPE, 'header' => 'x', 'fields' => ['table_class' => 'striped']],
            '"table_class" is not a scalar column of content type "' . self::SCALAR_TYPE . '"',
        );
    }

    #[Test]
    public function aColumnOnTheFieldDenyListIsRefused(): void
    {
        foreach (['hidden' => 0, 'fe_group' => '-2', 'colPos' => 1, 'header' => 'x'] as $column => $value) {
            $this->assertRefusedAndNothingCreated(
                ['page' => self::PAGE, 'type' => self::SCALAR_TYPE, 'header' => 'x', 'fields' => [$column => $value]],
                sprintf('"%s" cannot be set through "fields"', $column),
            );
        }
    }

    /**
     * `bullets_type` is an `exclude` field. The DataHandler would drop it in
     * silence and create the element without it; the grant is asked before the
     * write and the whole call is refused instead.
     */
    #[Test]
    public function aMissingExcludeFieldGrantRefusesTheWholeCall(): void
    {
        $editor                                  = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify']      = 'tt_content';
        $editor->groupData['explicit_allowdeny'] = 'tt_content:CType:' . self::SCALAR_TYPE;
        // `hidden` granted, `bullets_type` deliberately not.
        $editor->groupData['non_exclude_fields'] = 'tt_content:hidden';

        $result = $this->tool->execute(
            ['page' => self::PAGE, 'type' => self::SCALAR_TYPE, 'header' => 'x', 'fields' => ['bullets_type' => 1]],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('no field-level ("exclude field") grant for tt_content:bullets_type', $result->content);
        self::assertSame(0, $this->elementCount(), 'nothing may have been created');
    }

    #[Test]
    public function theEditorCreatesWithTheFieldsTheyAreGranted(): void
    {
        $editor                                  = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify']      = 'tt_content';
        $editor->groupData['explicit_allowdeny'] = 'tt_content:CType:' . self::SCALAR_TYPE;
        $editor->groupData['non_exclude_fields'] = 'tt_content:hidden,tt_content:bullets_type';

        $result = $this->tool->execute(
            ['page' => self::PAGE, 'type' => self::SCALAR_TYPE, 'header' => 'x', 'fields' => ['bullets_type' => 1]],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame(1, (int)($this->createdElement()['bullets_type'] ?? -1));
    }

    /**
     * `tt_content.CType` is an `authMode` column. The DataHandler drops a
     * value outside the acting user's `explicit_allowdeny` without an error
     * and creates the element as the default type; the tool asks the same
     * question before the write.
     */
    #[Test]
    public function aContentTypeOutsideTheEditorsAllowListIsRefusedBeforeTheWrite(): void
    {
        $editor = $this->editorFor('text');

        $result = $this->tool->execute(
            ['page' => self::PAGE, 'type' => self::SCALAR_TYPE, 'header' => 'x'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('not allowed content type "' . self::SCALAR_TYPE . '"', $result->content);
        self::assertStringContainsString('tt_content:CType:' . self::SCALAR_TYPE, $result->content);
        self::assertSame(0, $this->elementCount(), 'nothing may have been created');
    }

    /**
     * Core marks `exclude` with a boolean; an extension may write the integer
     * `1`, which the schema reads as the same flag. The grant is asked either
     * way, before the write.
     */
    #[Test]
    public function anIntegerExcludeFlagIsReadAsTheFlag(): void
    {
        $this->overrideTca(['columns' => ['table_caption' => ['exclude' => 1]]]);
        $editor = $this->editorFor(self::SCALAR_TYPE);

        $result = $this->tool->execute(
            ['page' => self::PAGE, 'type' => self::SCALAR_TYPE, 'header' => 'x', 'fields' => ['table_caption' => 'Caption']],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('no field-level ("exclude field") grant for tt_content:table_caption', $result->content);
        self::assertSame(0, $this->elementCount(), 'nothing may have been created');
    }

    /**
     * `sys_language_uid` is an `exclude` column (core's TcaEnrichment). The
     * DataHandler drops it for an editor without the grant and the element
     * lands in the default language while the call asked for another. The
     * columns the tool writes itself are asked before the write too, where
     * the value differs from what the silence would leave.
     */
    #[Test]
    public function aMissingGrantForTheLanguageColumnRefusesTheWholeCall(): void
    {
        $editor = $this->editorFor(self::SCALAR_TYPE);

        $result = $this->tool->execute(
            ['page' => self::PAGE, 'type' => self::SCALAR_TYPE, 'header' => 'x', 'language' => 1],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('no field-level ("exclude field") grant for tt_content:sys_language_uid', $result->content);
        self::assertSame(0, $this->elementCount(), 'nothing may have been created');
    }

    /**
     * The other columns the tool writes itself go through the same question
     * where an installation marks them `exclude` — core does not, so the flag
     * is set here. Position is asked because it differs from the `0` the
     * silence would leave; the hidden column is granted and not named.
     */
    #[Test]
    public function aMissingGrantForAColumnTheToolWritesItselfRefusesTheWholeCall(): void
    {
        $this->overrideTca(['columns' => [
            'header'   => ['exclude' => true],
            'bodytext' => ['exclude' => true],
            'colPos'   => ['exclude' => true],
        ]]);
        $editor = $this->editorFor(self::SCALAR_TYPE);

        $result = $this->tool->execute(
            ['page' => self::PAGE, 'type' => self::SCALAR_TYPE, 'header' => 'x', 'bodytext' => 'y', 'column' => 1],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString(
            'no field-level ("exclude field") grant for tt_content:header, tt_content:bodytext, tt_content:colPos.',
            $result->content,
        );
        self::assertSame(0, $this->elementCount(), 'nothing may have been created');
    }

    /**
     * The read-back behind that check. The DataHandler also drops, silently,
     * a column whose `displayCond` is `HIDE_FOR_NON_ADMINS` — a silence the
     * grant check cannot see. The element then carries the default position
     * and language, not the ones asked for; the read-back compares both and
     * takes the element back.
     */
    #[Test]
    public function anElementThatLandedInAnotherColumnOrLanguageIsDeletedAgain(): void
    {
        $this->overrideTca(['columns' => [
            'colPos'           => ['displayCond' => 'HIDE_FOR_NON_ADMINS'],
            'sys_language_uid' => ['displayCond' => 'HIDE_FOR_NON_ADMINS'],
        ]]);
        $editor = $this->editorFor(self::SCALAR_TYPE, 'tt_content:sys_language_uid');

        $result = $this->tool->execute(
            ['page' => self::PAGE, 'type' => self::SCALAR_TYPE, 'header' => 'x', 'column' => 1, 'language' => 1],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('colPos, sys_language_uid did not carry the value asked for', $result->content);
        self::assertStringContainsString('was deleted again', $result->content);
        self::assertSame(0, $this->undeletedElementCount(), 'nothing undeleted may be left behind');
    }

    /**
     * A date is handed to the DataHandler as the integer both cores store,
     * not as a string: 13.4 reads a string as UTC wall time and shifts it by
     * the server's offset, so a day would land on the evening before. The
     * container runs in UTC, where the two agree, so the zone is set here.
     *
     * 14.3 reads an offset string correctly, so on this core the row tells
     * the two shapes apart only on the 13.4 matrix cell. The card is the
     * human gate (ADR-136), so it shows the day the integer stands for, in
     * the server's zone, and not the integer.
     */
    #[Test]
    public function aDateLandsOnTheDayAskedForInTheServersTimezone(): void
    {
        $admin     = $this->setUpBackendUser(1);
        $context   = ToolExecutionContext::fromBackendUser($admin);
        $arguments = ['page' => self::PAGE, 'type' => self::SCALAR_TYPE, 'header' => 'x', 'fields' => ['date' => '2026-09-21']];
        $zone      = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');

        try {
            $lines  = $this->tool->previewCall($arguments, $context);
            $result = $this->tool->execute($arguments, $context);
        } finally {
            date_default_timezone_set($zone);
        }

        $midnight = (new DateTimeImmutable('2026-09-21', new DateTimeZone('Europe/Berlin')))->getTimestamp();
        self::assertContains('date: "2026-09-21 00:00:00"', $lines, 'the card shows the day, not the timestamp');
        self::assertFalse($result->isError, $result->content);
        self::assertSame($midnight, (int)($this->createdElement()['date'] ?? 0), 'the day must not shift');
    }

    /**
     * An integer on a `time` column is seconds of the day to both cores, not
     * a Unix timestamp, and is handed over as it is. Read as a timestamp it
     * would shift by the server's offset, so the zone is set here. The card
     * shows the time of day the seconds stand for.
     */
    #[Test]
    public function aTimeGivenAsSecondsOfTheDayIsStoredAsItIs(): void
    {
        $admin     = $this->setUpBackendUser(1);
        $context   = ToolExecutionContext::fromBackendUser($admin);
        $arguments = ['page' => self::PAGE, 'type' => self::REWRITTEN_TYPE, 'header' => 'x', 'fields' => ['date' => 14 * 3600 + 30 * 60]];
        $zone      = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');

        try {
            $lines  = $this->tool->previewCall($arguments, $context);
            $result = $this->tool->execute($arguments, $context);
        } finally {
            date_default_timezone_set($zone);
        }

        self::assertContains('date: "14:30:00"', $lines, 'the card shows the time of day, not the seconds');
        self::assertFalse($result->isError, $result->content);
        self::assertSame(14 * 3600 + 30 * 60, (int)($this->createdElement()['date'] ?? 0), 'the time must not shift');
    }

    /**
     * A `time` column stores seconds of the day, and both cores read an
     * integer as that.
     */
    #[Test]
    public function aTimeIsStoredAsSecondsOfTheDay(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['page' => self::PAGE, 'type' => self::REWRITTEN_TYPE, 'header' => 'x', 'fields' => ['date' => '14:30']],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame(14 * 3600 + 30 * 60, (int)($this->createdElement()['date'] ?? 0));
    }

    /**
     * Midnight on a native `time` column is stored as `00:00:00`, which is a
     * value there and not the column's emptiness — core keeps it where it
     * nulls the other native empty values. The read-back reads it as held.
     */
    #[Test]
    public function aMidnightOnANativeTimeColumnIsReadBackAsHeld(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['page' => self::PAGE, 'type' => self::REWRITTEN_TYPE, 'header' => 'x', 'fields' => [self::NATIVE_TIME_COLUMN => '00:00']],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame('00:00:00', $this->createdElement()[self::NATIVE_TIME_COLUMN] ?? null);
    }

    /**
     * The other direction: a native `time` column the DataHandler dropped
     * reads back `NULL`, which is its emptiness, and the element is taken
     * back.
     */
    #[Test]
    public function aNativeTimeColumnTheDataHandlerDroppedIsReportedAndTheElementIsDeletedAgain(): void
    {
        $this->overrideTca(['columns' => [self::NATIVE_TIME_COLUMN => ['displayCond' => 'HIDE_FOR_NON_ADMINS']]]);
        $editor = $this->editorFor(self::REWRITTEN_TYPE);

        $result = $this->tool->execute(
            ['page' => self::PAGE, 'type' => self::REWRITTEN_TYPE, 'header' => 'x', 'fields' => [self::NATIVE_TIME_COLUMN => '14:30']],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString(self::NATIVE_TIME_COLUMN . ' did not carry the value asked for', $result->content);
        self::assertStringContainsString('was deleted again', $result->content);
        self::assertSame(0, $this->undeletedElementCount(), 'nothing undeleted may be left behind');
    }

    /**
     * An `input` column with `eval` is rewritten by the DataHandler on
     * purpose (`upper` here), so the read-back checks that it took, not that
     * it is byte-equal.
     */
    #[Test]
    public function anEvaluatedInputColumnIsAcceptedAsTheDataHandlerRewritesIt(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['page' => self::PAGE, 'type' => self::REWRITTEN_TYPE, 'header' => 'x', 'fields' => ['table_caption' => 'abc']],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame('ABC', $this->createdElement()['table_caption'] ?? null);
    }

    /**
     * Below `min` the DataHandler stores '' in silence; the value is refused
     * instead, before the write.
     */
    #[Test]
    public function anInputBelowTheTcaMinIsRefused(): void
    {
        $this->assertRefusedAndNothingCreated(
            ['page' => self::PAGE, 'type' => self::REWRITTEN_TYPE, 'header' => 'x', 'fields' => ['table_caption' => 'ab']],
            'the value for "table_caption" must be at least 3 characters',
        );
    }

    /**
     * A decimal is handed over with two decimals and comes back from the
     * database as the database renders it; the read-back compares numbers.
     */
    #[Test]
    public function aDecimalIsComparedAsANumberOnTheReadBack(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['page' => self::PAGE, 'type' => self::REWRITTEN_TYPE, 'header' => 'x', 'fields' => ['imagewidth' => 12]],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        $stored = $this->createdElement()['imagewidth'] ?? null;
        self::assertIsNumeric($stored);
        self::assertSame(12.0, (float)$stored);
    }

    /**
     * The backstop behind the grant check. `checkValueForNumber()` returns no
     * value for a `format` it does not know, without an `errorLog` entry, so
     * the element comes into being without the column. The read-back reports
     * the column and the element is taken back — an approver agreed to the
     * whole draft.
     */
    #[Test]
    public function aColumnTheDataHandlerDroppedIsReportedAndTheElementIsDeletedAgain(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['page' => self::PAGE, 'type' => self::DROPPING_TYPE, 'header' => 'x', 'fields' => ['imagewidth' => 320]],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('imagewidth did not carry the value asked for', $result->content);
        self::assertStringContainsString('was deleted again', $result->content);
        self::assertSame(0, $this->undeletedElementCount(), 'nothing undeleted may be left behind');
    }

    /**
     * `header`'s form shows no body. The DataHandler would still write one,
     * under the column's base config — through the RTE where the base
     * enables it — and the read-back, comparing under the type's config,
     * would delete a correct element and blame a grant. The body is refused
     * where the form does not show it, as a `fields` key is.
     */
    #[Test]
    public function aBodyIsRefusedForATypeWhoseFormDoesNotShowIt(): void
    {
        $this->overrideTca(['columns' => ['bodytext' => ['config' => ['enableRichtext' => true]]]]);

        $this->assertRefusedAndNothingCreated(
            ['page' => self::PAGE, 'type' => 'header', 'header' => 'x', 'bodytext' => "Line one\nLine two"],
            'content type "header" shows no "bodytext"',
        );
    }

    /**
     * `text` enables the RTE on its body, which rewrites plain lines on
     * purpose; the read-back checks the body for presence and keeps the
     * element.
     */
    #[Test]
    public function aPlainMultiLineBodyOnAnRteTextIsCreatedAndKept(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['page' => self::PAGE, 'type' => 'text', 'header' => 'x', 'bodytext' => "Line one\nLine two"],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame(1, $this->undeletedElementCount(), 'the element must not have been taken back');
        self::assertStringContainsString('Line two', (string)($this->createdElement()['bodytext'] ?? ''));
    }

    /**
     * `TCEFORM.tt_content.CType.keepItems` and `.removeItems` narrow the type
     * selector per page. FormEngine applies them; the DataHandler does not,
     * so the tool asks them itself, after the page is authorised.
     */
    #[Test]
    public function aTypeThePagesTsConfigTakesOutOfTheSelectorIsRefused(): void
    {
        $this->pageWithTsConfig('TCEFORM.tt_content.CType.keepItems = text');
        $this->assertRefusedAndNothingCreated(
            ['page' => self::TSCONFIG_PAGE, 'type' => 'table', 'header' => 'x'],
            'content type "table" is not offered on page [3] by its page TSconfig (TCEFORM.tt_content.CType.keepItems)',
        );

        $this->pageWithTsConfig('TCEFORM.tt_content.CType.removeItems = bullets, table');
        $this->assertRefusedAndNothingCreated(
            ['page' => self::TSCONFIG_PAGE, 'type' => 'table', 'header' => 'x'],
            '(TCEFORM.tt_content.CType.removeItems)',
        );
    }

    /**
     * A select item page TSconfig removes, at column level or for the
     * chosen type, is not a value the form offers there.
     */
    #[Test]
    public function aSelectItemThePagesTsConfigRemovesIsRefused(): void
    {
        $this->pageWithTsConfig('TCEFORM.tt_content.layout.removeItems = 1,2,3');
        $this->assertRefusedAndNothingCreated(
            ['page' => self::TSCONFIG_PAGE, 'type' => 'table', 'header' => 'x', 'fields' => ['layout' => '1']],
            'the value "1" for "layout" is not offered on page [3] by its page TSconfig (TCEFORM.tt_content.layout.removeItems)',
        );

        $this->pageWithTsConfig('TCEFORM.tt_content.layout.keepItems = 0,2');
        $this->assertRefusedAndNothingCreated(
            ['page' => self::TSCONFIG_PAGE, 'type' => 'table', 'header' => 'x', 'fields' => ['layout' => '1']],
            '(TCEFORM.tt_content.layout.keepItems)',
        );

        $this->pageWithTsConfig('TCEFORM.tt_content.layout.types.table.removeItems = 2');
        $this->assertRefusedAndNothingCreated(
            ['page' => self::TSCONFIG_PAGE, 'type' => 'table', 'header' => 'x', 'fields' => ['layout' => '2']],
            '(TCEFORM.tt_content.layout.types.table.removeItems)',
        );
    }

    #[Test]
    public function aColumnThePagesTsConfigDisablesIsRefused(): void
    {
        $this->pageWithTsConfig('TCEFORM.tt_content.subheader.disabled = 1');
        $this->assertRefusedAndNothingCreated(
            ['page' => self::TSCONFIG_PAGE, 'type' => 'table', 'header' => 'x', 'fields' => ['subheader' => 'Sub']],
            '"subheader" is not shown on page [3] by its page TSconfig (TCEFORM.tt_content.subheader.disabled)',
        );

        // The body is an argument of its own and is hidden the same way.
        $this->pageWithTsConfig('TCEFORM.tt_content.bodytext.types.table.disabled = 1');
        $this->assertRefusedAndNothingCreated(
            ['page' => self::TSCONFIG_PAGE, 'type' => 'table', 'header' => 'x', 'bodytext' => 'a|b'],
            '"bodytext" is not shown on page [3] by its page TSconfig (TCEFORM.tt_content.bodytext.types.table.disabled)',
        );
    }

    /**
     * Core's EXT:frontend ships
     * `TCEFORM.tt_content.imageorient.types.image.removeItems = 8,9,10,17,18,25,26`
     * as global page TSconfig. On an `image` element those positions are
     * refused; on `textpic`, which the rule does not name, and for a
     * position it keeps, the element is created.
     */
    #[Test]
    public function coresImageorientDefaultIsHonoured(): void
    {
        $this->assertRefusedAndNothingCreated(
            ['page' => self::PAGE, 'type' => 'image', 'header' => 'x', 'fields' => ['imageorient' => 8]],
            '(TCEFORM.tt_content.imageorient.types.image.removeItems)',
        );

        $admin   = $this->setUpBackendUser(1);
        $context = ToolExecutionContext::fromBackendUser($admin);

        $kept = $this->tool->execute(
            ['page' => self::PAGE, 'type' => 'image', 'header' => 'x', 'fields' => ['imageorient' => 1]],
            $context,
        );
        self::assertFalse($kept->isError, $kept->content);

        $otherType = $this->tool->execute(
            ['page' => self::PAGE, 'type' => 'textpic', 'header' => 'y', 'fields' => ['imageorient' => 8]],
            $context,
        );
        self::assertFalse($otherType->isError, $otherType->content);
        self::assertSame(2, $this->undeletedElementCount());
    }

    /**
     * The page TSconfig names the page, so it is read only after the page
     * is authorised: a user without access gets the neutral refusal.
     */
    #[Test]
    public function thePagesTsConfigIsNotReportedToAUserWithoutAccessToThePage(): void
    {
        $this->pageWithTsConfig('TCEFORM.tt_content.CType.keepItems = text');
        $editor = $this->editorFor('table');

        $result = $this->tool->execute(
            ['page' => self::TSCONFIG_PAGE, 'type' => 'table', 'header' => 'x'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError);
        self::assertSame('Page not found or not permitted.', $result->content);
    }

    #[Test]
    public function thePreviewNamesEveryFieldTheCallWouldSet(): void
    {
        $admin = $this->setUpBackendUser(1);

        $lines = $this->tool->previewCall(
            [
                'page'   => self::PAGE,
                'type'   => self::SCALAR_TYPE,
                'header' => 'Proposed',
                'fields' => ['bullets_type' => 2, 'table_caption' => 'Caption', 'sectionIndex' => false],
            ],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertCount(8, $lines);
        self::assertStringContainsString('New ' . self::SCALAR_TYPE . ' element on page [2]', $lines[0]);
        self::assertSame('bullets_type: "2"', $lines[3]);
        self::assertSame('table_caption: "Caption"', $lines[4]);
        self::assertSame('sectionIndex: "0"', $lines[5]);
        self::assertStringContainsString('first in the column', $lines[6]);
        self::assertStringContainsString('hidden', $lines[7]);

        self::assertSame(0, $this->elementCount(), 'a preview must not create anything');
    }

    /**
     * Six types beside core's, added the way an installation's TCA overrides
     * add theirs. The DataHandler reads the compiled schema, so it is rebuilt
     * from the changed array (see {@see SetFileAlternativeTextToolFileMountTest}).
     *
     * Every column a test type writes is a core column, so it has a database
     * column; what the test types change is the config the DataHandler
     * applies to it, through `columnsOverrides`.
     */
    private function declareTestContentTypes(): void
    {
        $tca = $GLOBALS['TCA'];
        self::assertIsArray($tca);
        $table = $tca['tt_content'] ?? null;
        self::assertIsArray($table);
        $columns = $table['columns'] ?? null;
        self::assertIsArray($columns);
        $cType = $columns['CType'] ?? null;
        self::assertIsArray($cType);
        $config = $cType['config'] ?? null;
        self::assertIsArray($config);
        $items = $config['items'] ?? null;
        self::assertIsArray($items);
        $types = $table['types'] ?? null;
        self::assertIsArray($types);

        foreach ([self::SCALAR_TYPE, self::FLEX_TYPE, self::INLINE_TYPE, self::DROPPING_TYPE, self::REWRITTEN_TYPE, 'list'] as $value) {
            $items[] = ['label' => $value, 'value' => $value];
        }

        $items[] = ['label' => self::PLUGIN_TYPE, 'value' => self::PLUGIN_TYPE, 'group' => 'plugins'];

        // Nothing writes this column, so it needs no database column.
        $columns['nrllm_children'] = [
            'label'  => 'Children',
            'config' => [
                'type'          => 'inline',
                'foreign_table' => 'sys_file_reference',
                'foreign_field' => 'uid_foreign',
            ],
        ];
        // Written, into the column {@see self::addNativeTimeColumn()} adds.
        $columns[self::NATIVE_TIME_COLUMN] = [
            'label'  => 'Opens',
            'config' => [
                'type'   => 'datetime',
                'dbType' => 'time',
                'format' => 'time',
            ],
        ];

        // The `headers` palette carries `date` (format `date`) for every type.
        $types[self::SCALAR_TYPE] = [
            'showitem' => '--palette--;;headers, bodytext, bullets_type, sectionIndex, table_caption, imagewidth,'
                . ' --div--;core.form.tabs:categories, categories',
            // A range of the type's own: core's `imagewidth` declares a lower
            // bound of 0 on 13.4 and 1 on 14.3, so the bound under test is
            // this one, and the tool has to read it from the type.
            'columnsOverrides' => ['imagewidth' => ['config' => ['range' => ['lower' => 1, 'upper' => self::WIDTH_UPPER]]]],
        ];
        $types[self::FLEX_TYPE] = [
            'showitem' => '--palette--;;headers, pi_flexform',
        ];
        $types[self::INLINE_TYPE] = [
            'showitem' => '--palette--;;headers, nrllm_children',
        ];
        $types[self::DROPPING_TYPE] = [
            'showitem'         => '--palette--;;headers, imagewidth',
            'columnsOverrides' => ['imagewidth' => ['config' => ['format' => 'unknown']]],
        ];
        $types[self::REWRITTEN_TYPE] = [
            'showitem'         => '--palette--;;headers, table_caption, imagewidth, ' . self::NATIVE_TIME_COLUMN,
            'columnsOverrides' => [
                'table_caption' => ['config' => ['eval' => 'upper', 'min' => 3]],
                // The database column is an integer, so the value asked for
                // in the test is a whole one; what is under test is that the
                // read-back compares the two decimals as numbers.
                'imagewidth' => ['config' => ['format' => 'decimal', 'range' => ['lower' => 1]]],
                'date'       => ['config' => ['format' => 'time']],
            ],
        ];
        $types[self::PLUGIN_TYPE] = $types['header'];
        $types['list']            = [
            'showitem' => '--palette--;;headers, bodytext',
        ];

        $config['items']   = $items;
        $cType['config']   = $config;
        $columns['CType']  = $cType;
        $table['columns']  = $columns;
        $table['types']    = $types;
        $tca['tt_content'] = $table;
        $GLOBALS['TCA']    = $tca;

        $this->getService(TcaSchemaFactory::class)->rebuild($tca);
    }

    /**
     * The native `TIME` column for {@see self::REWRITTEN_TYPE}. The schema
     * is built before the types above are declared, so the column is added
     * by hand — per test, because the framework restores a pristine SQLite
     * file before every test but the first, and only where it is missing,
     * because on MariaDB it survives the truncation.
     */
    private function addNativeTimeColumn(): void
    {
        $connection = $this->connectionPool->getConnectionForTable('tt_content');
        if (isset($connection->createSchemaManager()->listTableColumns('tt_content')[self::NATIVE_TIME_COLUMN])) {
            return;
        }

        $connection->executeStatement('ALTER TABLE tt_content ADD COLUMN ' . self::NATIVE_TIME_COLUMN . ' TIME DEFAULT NULL');
    }

    /**
     * Page {@see self::TSCONFIG_PAGE}, outside the editors' mount, carrying
     * the given page TSconfig — replaced when it already exists, so one test
     * can try several rules. The runtime cache of page TSconfig is flushed.
     */
    private function pageWithTsConfig(string $tsConfig): void
    {
        $pages = $this->connectionPool->getConnectionForTable('pages');
        $pages->delete('pages', ['uid' => self::TSCONFIG_PAGE]);
        $pages->insert('pages', [
            'uid' => self::TSCONFIG_PAGE, 'pid' => 0, 'title' => 'Narrowed', 'doktype' => 1, 'slug' => '/narrowed',
            'sorting' => 2, 'TSconfig' => $tsConfig,
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => 0,
        ]);

        $runtimeCache = $this->get(CacheManager::class)->getCache('runtime');
        self::assertInstanceOf(FrontendInterface::class, $runtimeCache);
        $runtimeCache->flush();
    }

    /**
     * A change to the TCA one test needs, applied over the declarations
     * above, with the schema rebuilt.
     *
     * @param array<string, mixed> $patch a partial `tt_content` definition
     */
    private function overrideTca(array $patch): void
    {
        $tca = $GLOBALS['TCA'];
        self::assertIsArray($tca);
        $tca            = array_replace_recursive($tca, ['tt_content' => $patch]);
        $GLOBALS['TCA'] = $tca;

        $this->getService(TcaSchemaFactory::class)->rebuild($tca);
    }

    /**
     * A non-admin who may create the given type, hide it, and set `bullets_type`.
     *
     * @param non-empty-string ...$furtherGrants further `non_exclude_fields` entries
     */
    private function editorFor(string $type, string ...$furtherGrants): BackendUserAuthentication
    {
        $editor                                  = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify']      = 'tt_content';
        $editor->groupData['explicit_allowdeny'] = 'tt_content:CType:' . $type;
        $editor->groupData['non_exclude_fields'] = implode(',', ['tt_content:hidden', ...$furtherGrants]);

        return $editor;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function assertRefusedAndNothingCreated(array $arguments, string $expectedFragment): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute($arguments, ToolExecutionContext::fromBackendUser($admin));

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString($expectedFragment, $result->content);
        self::assertSame(0, $this->elementCount(), 'nothing may have been created');
    }

    /**
     * The one element this tool created, asserting there is exactly one.
     *
     * @return array<string, mixed>
     */
    private function createdElement(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('*')
            ->from('tt_content')
            ->executeQuery()
            ->fetchAllAssociative();

        self::assertCount(1, $rows, 'exactly one element must have been created');

        return $rows[0];
    }

    private function elementCount(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->count('uid')
            ->from('tt_content')
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Elements that are not flagged deleted — what an editor would still see.
     */
    private function undeletedElementCount(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->count('uid')
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();
    }
}
