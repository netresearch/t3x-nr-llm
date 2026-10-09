<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Service\Tool\FieldMeasurer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * The count and the configured range of a structured preview (ADR-214, item
 * 9). Ranges come from the configuration, the count from the plain text.
 */
#[CoversClass(FieldMeasurer::class)]
final class FieldMeasurerTest extends TestCase
{
    /**
     * The shipped default: the meta description, 140 to 160, and nothing else.
     */
    #[Test]
    public function withoutConfigurationOnlyTheDescriptionHasTheShippedRange(): void
    {
        $measurer = new FieldMeasurer();

        self::assertSame([140, 160], $measurer->rangeFor('pages', 'description'));
        self::assertNull($measurer->rangeFor('pages', 'title'));
        self::assertNull($measurer->rangeFor('pages', 'og_description'));
        self::assertNull($measurer->rangeFor('tt_content', 'bodytext'));
    }

    /**
     * An absent key and configuration that cannot be read both mean "the
     * shipped default": an installation that never saved its settings keeps
     * the range it was shipped with.
     */
    #[Test]
    public function anAbsentKeyAndUnreadableConfigurationKeepTheShippedRange(): void
    {
        self::assertSame([140, 160], $this->measurer(['tools' => []])->rangeFor('pages', 'description'));

        $broken = self::createStub(ExtensionConfiguration::class);
        $broken->method('get')->willThrowException(new RuntimeException('not configured', 1791700102));
        self::assertSame([140, 160], (new FieldMeasurer($broken))->rangeFor('pages', 'description'));
    }

    #[Test]
    public function anEmptyValueRemovesEveryRangeIncludingTheDefault(): void
    {
        self::assertNull($this->configured('')->rangeFor('pages', 'description'));
    }

    /**
     * A configured entry replaces the default list; a malformed entry and an
     * inverted range give their field nothing and leave the others standing.
     */
    #[Test]
    public function configuredEntriesAreReadAndBrokenOnesIgnored(): void
    {
        $measurer = $this->configured(' pages.title:30-60 , tt_content.bodytext:200-400, pages.seo_title:60-30, nonsense, pages.abstract:-5-10');

        self::assertSame([30, 60], $measurer->rangeFor('pages', 'title'));
        self::assertSame([200, 400], $measurer->rangeFor('tt_content', 'bodytext'));
        self::assertNull($measurer->rangeFor('pages', 'seo_title'));
        self::assertNull($measurer->rangeFor('pages', 'abstract'));
        // The operator's list is the whole list: the default is not merged in.
        self::assertNull($measurer->rangeFor('pages', 'description'));
    }

    /**
     * Counted in characters with mb_strlen, carrying the configured range.
     */
    #[Test]
    public function aConfiguredFieldCarriesItsCountAndRange(): void
    {
        $measure = (new FieldMeasurer())->measure('pages', 'description', 'Größe: ½ €', false);

        self::assertNotNull($measure);
        self::assertSame(['count' => 10, 'min' => 140, 'max' => 160], $measure->toArray());
    }

    /**
     * Without a range, the count alone appears for a single-line or meta field
     * and nothing at all for any other field.
     */
    #[Test]
    public function withoutARangeTheCountAppearsOnlyWhereTheCallerSaysItMeansSomething(): void
    {
        $measurer = new FieldMeasurer();

        self::assertSame(['count' => 5, 'min' => null, 'max' => null], $measurer->measure('pages', 'title', 'Start', true)?->toArray());
        self::assertNull($measurer->measure('tt_content', 'bodytext', '<p>Body</p>', false, true));
    }

    /**
     * Rich text is counted as the text a reader sees: tags out, entities
     * decoded. A plain field counts every character, angle brackets included.
     */
    #[Test]
    public function richTextIsCountedWithoutItsMarkup(): void
    {
        $measurer = $this->configured('tt_content.bodytext:1-50,tt_content.header:1-50');

        self::assertSame(5, $measurer->measure('tt_content', 'bodytext', '<p>A &amp; <b>B</b></p>', false, true)?->count);
        self::assertSame(11, $measurer->measure('tt_content', 'header', '<b>Bold</b>', true)?->count);
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function measurer(array $configuration): FieldMeasurer
    {
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($configuration);

        return new FieldMeasurer($extensionConfiguration);
    }

    private function configured(string $ranges): FieldMeasurer
    {
        return $this->measurer(['tools' => ['structuredPreview' => ['ranges' => $ranges]]]);
    }
}
