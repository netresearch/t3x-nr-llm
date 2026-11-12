<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Integration;

use B13\AiLabel\Domain\Enum\AiOrigin;
use B13\AiLabel\Domain\Model\AiMetadata;
use DeepL\Translator;
use Netresearch\NrLlm\Integration\Autotranslate\AutotranslateTranslator;
use Netresearch\NrLlm\Integration\Autotranslate\LabelAutotranslatedRecord;
use Netresearch\NrLlm\Integration\Autotranslate\MarkAutotranslatedRecord;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use ThieleUndKlose\Autotranslate\Event\AfterTranslateEvent;
use ThieleUndKlose\Autotranslate\Utility\Records;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function is_string;

/**
 * The EXT:autotranslate integration in a real container (ADR-289): what
 * autotranslate resolves, and what its own write stores after the event.
 */
#[CoversClass(MarkAutotranslatedRecord::class)]
#[CoversClass(LabelAutotranslatedRecord::class)]
final class AutotranslateIntegrationTest extends AbstractFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'b13/ai-label',
        'thieleundklose/autotranslate',
        'netresearch/nr-llm',
    ];

    protected array $coreExtensionsToLoad = ['extbase', 'fluid'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('Autotranslate/Translation.csv');
        $this->routeThroughNrLlm(true);
    }

    #[Test]
    public function autotranslateGetsNrLlmsTranslatorFromTheContainer(): void
    {
        // The call autotranslate makes for the class its translatorClass names.
        $translator = GeneralUtility::getContainer()->get(AutotranslateTranslator::class);

        self::assertInstanceOf(Translator::class, $translator); // @phpstan-ignore classConstant.deprecatedClass (autotranslate's type)
    }

    #[Test]
    public function autotranslatesWriteStoresTheMarkAndTheLabelWithTheText(): void
    {
        $columns = $this->translate();

        Records::updateRecord('tt_content', 8, $columns);

        self::assertSame('<p>Hallo Welt.</p>', $this->stored('bodytext'));
        self::assertSame('1', (string) $this->stored('tx_nrllm_ai_written'));
        $label = AiMetadata::fromJsonString($this->stored(LabelAutotranslatedRecord::FIELD));
        self::assertSame(AiOrigin::Manipulated, $label->getOrigin(), 'A translation is AI-modified');
        self::assertFalse($label->isReviewed());
    }

    #[Test]
    public function anAiGeneratedOriginIsKeptAndItsReviewCleared(): void
    {
        $this->connection()->update(
            'tt_content',
            [LabelAutotranslatedRecord::FIELD => ['origin' => AiOrigin::Generated->value, 'reviewed_by' => 1, 'reviewed_timestamp' => 1234]],
            ['uid' => 8],
        );

        $label = AiMetadata::fromJsonString($this->labelIn($this->translate()));

        self::assertSame(AiOrigin::Generated, $label->getOrigin());
        self::assertSame(0, $label->getReviewedBy());
        self::assertSame(0, $label->getReviewedTimestamp());
    }

    #[Test]
    public function aTranslationOutsideNrLlmGetsNeither(): void
    {
        $this->routeThroughNrLlm(false);

        $columns = $this->translate();

        self::assertArrayNotHasKey('tx_nrllm_ai_written', $columns);
        self::assertArrayNotHasKey(LabelAutotranslatedRecord::FIELD, $columns);
    }

    private function routeThroughNrLlm(bool $on): void
    {
        /** @var array<string, array<string, array<string, mixed>>> $configuration */
        $configuration = $GLOBALS['TYPO3_CONF_VARS'];
        if ($on) {
            $configuration['EXTENSIONS']['autotranslate']['translatorClass'] = AutotranslateTranslator::class;
        } else {
            unset($configuration['EXTENSIONS']['autotranslate']['translatorClass']);
        }

        $GLOBALS['TYPO3_CONF_VARS'] = $configuration;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function translate(): array
    {
        $event = new AfterTranslateEvent(
            ['header' => 'Hallo', 'bodytext' => '<p>Hallo Welt.</p>'],
            ['uid' => 7, 'header' => 'Hello', 'bodytext' => '<p>Hello world.</p>'],
            1,
            'tt_content',
            8,
            'EN',
            'DE',
        );
        $this->getService(EventDispatcherInterface::class)->dispatch($event);

        return $event->getTranslatedColumns();
    }

    /**
     * @param array<array-key, mixed> $columns
     */
    private function labelIn(array $columns): ?string
    {
        $label = $columns[LabelAutotranslatedRecord::FIELD] ?? null;

        return is_string($label) ? $label : null;
    }

    private function stored(string $column): ?string
    {
        $value = $this->connection()->select([$column], 'tt_content', ['uid' => 8])->fetchOne();

        return is_string($value) || is_int($value) ? (string) $value : null;
    }

    private function connection(): Connection
    {
        return $this->getService(ConnectionPool::class)->getConnectionForTable('tt_content');
    }
}
