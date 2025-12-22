<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Feature;

use Netresearch\NrLlm\Service\Feature\VisionService;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\PromptTemplateService;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Domain\Model\RenderedPrompt;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for VisionService
 */
class VisionServiceTest extends TestCase
{
    private VisionService $subject;
    private LlmServiceManager|MockObject $llmManagerMock;
    private PromptTemplateService|MockObject $promptServiceMock;

    protected function setUp(): void
    {
        $this->llmManagerMock = $this->createMock(LlmServiceManager::class);
        $this->promptServiceMock = $this->createMock(PromptTemplateService::class);
        $this->subject = new VisionService($this->llmManagerMock, $this->promptServiceMock);
    }

    /**
     * @test
     */
    public function generateAltTextReturnsSingleString(): void
    {
        $imageUrl = 'https://example.com/image.jpg';
        $expectedAltText = 'A red barn in a green field';

        $this->mockPromptRendering('vision.alt_text');
        $this->mockVisionResponse($expectedAltText);

        $result = $this->subject->generateAltText($imageUrl);

        $this->assertEquals($expectedAltText, $result);
    }

    /**
     * @test
     */
    public function generateAltTextProcessesBatch(): void
    {
        $imageUrls = [
            'https://example.com/img1.jpg',
            'https://example.com/img2.jpg',
        ];

        $this->mockPromptRendering('vision.alt_text');
        $this->mockVisionResponse('Alt text 1');
        $this->mockVisionResponse('Alt text 2');

        $results = $this->subject->generateAltText($imageUrls);

        $this->assertIsArray($results);
        $this->assertCount(2, $results);
    }

    /**
     * @test
     */
    public function generateTitleUsesCorrectPrompt(): void
    {
        $imageUrl = 'https://example.com/image.jpg';

        $this->promptServiceMock
            ->expects($this->once())
            ->method('render')
            ->with('vision.seo_title')
            ->willReturn($this->createMockRenderedPrompt());

        $this->mockVisionResponse('SEO optimized title');

        $this->subject->generateTitle($imageUrl);
    }

    /**
     * @test
     */
    public function generateDescriptionUsesCorrectPrompt(): void
    {
        $imageUrl = 'https://example.com/image.jpg';

        $this->promptServiceMock
            ->expects($this->once())
            ->method('render')
            ->with('vision.description')
            ->willReturn($this->createMockRenderedPrompt());

        $this->mockVisionResponse('Detailed description');

        $this->subject->generateDescription($imageUrl);
    }

    /**
     * @test
     */
    public function analyzeImageWithCustomPrompt(): void
    {
        $imageUrl = 'https://example.com/chart.jpg';
        $customPrompt = 'What trends are shown in this chart?';
        $expectedAnalysis = 'The chart shows upward trends';

        $this->llmManagerMock
            ->expects($this->once())
            ->method('complete')
            ->with($this->callback(function ($options) use ($customPrompt) {
                $this->assertArrayHasKey('messages', $options);
                $messages = $options['messages'];
                $this->assertEquals('user', $messages[0]['role']);
                $content = $messages[0]['content'];
                $this->assertEquals($customPrompt, $content[0]['text']);
                $this->assertEquals('image_url', $content[1]['type']);
                return true;
            }))
            ->willReturn($this->createMockLlmResponse($expectedAnalysis));

        $result = $this->subject->analyzeImage($imageUrl, $customPrompt);

        $this->assertEquals($expectedAnalysis, $result);
    }

    /**
     * @test
     */
    public function analyzeImageFullReturnsVisionResponse(): void
    {
        $imageUrl = 'https://example.com/image.jpg';
        $analysis = 'Detailed analysis';

        $this->mockPromptRendering('vision.alt_text');
        $this->mockVisionResponse($analysis);

        $result = $this->subject->analyzeImageFull($imageUrl, 'vision.alt_text');

        $this->assertInstanceOf(VisionResponse::class, $result);
        $this->assertEquals($analysis, $result->getText());
    }

    /**
     * @test
     */
    public function throwsOnInvalidImageUrl(): void
    {
        $invalidUrl = 'not-a-valid-url';

        $this->mockPromptRendering('vision.alt_text');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid image URL');

        $this->subject->generateAltText($invalidUrl);
    }

    /**
     * @test
     */
    public function acceptsBase64DataUri(): void
    {
        $base64Uri = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

        $this->mockPromptRendering('vision.alt_text');
        $this->mockVisionResponse('Alt text');

        $result = $this->subject->generateAltText($base64Uri);

        $this->assertIsString($result);
    }

    /**
     * @test
     */
    public function appliesDetailLevelOption(): void
    {
        $imageUrl = 'https://example.com/image.jpg';

        $this->mockPromptRendering('vision.alt_text');

        $this->llmManagerMock
            ->expects($this->once())
            ->method('complete')
            ->with($this->callback(function ($options) {
                $imageUrl = $options['messages'][1]['content'][1]['image_url'];
                $this->assertEquals('high', $imageUrl['detail']);
                return true;
            }))
            ->willReturn($this->createMockLlmResponse('Alt text'));

        $this->subject->generateAltText($imageUrl, ['detail_level' => 'high']);
    }

    /**
     * Mock prompt rendering
     */
    private function mockPromptRendering(string $identifier): void
    {
        $this->promptServiceMock
            ->method('render')
            ->with($identifier)
            ->willReturn($this->createMockRenderedPrompt());
    }

    /**
     * Mock vision response
     */
    private function mockVisionResponse(string $content): void
    {
        $this->llmManagerMock
            ->method('complete')
            ->willReturn($this->createMockLlmResponse($content));
    }

    /**
     * Create mock rendered prompt
     */
    private function createMockRenderedPrompt(): RenderedPrompt
    {
        return new RenderedPrompt(
            systemPrompt: 'Test system prompt',
            userPrompt: 'Test user prompt',
            temperature: 0.5,
            maxTokens: 300
        );
    }

    /**
     * Create mock LLM response
     */
    private function createMockLlmResponse(string $content): object
    {
        return new class($content) {
            public function __construct(private string $content) {}

            public function getContent(): string
            {
                return $this->content;
            }

            public function getUsage(): array
            {
                return [
                    'prompt_tokens' => 100,
                    'completion_tokens' => 50,
                    'estimated_cost' => 0.002,
                ];
            }

            public function getMetadata(): ?array
            {
                return ['confidence' => 0.95];
            }
        };
    }
}
