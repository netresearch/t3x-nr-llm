<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Translation;

use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Specialized\Exception\ServiceConfigurationException;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\Translation\DeepLTranslator;
use Netresearch\NrLlm\Specialized\Translation\TranslatorResult;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Client\ClientInterface;

#[CoversClass(DeepLTranslator::class)]
class DeepLTranslatorTest extends AbstractUnitTestCase
{
    private DeepLTranslator $subject;
    private ClientInterface&MockObject $httpClientMock;
    private UsageTrackerServiceInterface&MockObject $usageTrackerMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClientMock = $this->createHttpClientMock();
        $this->usageTrackerMock = $this->createMock(UsageTrackerServiceInterface::class);

        $config = [
            'translators' => [
                'deepl' => [
                    'apiKey' => $this->randomApiKey(),
                    'timeout' => 30,
                ],
            ],
        ];

        $this->subject = new DeepLTranslator(
            $this->httpClientMock,
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createExtensionConfigurationMock($config),
            $this->usageTrackerMock,
            $this->createLoggerMock(),
        );
    }

    #[Test]
    public function getIdentifierReturnsDeepL(): void
    {
        $this->assertEquals('deepl', $this->subject->getIdentifier());
    }

    #[Test]
    public function getNameReturnsDeepLTranslation(): void
    {
        $this->assertEquals('DeepL Translation', $this->subject->getName());
    }

    #[Test]
    public function isAvailableReturnsTrueWhenApiKeyConfigured(): void
    {
        $this->assertTrue($this->subject->isAvailable());
    }

    #[Test]
    public function isAvailableReturnsFalseWhenNoApiKey(): void
    {
        $config = [
            'translators' => [
                'deepl' => [
                    'apiKey' => '',
                ],
            ],
        ];

        $translator = new DeepLTranslator(
            $this->httpClientMock,
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createExtensionConfigurationMock($config),
            $this->usageTrackerMock,
            $this->createLoggerMock(),
        );

        $this->assertFalse($translator->isAvailable());
    }

    #[Test]
    public function translateReturnsValidResult(): void
    {
        $apiResponse = [
            'translations' => [
                [
                    'text' => 'Hallo Welt',
                    'detected_source_language' => 'EN',
                ],
            ],
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->translate('Hello World', 'de');

        $this->assertInstanceOf(TranslatorResult::class, $result);
        $this->assertEquals('Hallo Welt', $result->translatedText);
        $this->assertEquals('en', $result->sourceLanguage);
        $this->assertEquals('de', $result->targetLanguage);
        $this->assertEquals('deepl', $result->translator);
    }

    #[Test]
    public function translateWithSourceLanguage(): void
    {
        $apiResponse = [
            'translations' => [
                [
                    'text' => 'Bonjour le monde',
                    'detected_source_language' => 'EN',
                ],
            ],
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->translate('Hello World', 'fr', 'en');

        $this->assertEquals('Bonjour le monde', $result->translatedText);
        $this->assertEquals('fr', $result->targetLanguage);
    }

    #[Test]
    public function translateTracksUsage(): void
    {
        $text = 'Hello World';

        $apiResponse = [
            'translations' => [
                ['text' => 'Hallo Welt', 'detected_source_language' => 'EN'],
            ],
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $this->usageTrackerMock
            ->expects($this->once())
            ->method('trackUsage')
            ->with(
                'translation',
                'deepl',
                $this->callback(fn(array $data) => $data['characters'] === mb_strlen($text))
            );

        $this->subject->translate($text, 'de');
    }

    #[Test]
    public function translateThrowsWhenNotAvailable(): void
    {
        $config = ['translators' => ['deepl' => ['apiKey' => '']]];

        $translator = new DeepLTranslator(
            $this->httpClientMock,
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createExtensionConfigurationMock($config),
            $this->usageTrackerMock,
            $this->createLoggerMock(),
        );

        $this->expectException(ServiceUnavailableException::class);

        $translator->translate('Hello', 'de');
    }

    #[Test]
    public function translateBatchReturnsMultipleResults(): void
    {
        $apiResponse = [
            'translations' => [
                ['text' => 'Hallo', 'detected_source_language' => 'EN'],
                ['text' => 'Welt', 'detected_source_language' => 'EN'],
            ],
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $results = $this->subject->translateBatch(['Hello', 'World'], 'de');

        $this->assertCount(2, $results);
        $this->assertEquals('Hallo', $results[0]->translatedText);
        $this->assertEquals('Welt', $results[1]->translatedText);
    }

    #[Test]
    public function translateBatchReturnsEmptyForEmptyInput(): void
    {
        $results = $this->subject->translateBatch([], 'de');

        $this->assertEmpty($results);
    }

    #[Test]
    public function getSupportedLanguagesReturnsNonEmptyArray(): void
    {
        $languages = $this->subject->getSupportedLanguages();

        $this->assertIsArray($languages);
        $this->assertNotEmpty($languages);
        $this->assertContains('en', $languages);
        $this->assertContains('de', $languages);
        $this->assertContains('fr', $languages);
    }

    #[Test]
    public function detectLanguageReturnsLanguageCode(): void
    {
        $apiResponse = [
            'translations' => [
                ['text' => 'test', 'detected_source_language' => 'DE'],
            ],
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $detected = $this->subject->detectLanguage('Hallo Welt, wie geht es dir?');

        $this->assertEquals('de', $detected);
    }

    #[Test]
    #[DataProvider('languagePairProvider')]
    public function supportsLanguagePairValidatesCorrectly(
        string $source,
        string $target,
        bool $expected
    ): void {
        $result = $this->subject->supportsLanguagePair($source, $target);

        $this->assertEquals($expected, $result);
    }

    public static function languagePairProvider(): array
    {
        return [
            'en to de' => ['en', 'de', true],
            'de to fr' => ['de', 'fr', true],
            'en to en-gb' => ['en', 'en-gb', true],
            'invalid source' => ['xx', 'de', false],
            'invalid target' => ['en', 'xx', false],
        ];
    }

    #[Test]
    #[DataProvider('formalityLanguageProvider')]
    public function supportsFormalityReturnsCorrectValue(string $language, bool $expected): void
    {
        $result = $this->subject->supportsFormality($language);

        $this->assertEquals($expected, $result);
    }

    public static function formalityLanguageProvider(): array
    {
        return [
            'german supports' => ['de', true],
            'french supports' => ['fr', true],
            'spanish supports' => ['es', true],
            'english does not' => ['en', false],
            'chinese does not' => ['zh', false],
            'portuguese-br supports' => ['pt-br', true],
        ];
    }

    #[Test]
    public function getUsageReturnsUsageStatistics(): void
    {
        $apiResponse = [
            'character_count' => 50000,
            'character_limit' => 500000,
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $usage = $this->subject->getUsage();

        $this->assertEquals(50000, $usage['character_count']);
        $this->assertEquals(500000, $usage['character_limit']);
    }

    #[Test]
    public function getGlossariesReturnsGlossaryList(): void
    {
        $apiResponse = [
            'glossaries' => [
                [
                    'glossary_id' => 'gls_123',
                    'name' => 'Technical Terms',
                    'source_lang' => 'en',
                    'target_lang' => 'de',
                ],
            ],
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $glossaries = $this->subject->getGlossaries();

        $this->assertCount(1, $glossaries);
        $this->assertEquals('gls_123', $glossaries[0]['glossary_id']);
    }

    #[Test]
    public function translateHandles401Error(): void
    {
        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock(['error' => 'Unauthorized'], 401));

        $this->expectException(ServiceConfigurationException::class);

        $this->subject->translate('Hello', 'de');
    }

    #[Test]
    public function translateHandles429RateLimitError(): void
    {
        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock(['message' => 'Rate limit exceeded'], 429));

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('rate limit');

        $this->subject->translate('Hello', 'de');
    }

    #[Test]
    public function translateHandles456QuotaError(): void
    {
        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock(['message' => 'Quota exceeded'], 456));

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('quota exceeded');

        $this->subject->translate('Hello', 'de');
    }

    #[Test]
    public function translateThrowsOnEmptyResponse(): void
    {
        $apiResponse = ['translations' => []];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('empty translation');

        $this->subject->translate('Hello', 'de');
    }

    #[Test]
    public function resultIncludesMetadata(): void
    {
        $apiResponse = [
            'translations' => [
                ['text' => 'Hallo', 'detected_source_language' => 'EN'],
            ],
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->translate('Hello', 'de');

        $this->assertArrayHasKey('detected_source_language', $result->metadata);
        $this->assertArrayHasKey('billed_characters', $result->metadata);
    }

    #[Test]
    public function confidenceIsSetToHighValue(): void
    {
        $apiResponse = [
            'translations' => [
                ['text' => 'Hallo', 'detected_source_language' => 'EN'],
            ],
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->translate('Hello', 'de');

        $this->assertEquals(0.95, $result->confidence);
    }
}
