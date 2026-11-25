<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Service\Tool\Builtin\ListContentElementChoicesTool;
use Netresearch\NrLlm\Service\Tool\ContentType\ContentElementComposer;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Functional\Fixtures\ContentChildren\IconItems;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * `list_content_element_choices` names what a computed select of a child
 * record takes, as the acting user may use it and as the draft checks it
 * (ADR-328, spec 064 CC15).
 *
 * The content types come from the fixture extension of the draft tool's tests.
 */
#[CoversClass(ListContentElementChoicesTool::class)]
final class ListContentElementChoicesToolTest extends AbstractFunctionalTestCase
{
    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend', 'workspaces'];

    /** @var non-empty-string[] */
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'typo3conf/ext/nr_llm/Tests/Functional/Fixtures/Extensions/nrllm_content_children_fixture',
    ];

    private const TABLE = 'tx_childrenfixture_item';

    /** The inline column of the fixture's content type. */
    private const COLUMN = 'tx_childrenfixture_items';

    private ListContentElementChoicesTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('BeUsers.csv');
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');
        $this->tool      = new ListContentElementChoicesTool();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function theRegistryOffersTheTool(): void
    {
        self::assertInstanceOf(
            ListContentElementChoicesTool::class,
            $this->getService(ToolRegistry::class)->get('list_content_element_choices'),
        );
    }

    #[Test]
    public function theChoicesOfOneSetAreListedByTheNamesTheDraftTakes(): void
    {
        $content = $this->ask('icon', ['set' => ['icon_set' => 'Beta']])->content;

        self::assertStringContainsString('"home", "EXT:fixture/Icons/Beta/clash.svg", "EXT:fixture/Icons/Beta/upper.svg", "clash"', $content);
        self::assertStringContainsString('All 5 are shown.', $content);
        self::assertStringNotContainsString('alpha-', $content);
        self::assertStringNotContainsString('depend on', $content);

        $names     = [];
        $described = (new ContentElementComposer())->describe('nrllm_items')['children'][self::COLUMN]['fields']['icon']['itemsBy'] ?? null;
        self::assertIsArray($described);
        self::assertIsArray($described['items'] ?? null);
        self::assertIsArray($described['items']['Beta'] ?? null);
        foreach ($described['items']['Beta'] as $name) {
            $names[] = json_encode($name, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        self::assertStringContainsString("\n" . implode(', ', $names) . "\n", $content, 'the list is the one describe() gives');
    }

    /**
     * The stored value of a set is accepted as the draft accepts it.
     */
    #[Test]
    public function aSetIsNamedByItsStoredValueToo(): void
    {
        self::assertSame(
            $this->ask('icon', ['set' => ['icon_set' => 'Alpha']])->content,
            $this->ask('icon', ['set' => ['icon_set' => IconItems::SET]])->content,
        );
    }

    #[Test]
    public function withoutASetTheChoicesAreListedPerSet(): void
    {
        $content = $this->ask('icon')->content;

        self::assertStringContainsString("\nicon_set (empty): \"0\"\n", $content);
        self::assertStringContainsString("\nicon_set \"Alpha\": \"0\", \"alpha-1\"", $content);
        self::assertStringContainsString("\nicon_set \"Beta\": \"0\", \"home\"", $content);
        self::assertStringContainsString('All 67 are shown.', $content);
        self::assertStringContainsString('The choices depend on "icon_set"', $content);
    }

    #[Test]
    public function aListOfSetsIsListedItself(): void
    {
        $content = $this->ask('icon_set')->content;

        self::assertStringContainsString("\n\"Alpha\", \"Beta\"\n", $content);
        self::assertStringNotContainsString('depend on', $content, 'a computed select after this one decides nothing');
    }

    #[Test]
    public function aSearchNamesTheSetOfAHit(): void
    {
        $content = $this->ask('icon', ['search' => 'HOME'])->content;

        self::assertStringContainsString('icon_set "Beta": "home"', $content);
        self::assertStringNotContainsString('"Alpha"', $content);
        self::assertStringContainsString('All 1 are shown.', $content);
    }

    #[Test]
    public function aSearchAsksForEveryWord(): void
    {
        $content = $this->ask('icon', ['set' => ['icon_set' => 'Alpha'], 'search' => '6 alpha 0'])->content;

        self::assertStringContainsString("\n\"alpha-60\"\n", $content);
    }

    /**
     * Several searches in one call: a name is listed that has every word of one
     * of them, and the searches without a hit get their nearest names.
     */
    #[Test]
    public function severalSearchesAreMadeInOneCall(): void
    {
        $content = $this->ask('icon', ['set' => ['icon_set' => 'Alpha'], 'search' => '6 alpha 0, ALPHA-33, alpha-5x'])->content;

        self::assertStringContainsString('that contain "6 alpha 0" or "ALPHA-33" or "alpha-5x":', $content);
        self::assertStringContainsString("\n\"alpha-33\", \"alpha-60\"\n", $content);
        self::assertStringContainsString('All 2 are shown.', $content);
        self::assertStringContainsString('No name contains "alpha-5x". Nearest names:' . "\n" . '"alpha-5"', $content);
        self::assertStringNotContainsString('No name contains "6 alpha 0"', $content, 'a search with a hit is not reported as missed');
        self::assertStringNotContainsString('No name contains "ALPHA-33"', $content);
        self::assertStringNotContainsString('Only the first', $content);
    }

    /**
     * A search found in one of the lists is not missed in the others.
     */
    #[Test]
    public function aSearchFoundInOneSetIsNotMissed(): void
    {
        $content = $this->ask('icon', ['search' => 'home, alpha-33'])->content;

        self::assertStringContainsString('icon_set "Alpha": "alpha-33"', $content);
        self::assertStringContainsString('icon_set "Beta": "home"', $content);
        self::assertStringNotContainsString('No name contains', $content);
    }

    #[Test]
    public function severalSearchesWithoutAHitAreEachAnswered(): void
    {
        $content = $this->ask('icon', ['set' => ['icon_set' => 'Alpha'], 'search' => 'alpha-5x, alpha-7x'])->content;

        self::assertStringContainsString('None of the 61 choices', $content);
        self::assertStringContainsString('No name contains "alpha-5x". Nearest names:' . "\n" . '"alpha-5"', $content);
        self::assertStringContainsString('No name contains "alpha-7x". Nearest names:' . "\n" . '"alpha-7"', $content);
    }

    /**
     * An empty search is none, and a repeated one is made once.
     */
    #[Test]
    public function emptyAndRepeatedSearchesCountOnce(): void
    {
        $set = ['set' => ['icon_set' => 'Alpha'], 'limit' => 1000];

        self::assertSame($this->ask('icon', $set)->content, $this->ask('icon', [...$set, 'search' => ' , ,'])->content);
        self::assertSame(
            $this->ask('icon', [...$set, 'search' => 'alpha-33'])->content,
            $this->ask('icon', [...$set, 'search' => ', alpha-33,, alpha-33 ,'])->content,
        );
    }

    #[Test]
    public function moreSearchesThanOneCallMakesAreCutAndSaidSo(): void
    {
        $content = $this->ask('icon', ['set' => ['icon_set' => 'Alpha'], 'search' => 'n1,n2,n3,n4,n5,n6,n7,n8,n9,alpha-33,alpha-44'])->content;

        self::assertStringContainsString('"alpha-33"', $content);
        self::assertStringNotContainsString('alpha-44', $content);
        self::assertStringContainsString('Only the first 10 of 11 searches were made.', $content);
    }

    /**
     * Each search is bounded on its own: a long one does not take the next.
     */
    #[Test]
    public function eachSearchIsBoundedInLength(): void
    {
        $content = $this->ask('icon', ['set' => ['icon_set' => 'Alpha'], 'search' => str_repeat('y', 5000) . ', alpha-33'])->content;

        self::assertStringContainsString("\n\"alpha-33\"\n", $content);
        self::assertStringContainsString('No name contains "' . str_repeat('y', 60) . '".', $content);
    }

    /**
     * Ten searches without a hit stay below the loop's own cap, and each is named.
     */
    #[Test]
    public function theNearestNamesOfManySearchesAreCut(): void
    {
        $this->setIconProcessor(IconItems::class . '->longNames');

        $content = $this->ask('icon', ['search' => 'a1,a2,a3,a4,a5,a6,a7,a8,a9,a10'])->content;

        self::assertLessThan(10000, strlen($content));
        self::assertSame(4, substr_count($content, '(further lists are left out'), 'six lines of ten long names fit the byte budget');
        self::assertStringContainsString('No name contains "a10".', $content);
    }

    #[Test]
    public function aSearchWithoutAHitIsAnsweredWithTheNearestNames(): void
    {
        $content = $this->ask('icon', ['set' => ['icon_set' => 'Alpha'], 'search' => 'alpha-5x'])->content;

        self::assertStringContainsString('None of the 61 choices', $content);
        self::assertStringContainsString('"alpha-5"', $content);
        self::assertStringNotContainsString('"alpha-10"', $content, 'only the nearest ones');
    }

    #[Test]
    public function aCutListSaysSoWithTheTotal(): void
    {
        $content = $this->ask('icon', ['set' => ['icon_set' => 'Alpha'], 'limit' => 3])->content;

        self::assertStringContainsString('(3 of 61 shown) "0", "alpha-1", "alpha-2"' . "\n", $content);
        self::assertStringContainsString('The list is cut: 3 of 61 are shown. Use "search"', $content);
    }

    /**
     * The limit counts over all lists, and a list it leaves nothing for is named.
     */
    #[Test]
    public function theLimitCountsOverAllLists(): void
    {
        $content = $this->ask('icon', ['limit' => 2])->content;

        self::assertStringContainsString('icon_set "Alpha": (1 of 61 shown) "0"', $content);
        self::assertStringContainsString('icon_set "Beta": (0 of 5 shown) ', $content);
        self::assertStringContainsString('2 of 67 are shown', $content);
    }

    /**
     * The loop would cut a longer answer and its closing line with it.
     */
    #[Test]
    public function aListOfLongNamesIsCutBelowTheLoopsOwnCap(): void
    {
        $this->setIconProcessor(IconItems::class . '->longNames');

        $content = $this->ask('icon', ['limit' => 1000])->content;

        self::assertLessThan(50000, strlen($content));
        self::assertStringContainsString('of 1000 are shown', $content);
    }

    #[Test]
    public function aNameWithALineBreakStartsNoLine(): void
    {
        $this->setIconProcessor(IconItems::class . '->nameWithALineBreak');

        self::assertStringContainsString('"one\nAll 99 are shown."', $this->ask('icon')->content);
    }

    #[Test]
    public function aProcessorThatFailsIsNoEmptyList(): void
    {
        $result = $this->ask('broken');

        self::assertTrue($result->isError);
        self::assertStringContainsString('could not be computed (RuntimeException)', $result->content);
        self::assertStringNotContainsString('No choices', $result->content);
    }

    /**
     * A select with more choices than are listed apart decides, and without it
     * the processor fails: the answer still says what to name in "set".
     */
    #[Test]
    public function aFailureThatTheSetWouldLiftNamesIt(): void
    {
        $this->setStaticSets(60, '');
        $this->setIconProcessor(IconItems::class . '->failsOnASetOfItsOwn');

        $result = $this->ask('icon');

        self::assertTrue($result->isError);
        self::assertStringContainsString('could not be computed (RuntimeException). This is not an empty list.', $result->content);
        self::assertStringContainsString('The choices can depend on icon_set; name it in "set".', $result->content);
        self::assertStringNotContainsString('leave the column out', $result->content);
        self::assertStringContainsString('"icon of s7"', $this->ask('icon', ['set' => ['icon_set' => 's7']])->content);
    }

    /**
     * Nearest names are looked for in a bounded number of lists, and the answer
     * says so.
     */
    #[Test]
    public function theNearestNamesComeFromABoundedNumberOfLists(): void
    {
        $this->setStaticSets(30, 's1');
        $this->setIconProcessor(IconItems::class . '->iconOfAnySet');

        $content = $this->ask('icon', ['search' => 'nothing, nowhere'])->content;

        self::assertSame(20, substr_count($content, '"icon of s'));
        self::assertSame(2, substr_count($content, '(further lists are left out'));
        self::assertStringContainsString('No name contains "nowhere".', $content);
    }

    /**
     * A processor that fails for one set only does not hide the others.
     */
    #[Test]
    public function aSetWhoseProcessorFailsIsNamed(): void
    {
        $this->setIconProcessor(IconItems::class . '->failsForBeta');

        $content = $this->ask('icon')->content;

        self::assertStringContainsString('icon_set "Beta": could not be computed (RuntimeException).', $content);
        self::assertStringContainsString('"alpha-1"', $content);
    }

    #[Test]
    public function aListWithoutChoicesSaysSo(): void
    {
        $this->setIconProcessor(IconItems::class . '->iconsOfASetOnly');

        $result = $this->ask('icon', ['set' => ['icon_set' => '']]);

        self::assertFalse($result->isError);
        self::assertStringContainsString('No choices:', $result->content);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('wrongQuestions')]
    public function aWrongQuestionIsAnsweredWithWhatThereIs(array $arguments, string $expected): void
    {
        $content = $this->tool->execute(
            [...['type' => 'nrllm_items', 'children' => self::COLUMN, 'column' => 'icon'], ...$arguments],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        )->content;

        self::assertStringContainsString($expected, $content);
        self::assertStringNotContainsString('alpha-', $content);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function wrongQuestions(): array
    {
        return [
            'a type that is not offered'    => [['type' => 'html'], 'Unknown content type "html". Content types with computed choices: nrllm_items'],
            'another child-record column'   => [['children' => 'nothing'], 'Unknown child-record column "nothing" of content type "nrllm_items". Child-record columns: ' . self::COLUMN],
            'a column that is not there'    => [['column' => 'nothing'], 'Unknown column "nothing" of the records in "children.' . self::COLUMN . '". Columns with computed choices: icon_set, icon, broken.'],
            'a column without choices'      => [['column' => 'header'], '"header" of the records in "children.' . self::COLUMN . '" has no computed choices.'],
            'a set that is a list'          => [['set' => ['Alpha']], '"set" must be an object'],
            'a set that is a string'        => [['set' => 'Alpha'], '"set" must be an object'],
            'a set of a column not there'   => [['set' => ['nothing' => 'x']], '"nothing" in "set" is not another select'],
            'a set the draft does not know' => [['column' => 'icon_set', 'set' => ['icon' => 'x']], '"icon" in "set" is not another select'],
            'a choice that does not exist'  => [['set' => ['icon_set' => 'Gamma']], 'must be one of 2 choices, for example: "Alpha", "Beta"'],
        ];
    }

    #[Test]
    public function withoutAnActingUserNothingIsListed(): void
    {
        $result = $this->tool->execute(['type' => 'nrllm_items', 'children' => self::COLUMN, 'column' => 'icon'], ToolExecutionContext::none());

        self::assertSame('Not permitted: the acting backend user may not create content elements.', $result->content);
    }

    #[Test]
    public function anEditorWithTheGrantsReadsTheChoices(): void
    {
        $content = $this->askAs($this->editor('tt_content,' . self::TABLE, 'nrllm_items', 'header', 'icon'), 'icon')->content;

        self::assertStringContainsString('"alpha-1"', $content);
    }

    #[Test]
    #[DataProvider('editorsWithoutAGrant')]
    public function anEditorWithoutAGrantIsNotToldTheChoices(string $tables, string $type, string $column, string $expected): void
    {
        $content = $this->askAs($this->editor($tables, $type, 'header', $column), 'icon')->content;

        self::assertStringContainsString($expected, $content);
        self::assertStringNotContainsString('alpha-', $content);
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function editorsWithoutAGrant(): array
    {
        return [
            'no content elements'    => [self::TABLE, 'nrllm_items', 'icon', 'may not create content elements'],
            'another content type'   => ['tt_content,' . self::TABLE, 'text', 'icon', 'is not allowed content type "nrllm_items"'],
            'no child records'       => ['tt_content', 'nrllm_items', 'icon', 'may not create the records of "children.' . self::COLUMN . '"'],
            'no grant of the column' => ['tt_content,' . self::TABLE, 'nrllm_items', 'bodytext', 'no field-level ("exclude field") grant for ' . self::TABLE . ':icon'],
        ];
    }

    /**
     * A select the user may not set decides nothing: the draft leaves it at its default.
     */
    #[Test]
    public function aSetTheEditorMayNotSetIsNeitherNamedNorTaken(): void
    {
        /** @var array{tx_childrenfixture_item: array{columns: array<string, array<string, mixed>>}} $tca */
        $tca                                                = &$GLOBALS['TCA'];
        $tca[self::TABLE]['columns']['icon_set']['exclude'] = true;
        unset($tca);
        $editor = $this->editor('tt_content,' . self::TABLE, 'nrllm_items', 'header', 'icon');

        $content = $this->askAs($editor, 'icon')->content;
        self::assertStringContainsString("\n\"0\"\n", $content);
        self::assertStringNotContainsString('icon_set', $content);

        $content = $this->askAs($editor, 'icon', ['set' => ['icon_set' => 'Alpha']])->content;
        self::assertStringContainsString('"icon_set" in "set" is not another select', $content);
        self::assertStringNotContainsString('alpha-', $content);
    }

    /**
     * Choices `authMode` takes from the user are not named, as the draft refuses them.
     */
    #[Test]
    public function aChoiceTheEditorIsNotAllowedIsNotNamed(): void
    {
        $editor = $this->editor('tt_content,' . self::TABLE, 'nrllm_items', 'header', 'icon');
        self::assertStringContainsString('"Alpha"', $this->askAs($editor, 'icon_set')->content);

        /** @var array{tx_childrenfixture_item: array{columns: array<string, array{config: array<string, mixed>}>}} $tca */
        $tca                                                           = &$GLOBALS['TCA'];
        $tca[self::TABLE]['columns']['icon_set']['config']['authMode'] = 'explicitAllow';
        unset($tca);

        self::assertStringNotContainsString('"Alpha"', $this->askAs($editor, 'icon_set')->content);
        self::assertStringNotContainsString('alpha-', $this->askAs($editor, 'icon', ['set' => ['icon_set' => 'Alpha']])->content);
    }

    /**
     * A static select with `authMode` the choices depend on: a value the user
     * is not allowed is neither listed nor taken.
     */
    #[Test]
    public function aStaticSetTheEditorIsNotAllowedIsNeitherListedNorTaken(): void
    {
        /** @var array{tx_childrenfixture_item: array{columns: array<string, array{config: array<string, mixed>}>}} $tca */
        $tca                                               = &$GLOBALS['TCA'];
        $tca[self::TABLE]['columns']['icon_set']['config'] = [
            'type'       => 'select',
            'renderType' => 'selectSingle',
            'authMode'   => 'explicitAllow',
            'items'      => [['label' => 'A', 'value' => 'a'], ['label' => 'B', 'value' => 'b']],
        ];
        unset($tca);
        $this->setIconProcessor(IconItems::class . '->iconOfAnySet');
        $editor                                  = $this->editor('tt_content,' . self::TABLE, 'nrllm_items', 'header', 'icon');
        $editor->groupData['explicit_allowdeny'] = 'tt_content:CType:nrllm_items,' . self::TABLE . ':icon_set:b';

        $content = $this->askAs($editor, 'icon')->content;
        self::assertStringContainsString('icon_set "b": "icon of b"', $content);
        self::assertStringNotContainsString('icon of a', $content);
        self::assertStringContainsString('"icon of b"', $this->askAs($editor, 'icon', ['set' => ['icon_set' => 'b']])->content);

        $content = $this->askAs($editor, 'icon', ['set' => ['icon_set' => 'a']])->content;
        self::assertStringContainsString('is not allowed the value "a" for "icon_set"', $content);
        self::assertStringNotContainsString('icon of', $content);
    }

    /**
     * The processor sees the acting user, and the ambient one is put back.
     */
    #[Test]
    public function theProcessorRunsAsTheActingUser(): void
    {
        $editor = $this->editor('tt_content,' . self::TABLE, 'nrllm_items', 'header', 'icon');
        $admin  = $this->setUpBackendUser(1);

        $this->askAs($editor, 'icon_set');

        self::assertSame($editor, IconItems::$user);
        self::assertSame($admin, $GLOBALS['BE_USER']);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function ask(string $column, array $arguments = []): ToolResult
    {
        return $this->askAs($this->setUpBackendUser(1), $column, $arguments);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function askAs(BackendUserAuthentication $user, string $column, array $arguments = []): ToolResult
    {
        return $this->tool->execute(
            ['type' => 'nrllm_items', 'children' => self::COLUMN, 'column' => $column, ...$arguments],
            ToolExecutionContext::fromBackendUser($user),
        );
    }

    /**
     * @param string ...$columns the child columns the editor may set
     */
    private function editor(string $tables, string $type, string ...$columns): BackendUserAuthentication
    {
        $editor                                  = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify']      = $tables;
        $editor->groupData['explicit_allowdeny'] = 'tt_content:CType:' . $type;
        $editor->groupData['non_exclude_fields'] = implode(',', ['tt_content:hidden', ...array_map(static fn (string $column): string => self::TABLE . ':' . $column, $columns)]);

        return $editor;
    }

    /**
     * A static icon set with the choices `s1` to `s<count>`.
     */
    private function setStaticSets(int $count, string $default): void
    {
        $items = $default === '' ? [['label' => '', 'value' => '']] : [];
        for ($number = 1; $number <= $count; ++$number) {
            $items[] = ['label' => 'S', 'value' => 's' . $number];
        }

        /** @var array{tx_childrenfixture_item: array{columns: array<string, array{config: array<string, mixed>}>}} $tca */
        $tca                                               = &$GLOBALS['TCA'];
        $tca[self::TABLE]['columns']['icon_set']['config'] = ['type' => 'select', 'renderType' => 'selectSingle', 'items' => $items, 'default' => $default];
        unset($tca);
    }

    private function setIconProcessor(string $processor): void
    {
        /** @var array{tx_childrenfixture_item: array{columns: array<string, array{config: array<string, mixed>}>}} $tca */
        $tca                                                            = &$GLOBALS['TCA'];
        $tca[self::TABLE]['columns']['icon']['config']['itemsProcFunc'] = $processor;
        unset($tca);
    }
}
