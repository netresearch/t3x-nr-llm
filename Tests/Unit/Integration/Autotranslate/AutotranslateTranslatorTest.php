<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Integration\Autotranslate;

use DeepL\ConnectionException;
use DeepL\Language;
use DeepL\TextResult;
use DeepL\TranslateTextOptions;
use Netresearch\NrLlm\Exception\RuntimeException;
use Netresearch\NrLlm\Integration\Autotranslate\AutotranslateSettings;
use Netresearch\NrLlm\Integration\Autotranslate\AutotranslateTranslator;
use Netresearch\NrLlm\Service\Feature\TranslationServiceInterface;
use Netresearch\NrLlm\Service\Option\TranslationOptions;
use Netresearch\NrLlm\Specialized\Translation\TranslatorResult;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrVault\Security\TechnicalActorContextInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

use function array_map;
use function array_slice;
use function array_values;
use function is_string;

#[CoversClass(AutotranslateTranslator::class)]
final class AutotranslateTranslatorTest extends AbstractUnitTestCase
{
    /** @var list<array{texts: array<mixed>, target: string, source: ?string, options: ?TranslationOptions}> */
    private array $calls = [];

    #[Test]
    public function sendsTheTextsInOneBatchAndAnswersEachInItsPlace(): void
    {
        $translator = $this->translator();

        $results = $translator->translateText(['Hello', '', 'World', '  '], 'EN', 'DE');

        self::assertCount(1, $this->calls);
        self::assertSame(['Hello', 'World'], $this->calls[0]['texts'], 'A blank text is not sent');
        self::assertSame(
            ['de:Hello', '', 'de:World', '  '],
            array_map(static fn (TextResult $result): string => $result->text, $results),
        );
    }

    #[Test]
    public function aSingleTextGetsASingleResult(): void
    {
        $result = $this->translator()->translateText('Hello', 'EN', 'DE');

        self::assertInstanceOf(TextResult::class, $result);
        self::assertSame('de:Hello', $result->text);
        self::assertSame('en', $result->detectedSourceLang);
        self::assertSame(5, $result->billedCharacters);
    }

    #[Test]
    public function onlyBlankTextsReachNoTranslator(): void
    {
        $results = $this->translator()->translateText(['', ' '], 'EN', 'DE');

        self::assertSame([], $this->calls);
        self::assertSame(['', ' '], array_map(static fn (TextResult $result): string => $result->text, $results));
    }

    #[Test]
    public function aRegionIsDroppedFromBothLanguages(): void
    {
        $this->translator()->translateText('Colour', 'EN-GB', 'PT-BR');

        self::assertSame('pt', $this->calls[0]['target']);
        self::assertSame('en', $this->calls[0]['source']);
    }

    #[Test]
    public function aMissingSourceLanguageIsLeftToDetection(): void
    {
        $this->translator()->translateText('Hello', null, 'DE');

        self::assertNull($this->calls[0]['source']);
    }

    #[Test]
    public function richTextKeepsItsMarkup(): void
    {
        $this->translator()->translateText('<p>Hello</p>', 'EN', 'DE', [TranslateTextOptions::TAG_HANDLING => 'html']);
        $this->translator()->translateText('Hello', 'EN', 'DE', [TranslateTextOptions::SPLIT_SENTENCES => true]);
        $this->translator()->translateText('Hello', 'EN', 'DE', [TranslateTextOptions::TAG_HANDLING => 'markdown']);

        self::assertSame('html', $this->calls[0]['options']?->getTagHandling());
        self::assertNull($this->calls[1]['options']?->getTagHandling());
        self::assertNull($this->calls[2]['options']?->getTagHandling(), 'A tag handling nr-llm does not know is not passed on');
    }

    #[Test]
    public function theConfiguredTranslatorDoesTheWork(): void
    {
        $this->translator(translator: 'deepl')->translateText('Hello', 'EN', 'DE');
        $this->translator()->translateText('Hello', 'EN', 'DE');

        self::assertSame('deepl', $this->calls[0]['options']?->getTranslator());
        self::assertNull($this->calls[1]['options']?->getTranslator(), 'Without a setting TranslationService chooses');
    }

    #[Test]
    public function aMissingAnswerStoresNothing(): void
    {
        $translator = $this->translator(answerCount: 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1798100001);

        $translator->translateText(['Hello', 'World'], 'EN', 'DE');
    }

    #[Test]
    public function onTheCommandLineTheKeyIsReadAsTheWorker(): void
    {
        $actor = $this->createMock(TechnicalActorContextInterface::class);
        $actor->expects(self::once())
            ->method('runAs')
            ->with(994)
            ->willReturnCallback(static fn (int $uid, callable $work): mixed => $work());

        $result = $this->translator(workerUid: 994, actor: $actor, commandLine: true)->translateText('Hello', 'EN', 'DE');

        self::assertSame('de:Hello', $result->text);
    }

    #[Test]
    public function withoutAWorkerOrOutsideTheCommandLineTheCallerReadsTheKey(): void
    {
        $actor = $this->createMock(TechnicalActorContextInterface::class);
        $actor->expects(self::never())->method('runAs');

        $this->translator(workerUid: 0, actor: $actor, commandLine: true)->translateText('Hello', 'EN', 'DE');
        $this->translator(workerUid: 994, actor: $actor, commandLine: false)->translateText('Hello', 'EN', 'DE');

        self::assertCount(2, $this->calls);
    }

    #[Test]
    public function reportsNoQuota(): void
    {
        self::assertNull($this->translator()->getUsage()->character);
    }

    #[Test]
    public function findsNoDeepLGlossary(): void
    {
        self::assertSame([], $this->translator()->listGlossaries());
    }

    #[Test]
    public function offersDeepLsLanguageCodes(): void
    {
        $translator = $this->translator();

        self::assertContains('EN', $this->codes($translator->getSourceLanguages()));
        self::assertContains('DE', $this->codes($translator->getTargetLanguages()));
        self::assertContains('EN-GB', $this->codes($translator->getTargetLanguages()));
        self::assertNotContains('EN', $this->codes($translator->getTargetLanguages()), 'DeepL wants a region for English as target');
    }

    #[Test]
    public function aMethodItDoesNotOverrideNeverReachesDeepL(): void
    {
        $this->expectException(ConnectionException::class);

        $this->translator()->getGlossaryLanguages(); // @phpstan-ignore method.deprecatedClass (the inherited client is what is tested)
    }

    /**
     * @param Language[] $languages
     *
     * @return list<string>
     */
    private function codes(array $languages): array
    {
        return array_values(array_map(static fn (Language $language): string => $language->code, $languages));
    }

    private function translator(
        string $translator = '',
        int $workerUid = 0,
        ?TechnicalActorContextInterface $actor = null,
        bool $commandLine = false,
        ?int $answerCount = null,
    ): AutotranslateTranslator {
        $service = self::createStub(TranslationServiceInterface::class);
        $service->method('translateBatchWithTranslator')->willReturnCallback(
            function (array $texts, string $target, ?string $source = null, ?TranslationOptions $options = null) use ($answerCount): array {
                $this->calls[] = ['texts' => $texts, 'target' => $target, 'source' => $source, 'options' => $options];
                $answers       = [];
                foreach ($texts as $text) {
                    $answers[] = new TranslatorResult($target . ':' . (is_string($text) ? $text : ''), $source ?? 'en', $target, 'llm');
                }

                return $answerCount === null ? $answers : array_slice($answers, 0, $answerCount);
            },
        );

        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturnCallback(
            static fn (string $extension, string $path = ''): mixed => match ($extension . '/' . $path) {
                'nr_llm/autotranslate' => ['translator' => $translator],
                'nr_llm/worker'        => ['technicalBeUserUid' => (string) $workerUid],
                default                => null,
            },
        );

        return new AutotranslateTranslator(
            $service,
            new AutotranslateSettings($configuration),
            $actor ?? self::createStub(TechnicalActorContextInterface::class),
            $commandLine,
        );
    }
}
