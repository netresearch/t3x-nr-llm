<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Document;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Provider\Contract\DocumentCapableInterface;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Service\Budget\BackendUserContextResolverInterface;
use Netresearch\NrLlm\Service\Feature\VisionServiceInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\Option\VisionOptions;
use Netresearch\NrLlm\Specialized\Document\DocumentAnalysisService;
use Netresearch\NrLlm\Specialized\Document\PdfRasterizerInterface;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\Exception\UnsupportedFormatException;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(DocumentAnalysisService::class)]
class DocumentAnalysisServiceTest extends AbstractUnitTestCase
{
    private const PDF = "%PDF-1.7\nfake-document-bytes";
    private const PROMPT = 'Summarize this document.';

    private LlmServiceManagerInterface&MockObject $llmManager;
    private VisionServiceInterface&MockObject $visionService;
    private PdfRasterizerInterface&MockObject $rasterizer;
    private DocumentAnalysisService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $this->visionService = $this->createMock(VisionServiceInterface::class);
        $this->rasterizer = $this->createMock(PdfRasterizerInterface::class);
        $this->subject = new DocumentAnalysisService(
            $this->llmManager,
            $this->visionService,
            $this->rasterizer,
        );
    }

    /**
     * @return (ProviderInterface&DocumentCapableInterface)&MockObject
     */
    private function createDocumentCapableProvider(
        string $identifier = 'gemini',
        bool $supportsDocuments = true,
    ): MockObject {
        $provider = $this->createMockForIntersectionOfInterfaces([
            ProviderInterface::class,
            DocumentCapableInterface::class,
        ]);
        $provider->method('getIdentifier')->willReturn($identifier);
        $provider->method('supportsDocuments')->willReturn($supportsDocuments);
        $provider->method('getSupportedDocumentFormats')->willReturn($supportsDocuments ? ['pdf'] : []);

        return $provider;
    }

    private function completionResponse(string $content, string $model = 'gemini-3-pro', string $provider = 'gemini'): CompletionResponse
    {
        return new CompletionResponse(
            content: $content,
            model: $model,
            usage: new UsageStatistics(10, 5, 15),
            provider: $provider,
        );
    }

    private function visionResponse(string $description): VisionResponse
    {
        return new VisionResponse(
            description: $description,
            model: 'gpt-5.2',
            usage: new UsageStatistics(10, 5, 15),
            provider: 'openai',
        );
    }

    #[Test]
    public function analyzeDocumentUsesNativePathWhenProviderIsDocumentCapable(): void
    {
        $this->llmManager->method('resolveEffectiveConfiguration')->willReturn(null);
        $this->llmManager->expects(self::once())->method('getProvider')->with(null)->willReturn($this->createDocumentCapableProvider());

        $capturedMessages = null;
        $capturedOptions = null;
        $this->llmManager
            ->expects(self::once())
            ->method('chat')
            ->willReturnCallback(function (array $messages, ChatOptions $options) use (&$capturedMessages, &$capturedOptions): CompletionResponse {
                $capturedMessages = $messages;
                $capturedOptions = $options;

                return $this->completionResponse('Whole-document summary');
            });

        $this->rasterizer->expects(self::never())->method('isAvailable');
        $this->rasterizer->expects(self::never())->method('renderDocument');
        $this->visionService->expects(self::never())->method('analyzeImageFull');

        $result = $this->subject->analyzeDocument(self::PDF, self::PROMPT);

        self::assertTrue($result->usedNativeDocumentPath);
        self::assertSame(0, $result->rasterizedPageCount);
        self::assertSame('Whole-document summary', $result->text);
        self::assertSame('gemini-3-pro', $result->model);
        self::assertSame('gemini', $result->provider);

        self::assertSame(
            [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => self::PROMPT,
                        ],
                        [
                            'type' => 'document',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => 'application/pdf',
                                'data' => base64_encode(self::PDF),
                            ],
                        ],
                    ],
                ],
            ],
            $capturedMessages,
        );
        self::assertInstanceOf(ChatOptions::class, $capturedOptions);
        self::assertNull($capturedOptions->getProvider());
    }

    #[Test]
    public function analyzeDocumentPassesOptionsThroughOnNativePath(): void
    {
        $this->llmManager->expects(self::once())->method('getProvider')->with('gemini')->willReturn($this->createDocumentCapableProvider());

        $capturedOptions = null;
        $this->llmManager
            ->expects(self::once())
            ->method('chat')
            ->willReturnCallback(function (array $messages, ChatOptions $options) use (&$capturedOptions): CompletionResponse {
                $capturedOptions = $options;

                return $this->completionResponse('ok');
            });

        $options = new ChatOptions(
            temperature: 0.3,
            maxTokens: 512,
            provider: 'gemini',
            model: 'gemini-3-pro',
            beUserUid: 7,
            plannedCost: 0.5,
        );

        $this->subject->analyzeDocument(self::PDF, self::PROMPT, $options);

        self::assertInstanceOf(ChatOptions::class, $capturedOptions);
        self::assertSame(0.3, $capturedOptions->getTemperature());
        self::assertSame(512, $capturedOptions->getMaxTokens());
        self::assertSame('gemini', $capturedOptions->getProvider());
        self::assertSame('gemini-3-pro', $capturedOptions->getModel());
        self::assertSame(7, $capturedOptions->getBeUserUid());
        self::assertSame(0.5, $capturedOptions->getPlannedCost());
    }

    #[Test]
    public function analyzeDocumentProbesDefaultConfigurationProvider(): void
    {
        $configuration = self::createStub(LlmConfiguration::class);
        $configuration->method('getProviderType')->willReturn('claude');

        $this->llmManager->method('resolveEffectiveConfiguration')->willReturn($configuration);
        $this->llmManager
            ->expects(self::once())
            ->method('getProvider')
            ->with('claude')
            ->willReturn($this->createDocumentCapableProvider('claude'));
        $this->llmManager->method('chat')->willReturn($this->completionResponse('ok', 'claude-opus-4-5', 'claude'));

        $result = $this->subject->analyzeDocument(self::PDF, self::PROMPT);

        self::assertTrue($result->usedNativeDocumentPath);
    }

    #[Test]
    public function analyzeDocumentDoesNotPinConfigurationResolvedProviderOnDispatch(): void
    {
        // The configuration-derived key is for the capability probe only. Pinning
        // it on dispatch would make chat() skip the default DB configuration
        // (ConfigurationResolver returns null for any pinned provider) and fall
        // to the ad-hoc registry without the record's credentials/model.
        $configuration = self::createStub(LlmConfiguration::class);
        $configuration->method('getProviderType')->willReturn('gemini');

        $this->llmManager->method('resolveEffectiveConfiguration')->willReturn($configuration);
        $this->llmManager->expects(self::once())->method('getProvider')->with('gemini')->willReturn($this->createDocumentCapableProvider());

        $capturedOptions = null;
        $this->llmManager
            ->expects(self::once())
            ->method('chat')
            ->willReturnCallback(function (array $messages, ChatOptions $options) use (&$capturedOptions): CompletionResponse {
                $capturedOptions = $options;

                return $this->completionResponse('ok');
            });

        $this->subject->analyzeDocument(self::PDF, self::PROMPT, new ChatOptions(maxTokens: 1024));

        self::assertInstanceOf(ChatOptions::class, $capturedOptions);
        self::assertNull($capturedOptions->getProvider());
        self::assertSame(1024, $capturedOptions->getMaxTokens());
    }

    #[Test]
    public function analyzeDocumentAutoPopulatesBeUserUidOnNativePath(): void
    {
        $resolver = self::createStub(BackendUserContextResolverInterface::class);
        $resolver->method('resolveBeUserUid')->willReturn(42);

        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('resolveEffectiveConfiguration')->willReturn(null);
        $llmManager->method('getProvider')->willReturn($this->createDocumentCapableProvider());

        $capturedOptions = null;
        $llmManager
            ->method('chat')
            ->willReturnCallback(function (array $messages, ChatOptions $options) use (&$capturedOptions): CompletionResponse {
                $capturedOptions = $options;

                return $this->completionResponse('ok');
            });

        $subject = new DocumentAnalysisService(
            $llmManager,
            $this->visionService,
            $this->rasterizer,
            $resolver,
        );

        $subject->analyzeDocument(self::PDF, self::PROMPT);

        self::assertInstanceOf(ChatOptions::class, $capturedOptions);
        self::assertSame(42, $capturedOptions->getBeUserUid());
    }

    #[Test]
    public function analyzeDocumentFallsBackToRasterizationForNonDocumentProvider(): void
    {
        $provider = self::createStub(ProviderInterface::class);
        $provider->method('getIdentifier')->willReturn('openai');

        $this->llmManager->method('resolveEffectiveConfiguration')->willReturn(null);
        $this->llmManager->method('getProvider')->willReturn($provider);
        $this->llmManager->expects(self::never())->method('chat');

        $this->rasterizer->method('isAvailable')->willReturn(true);
        $this->rasterizer
            ->expects(self::once())
            ->method('renderDocument')
            ->willReturnCallback(static function (string $path): array {
                // The temp file must carry the PDF bytes at rasterization time.
                self::assertSame(self::PDF, file_get_contents($path));

                return [1 => 'PNG-ONE', 2 => 'PNG-TWO'];
            });

        $capturedUris = [];
        $capturedPrompts = [];
        $this->visionService
            ->expects(self::exactly(2))
            ->method('analyzeImageFull')
            ->willReturnCallback(function (string $imageUrl, string $prompt) use (&$capturedUris, &$capturedPrompts): VisionResponse {
                $capturedUris[] = $imageUrl;
                $capturedPrompts[] = $prompt;

                return $this->visionResponse(sprintf('Answer for call %d', count($capturedUris)));
            });

        $result = $this->subject->analyzeDocument(self::PDF, self::PROMPT);

        self::assertFalse($result->usedNativeDocumentPath);
        self::assertSame(2, $result->rasterizedPageCount);
        self::assertSame("[Page 1]\nAnswer for call 1\n\n[Page 2]\nAnswer for call 2", $result->text);
        self::assertSame('gpt-5.2', $result->model);
        self::assertSame('openai', $result->provider);
        self::assertSame(
            [
                'data:image/png;base64,' . base64_encode('PNG-ONE'),
                'data:image/png;base64,' . base64_encode('PNG-TWO'),
            ],
            $capturedUris,
        );
        self::assertSame([self::PROMPT, self::PROMPT], $capturedPrompts);
    }

    #[Test]
    public function analyzeDocumentFallsBackWhenProviderDisablesDocumentSupport(): void
    {
        $this->llmManager->method('resolveEffectiveConfiguration')->willReturn(null);
        $this->llmManager->method('getProvider')->willReturn($this->createDocumentCapableProvider('gemini', false));
        $this->llmManager->expects(self::never())->method('chat');

        $this->rasterizer->method('isAvailable')->willReturn(true);
        $this->rasterizer->method('renderDocument')->willReturn([1 => 'PNG-ONE']);
        $this->visionService->method('analyzeImageFull')->willReturn($this->visionResponse('Page answer'));

        $result = $this->subject->analyzeDocument(self::PDF, self::PROMPT);

        self::assertFalse($result->usedNativeDocumentPath);
    }

    #[Test]
    public function analyzeDocumentThrowsTypedErrorWhenPopplerIsAbsent(): void
    {
        $provider = self::createStub(ProviderInterface::class);
        $provider->method('getIdentifier')->willReturn('openai');

        $this->llmManager->method('resolveEffectiveConfiguration')->willReturn(null);
        $this->llmManager->method('getProvider')->willReturn($provider);

        $this->rasterizer->method('isAvailable')->willReturn(false);
        $this->rasterizer->expects(self::never())->method('renderDocument');
        $this->visionService->expects(self::never())->method('analyzeImageFull');

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionCode(1784211009);
        $this->expectExceptionMessageMatches('/poppler/');

        $this->subject->analyzeDocument(self::PDF, self::PROMPT);
    }

    #[Test]
    public function analyzeDocumentPassesOptionsThroughToVisionFallback(): void
    {
        $provider = self::createStub(ProviderInterface::class);
        $provider->method('getIdentifier')->willReturn('openai');

        $this->llmManager->expects(self::once())->method('getProvider')->with('openai')->willReturn($provider);

        $this->rasterizer->method('isAvailable')->willReturn(true);
        $this->rasterizer->method('renderDocument')->willReturn([1 => 'PNG-ONE']);

        $capturedOptions = null;
        $this->visionService
            ->expects(self::once())
            ->method('analyzeImageFull')
            ->willReturnCallback(function (string $imageUrl, string $prompt, ?VisionOptions $options) use (&$capturedOptions): VisionResponse {
                $capturedOptions = $options;

                return $this->visionResponse('Page answer');
            });

        $options = new ChatOptions(
            temperature: 0.2,
            maxTokens: 256,
            provider: 'openai',
            model: 'gpt-5.2',
            beUserUid: 9,
            plannedCost: 1.25,
        );

        $this->subject->analyzeDocument(self::PDF, self::PROMPT, $options);

        self::assertInstanceOf(VisionOptions::class, $capturedOptions);
        self::assertSame(0.2, $capturedOptions->getTemperature());
        self::assertSame(256, $capturedOptions->getMaxTokens());
        self::assertSame('openai', $capturedOptions->getProvider());
        self::assertSame('gpt-5.2', $capturedOptions->getModel());
        self::assertSame(9, $capturedOptions->getBeUserUid());
        self::assertSame(1.25, $capturedOptions->getPlannedCost());
    }

    #[Test]
    public function analyzeDocumentRejectsNonPdfInput(): void
    {
        $this->llmManager->expects(self::never())->method('getProvider');
        $this->llmManager->expects(self::never())->method('chat');

        $this->expectException(UnsupportedFormatException::class);
        $this->expectExceptionCode(1784211010);

        $this->subject->analyzeDocument('not a pdf', self::PROMPT);
    }
}
