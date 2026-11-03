<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Form\FieldWizard;

use Netresearch\NrLlm\Form\ChoiceHelp\ChoiceSummaryProvider;
use Netresearch\NrLlm\Form\FieldWizard\ChoiceHelpWizard;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;

/**
 * The help element under a record select (ADR-255): what it puts on the page
 * for the options FormEngine offers.
 */
#[CoversClass(ChoiceHelpWizard::class)]
final class ChoiceHelpWizardTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/ChoiceSummaries.csv');
    }

    /**
     * @param list<array{label: string, value: int|string}> $items
     *
     * @return array<string, mixed>
     */
    private function render(array $items, mixed $value, string $foreignTable = 'tx_nrllm_configuration'): array
    {
        $wizard = new ChoiceHelpWizard(new ChoiceSummaryProvider($this->get(ConnectionPool::class)));
        $wizard->setData([
            'renderData'     => ['fieldWizardOptions' => []],
            'parameterArray' => [
                'itemFormElValue' => $value,
                'fieldConf'       => ['config' => ['type' => 'select', 'foreign_table' => $foreignTable, 'items' => $items]],
            ],
        ]);

        return $wizard->render();
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<int|string, array{description: string, facts: list<string>}>
     */
    private function map(array $result): array
    {
        $html = $result['html'] ?? '';
        self::assertIsString($html);
        self::assertSame(1, preg_match('/data-nrllm-choice-help="([^"]*)"/', $html, $match), $html);
        $map = json_decode(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($map);

        /** @var array<int|string, array{description: string, facts: list<string>}> $map */
        return $map;
    }

    #[Test]
    public function describesOnlyTheOfferedRecords(): void
    {
        $result = $this->render([['label' => 'default', 'value' => 0], ['label' => 'Short texts', 'value' => 21]], [21]);

        // 22, 23 and 24 exist but are not offered, so they are not on the page.
        self::assertSame([21], array_keys($this->map($result)));
    }

    #[Test]
    public function rendersTheChosenOptionAsTextAndTiesItToTheScript(): void
    {
        $result = $this->render([['label' => 'Short texts', 'value' => 21], ['label' => 'Markup', 'value' => 24]], '21');
        $html   = $result['html'];
        self::assertIsString($html);

        self::assertStringContainsString('aria-live="polite"', $html);
        self::assertStringContainsString('aria-atomic="true"', $html);
        self::assertStringContainsString('>Writes teasers and meta descriptions</span>', $html);
        self::assertStringContainsString('>Model: GPT mini (OpenAI)</span>', $html);

        $modules = $result['javaScriptModules'] ?? [];
        self::assertIsArray($modules);
        self::assertCount(1, $modules);
        self::assertInstanceOf(JavaScriptModuleInstruction::class, $modules[0]);
        self::assertSame('@netresearch/nr-llm/Backend/ChoiceHelp.js', $modules[0]->getName());
    }

    #[Test]
    public function aStoredDescriptionNeverBecomesMarkup(): void
    {
        $result = $this->render([['label' => 'Markup', 'value' => 24]], 24);
        $html   = $result['html'];
        self::assertIsString($html);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringContainsString('&lt;b&gt;bold&lt;/b&gt; &amp; &lt;script&gt;x&lt;/script&gt;', $html);
        // The map survives the attribute and decodes to the stored text.
        self::assertSame('<b>bold</b> & <script>x</script>', $this->map($result)[24]['description']);
    }

    #[Test]
    public function rendersNothingWhenNoOfferedRecordCanBeDescribed(): void
    {
        self::assertSame('', $this->render([['label' => 'default', 'value' => 0]], 0)['html']);
        self::assertSame('', $this->render([['label' => 'Admins', 'value' => 1]], 1, 'be_groups')['html']);
    }

    #[Test]
    public function aMultipleSelectStartsEmptyUnlessExactlyOneItemIsChosen(): void
    {
        $items = [['label' => 'Short texts', 'value' => 21], ['label' => 'Criteria', 'value' => 22]];

        $two = $this->render($items, [21, 22])['html'];
        self::assertIsString($two);
        self::assertStringEndsWith('"></div>', $two);

        $one = $this->render($items, [22])['html'];
        self::assertIsString($one);
        self::assertStringContainsString('>Picks its model by criteria when it runs</span>', $one);
    }
}
