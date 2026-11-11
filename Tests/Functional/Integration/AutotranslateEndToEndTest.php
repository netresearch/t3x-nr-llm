<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Integration;

use B13\AiLabel\Domain\Enum\AiOrigin;
use B13\AiLabel\Domain\Model\AiMetadata;
use Netresearch\NrLlm\Integration\Autotranslate\AutotranslateTranslator;
use Netresearch\NrLlm\Integration\Autotranslate\LabelAutotranslatedRecord;
use Netresearch\NrLlm\Integration\Autotranslate\MarkAutotranslatedRecord;
use Netresearch\NrLlm\Specialized\Translation\TranslatorRegistry;
use Netresearch\NrLlm\Tests\Fixtures\Translation\RecordingTranslator;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use ThieleUndKlose\Autotranslate\Utility\DeeplApiHelper;
use ThieleUndKlose\Autotranslate\Utility\Translator;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function is_string;
use function method_exists;

/**
 * One translation from autotranslate's own entry point through nr-llm's
 * translator and TranslationService to the row autotranslate stores
 * (ADR-289). Only a translator at the end of the chain is a test double.
 *
 * Needs an autotranslate that reads `translatorClass`
 * (thieleundklose/tk-typo3-autotranslate#175); against one without it the test
 * is skipped.
 */
#[CoversClass(AutotranslateTranslator::class)]
#[CoversClass(MarkAutotranslatedRecord::class)]
#[CoversClass(LabelAutotranslatedRecord::class)]
final class AutotranslateEndToEndTest extends AbstractFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'b13/ai-label',
        'thieleundklose/autotranslate',
        'netresearch/nr-llm',
    ];

    protected array $coreExtensionsToLoad = ['extbase', 'fluid'];

    private RecordingTranslator $recording;

    protected function setUp(): void
    {
        parent::setUp();
        if (!method_exists(DeeplApiHelper::class, 'hasConfiguredTranslator')) {
            self::markTestSkipped('The installed autotranslate does not read translatorClass (thieleundklose/tk-typo3-autotranslate#175).');
        }

        $this->importFixture('Autotranslate/EndToEnd.csv');
        $this->writeSiteConfiguration();
        $this->configure();

        $this->recording = new RecordingTranslator('recording', 'Recording');
        $registry        = $this->getService(TranslatorRegistry::class);
        $translators     = new ReflectionProperty(TranslatorRegistry::class, 'translators');
        $registered      = $translators->getValue($registry);
        self::assertIsArray($registered);
        $registered['recording'] = $this->recording;
        $translators->setValue($registry, $registered);

        $user            = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->createFromUserPreferences($user);
    }

    #[Test]
    public function autotranslateStoresNrLlmsTranslationWithTheMarkAndTheLabel(): void
    {
        $result = GeneralUtility::makeInstance(Translator::class, 1)
            ->translateWithResult('tt_content', 10, null, '1', Translator::TRANSLATE_MODE_BOTH);

        self::assertFalse($result->hasErrors(), $result->getErrorSummary());
        $row = $this->translation();
        self::assertSame('[de] Hello', $row['header']);
        self::assertSame('[de] <p>Hello <strong>world</strong>.</p>', $row['bodytext']);
        self::assertSame(1, (int) $row['tx_nrllm_ai_written']);
        $label = AiMetadata::fromJsonString(is_string($row[LabelAutotranslatedRecord::FIELD]) ? $row[LabelAutotranslatedRecord::FIELD] : null);
        self::assertSame(AiOrigin::Manipulated, $label->getOrigin());

        $tagHandling = [];
        foreach ($this->recording->calls as $call) {
            self::assertSame('de', $call['target']);
            self::assertSame('en', $call['source']);
            $tagHandling[$call['text']] = $call['options']['tag_handling'] ?? null;
        }

        self::assertSame(
            ['Hello' => null, '<p>Hello <strong>world</strong>.</p>' => 'html'],
            $tagHandling,
            'Rich text goes as HTML, a plain field does not',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function translation(): array
    {
        // The localized copy is hidden (hideAtCopy), so no default restriction.
        $queryBuilder = $this->getService(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('*')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('l18n_parent', $queryBuilder->createNamedParameter(10, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($row, 'autotranslate created no German record');

        return $row;
    }

    private function configure(): void
    {
        /** @var array<string, array<string, array<string, mixed>>> $configuration */
        $configuration                                                   = $GLOBALS['TYPO3_CONF_VARS'];
        $configuration['EXTENSIONS']['autotranslate']['translatorClass'] = AutotranslateTranslator::class;
        $configuration['EXTENSIONS']['autotranslate']['fieldsToCopy']    = '';
        $nrLlm                                                           = $configuration['EXTENSIONS']['nr_llm'] ?? [];
        $nrLlm['autotranslate']                                          = ['translator' => 'recording'];
        $configuration['EXTENSIONS']['nr_llm']                           = $nrLlm;
        $GLOBALS['TYPO3_CONF_VARS']                                      = $configuration;

        // A text element's body text is rich text, as fluid_styled_content
        // declares it; that extension is not loaded here.
        $tca = $GLOBALS['TCA'];
        self::assertIsArray($tca);
        $GLOBALS['TCA'] = ArrayUtility::setValueByPath($tca, 'tt_content/types/text/columnsOverrides/bodytext/config/enableRichtext', true);
    }

    private function writeSiteConfiguration(): void
    {
        $directory = Environment::getConfigPath() . '/sites/autotranslate';
        GeneralUtility::mkdir_deep($directory);
        GeneralUtility::writeFile($directory . '/config.yaml', implode("\n", [
            'rootPageId: 1',
            "base: 'http://localhost/'",
            'languages:',
            '  -',
            '    languageId: 0',
            '    title: English',
            '    locale: en_US.UTF-8',
            '    base: /',
            '    deeplSourceLang: EN',
            '  -',
            '    languageId: 1',
            '    title: Deutsch',
            '    locale: de_DE.UTF-8',
            '    base: /de/',
            '    deeplTargetLang: DE',
            'autotranslateTtContentEnabled: 1',
            "autotranslateTtContentLanguages: '1'",
            "autotranslateTtContentTextfields: 'header,bodytext'",
            '',
        ]));
    }
}
