<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\SpecializedTestController;
use Netresearch\NrLlm\Domain\Model\TranslationResult;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Service\Feature\TranslationServiceInterface;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\Image\ImageGenerationResult;
use Netresearch\NrLlm\Specialized\Image\ImageGeneratorInterface;
use Netresearch\NrLlm\Specialized\Translation\TranslatorInterface;
use Netresearch\NrLlm\Specialized\Translation\TranslatorResult;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * Unit tests for the specialized-service verification endpoints.
 */
#[AllowMockObjectsWithoutExpectations]
final class SpecializedTestControllerTest extends TestCase
{
    private TranslationServiceInterface&MockObject $translationService;

    private ImageGeneratorInterface&MockObject $openAiImage;

    private ImageGeneratorInterface&MockObject $falImage;

    private mixed $previousBeUser = null;

    protected function setUp(): void
    {
        parent::setUp();

        // The actions are guarded by RequiresBackendAdminTrait (ADR-037);
        // provide an admin so the tests reach the action body.
        $this->previousBeUser = $GLOBALS['BE_USER'] ?? null;
        $backendUser = new BackendUserAuthentication();
        $backendUser->user = ['uid' => 1, 'admin' => 1];
        $GLOBALS['BE_USER'] = $backendUser;

        $this->translationService = $this->createMock(TranslationServiceInterface::class);
        $this->openAiImage = $this->createMock(ImageGeneratorInterface::class);
        $this->falImage = $this->createMock(ImageGeneratorInterface::class);
    }

    protected function tearDown(): void
    {
        if ($this->previousBeUser === null) {
            unset($GLOBALS['BE_USER']);
        } else {
            $GLOBALS['BE_USER'] = $this->previousBeUser;
        }
        parent::tearDown();
    }

    #[Test]
    public function translationWithoutATranslatorUsesTheLlmPath(): void
    {
        $this->translationService
            ->expects(self::once())
            ->method('translate')
            ->with('Guten Tag', 'en', null)
            ->willReturn(new TranslationResult(
                translation: 'Good day',
                sourceLanguage: 'de',
                targetLanguage: 'en',
                confidence: 0.9,
                usage: new UsageStatistics(11, 4, 15),
            ));

        $body = $this->decode($this->subject()->translateAction(
            $this->request(['text' => 'Guten Tag', 'targetLanguage' => 'en']),
        ));

        self::assertTrue($body['success']);
        self::assertSame('Good day', $body['translation']);
        self::assertSame('llm', $body['translator']);
        self::assertSame(15, $body['usage']['totalTokens']);
    }

    #[Test]
    public function namingATranslatorRoutesToThatTranslator(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->expects(self::once())
            ->method('translate')
            ->with('Guten Tag', 'en', 'de')
            ->willReturn(new TranslatorResult(
                translatedText: 'Good day',
                sourceLanguage: 'de',
                targetLanguage: 'en',
                translator: 'deepl',
                confidence: 1.0,
                charactersUsed: 9,
            ));

        $this->translationService
            ->expects(self::once())
            ->method('getTranslator')
            ->with('deepl')
            ->willReturn($translator);

        $this->translationService->expects(self::never())->method('translate');

        $body = $this->decode($this->subject()->translateAction($this->request([
            'text'           => 'Guten Tag',
            'targetLanguage' => 'en',
            'sourceLanguage' => 'de',
            'translator'     => 'deepl',
        ])));

        self::assertTrue($body['success']);
        self::assertSame('deepl', $body['translator']);
        self::assertSame(9, $body['charactersUsed']);
    }

    #[Test]
    public function anUnconfiguredTranslatorIsReportedAsUnavailableNotAsAFailure(): void
    {
        $this->translationService
            ->method('getTranslator')
            ->willThrowException(ServiceUnavailableException::notConfigured('translation', 'deepl'));

        $response = $this->subject()->translateAction($this->request([
            'text'           => 'Guten Tag',
            'targetLanguage' => 'en',
            'translator'     => 'deepl',
        ]));

        self::assertSame(503, $response->getStatusCode());
        $body = $this->decode($response);
        self::assertFalse($body['success']);
        self::assertIsString($body['error']);
        self::assertStringContainsString('Extension Configuration', $body['error']);
    }

    #[Test]
    public function aRuntimeTranslationFailureIsNotDisguisedAsAConfigurationProblem(): void
    {
        $this->translationService
            ->method('translate')
            ->willThrowException(new RuntimeException('upstream exploded'));

        $response = $this->subject()->translateAction(
            $this->request(['text' => 'Guten Tag', 'targetLanguage' => 'en']),
        );

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('upstream exploded', (string)$response->getBody());
    }

    #[Test]
    public function translationRequiresTextAndTargetLanguage(): void
    {
        self::assertSame(400, $this->subject()->translateAction($this->request(['targetLanguage' => 'en']))->getStatusCode());
        self::assertSame(400, $this->subject()->translateAction($this->request(['text' => 'x']))->getStatusCode());
    }

    #[Test]
    public function translationRejectsOverlongInput(): void
    {
        $response = $this->subject()->translateAction($this->request([
            'text'           => str_repeat('a', 5001),
            'targetLanguage' => 'en',
        ]));

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function imageGenerationDefaultsToTheOpenAiService(): void
    {
        $this->openAiImage
            ->expects(self::once())
            ->method('generate')
            ->with('a cat')
            ->willReturn($this->imageResult(url: '', base64: base64_encode('binary')));

        $this->falImage->expects(self::never())->method('generate');

        $body = $this->decode($this->subject()->generateImageAction($this->request(['prompt' => 'a cat'])));

        self::assertTrue($body['success']);
        self::assertNull($body['url']);
        self::assertIsString($body['dataUrl']);
        self::assertStringStartsWith('data:image/png;base64,', $body['dataUrl']);
    }

    #[Test]
    public function requestingFalRoutesToTheFalService(): void
    {
        $this->falImage
            ->expects(self::once())
            ->method('generate')
            ->willReturn($this->imageResult(url: 'https://fal.example/img.png', base64: null));

        $this->openAiImage->expects(self::never())->method('generate');

        $body = $this->decode($this->subject()->generateImageAction(
            $this->request(['prompt' => 'a cat', 'service' => 'fal']),
        ));

        self::assertSame('https://fal.example/img.png', $body['url']);
        self::assertNull($body['dataUrl']);
    }

    #[Test]
    public function anUnconfiguredImageServiceIsReportedAsUnavailable(): void
    {
        $this->openAiImage
            ->method('generate')
            ->willThrowException(ServiceUnavailableException::notConfigured('image', 'dall-e'));

        $response = $this->subject()->generateImageAction($this->request(['prompt' => 'a cat']));

        self::assertSame(503, $response->getStatusCode());
    }

    #[Test]
    public function imageGenerationRequiresAPrompt(): void
    {
        self::assertSame(400, $this->subject()->generateImageAction($this->request([]))->getStatusCode());
    }

    #[Test]
    public function everyActionRefusesANonAdmin(): void
    {
        $editor = new BackendUserAuthentication();
        $editor->user = ['uid' => 42, 'admin' => 0];
        $GLOBALS['BE_USER'] = $editor;

        $subject = $this->subject();

        self::assertSame(403, $subject->translateAction($this->request(['text' => 'x', 'targetLanguage' => 'en']))->getStatusCode());
        self::assertSame(403, $subject->translatorsAction()->getStatusCode());
        self::assertSame(403, $subject->generateImageAction($this->request(['prompt' => 'x']))->getStatusCode());
    }

    #[Test]
    public function listingTranslatorsReportsWhichAreConfigured(): void
    {
        $this->translationService
            ->method('getAvailableTranslators')
            ->willReturn([
                'deepl' => ['identifier' => 'deepl', 'name' => 'DeepL', 'available' => false],
                'llm'   => ['identifier' => 'llm', 'name' => 'LLM', 'available' => true],
            ]);

        $body = $this->decode($this->subject()->translatorsAction());

        self::assertTrue($body['success']);
        self::assertIsArray($body['translators']);
        self::assertCount(2, $body['translators']);
        self::assertIsArray($body['translators'][0]);
        self::assertFalse($body['translators'][0]['available']);
    }

    private function subject(): SpecializedTestController
    {
        return new SpecializedTestController(
            $this->translationService,
            $this->openAiImage,
            $this->falImage,
            new NullLogger(),
        );
    }

    private function imageResult(string $url, ?string $base64): ImageGenerationResult
    {
        return new ImageGenerationResult(
            url: $url,
            base64: $base64,
            prompt: 'a cat',
            revisedPrompt: null,
            model: 'test-model',
            size: '1024x1024',
            provider: 'test',
        );
    }

    /**
     * @param array<string, string> $body
     */
    private function request(array $body): ServerRequest
    {
        return (new ServerRequest('/ajax/test', 'POST'))->withParsedBody($body);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string)$response->getBody(), true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
