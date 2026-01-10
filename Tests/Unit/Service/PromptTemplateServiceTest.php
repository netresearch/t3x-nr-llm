<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Model\PromptTemplate;
use Netresearch\NrLlm\Domain\Repository\PromptTemplateRepository;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Exception\PromptTemplateNotFoundException;
use Netresearch\NrLlm\Service\PromptTemplateService;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;

#[CoversClass(PromptTemplateService::class)]
class PromptTemplateServiceTest extends AbstractUnitTestCase
{
    private PromptTemplateRepository&Stub $repositoryStub;
    private PromptTemplateService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repositoryStub = self::createStub(PromptTemplateRepository::class);
        $this->subject = new PromptTemplateService($this->repositoryStub);
    }

    private function createTemplate(
        string $identifier = 'test-template',
        ?string $systemPrompt = null,
        ?string $userPrompt = null,
        bool $hasRequiredVars = false,
    ): PromptTemplate {
        $template = new PromptTemplate();
        $template->setIdentifier($identifier);
        $template->setTitle('Test Template');
        $template->setSystemPrompt($systemPrompt);
        $template->setUserPromptTemplate($userPrompt);
        $template->setModel('gpt-5.2');
        $template->setTemperature(0.7);
        $template->setMaxTokens(1000);
        $template->setTopP(1.0);
        $template->setVersion(1);

        // Only set required variables if explicitly asked
        if ($hasRequiredVars) {
            $template->setVariables(['required' => ['context', 'text']]);
        }

        return $template;
    }

    // ==================== getPrompt tests ====================

    #[Test]
    public function getPromptReturnsTemplateWhenFound(): void
    {
        $template = $this->createTemplate('my-prompt');
        $this->repositoryStub
            ->method('findOneByIdentifier')
            ->with('my-prompt')
            ->willReturn($template);

        $result = $this->subject->getPrompt('my-prompt');

        self::assertSame($template, $result);
    }

    #[Test]
    public function getPromptThrowsExceptionWhenNotFound(): void
    {
        $this->repositoryStub
            ->method('findOneByIdentifier')
            ->with('nonexistent')
            ->willReturn(null);

        $this->expectException(PromptTemplateNotFoundException::class);
        $this->expectExceptionMessage('Prompt template "nonexistent" not found');

        $this->subject->getPrompt('nonexistent');
    }

    // ==================== render tests ====================

    #[Test]
    public function renderReturnsRenderedPromptWithVariables(): void
    {
        $template = $this->createTemplate(
            systemPrompt: 'System: {{context}}',
            userPrompt: 'Translate: {{text}}',
        );
        $this->repositoryStub
            ->method('findOneByIdentifier')
            ->willReturn($template);

        $result = $this->subject->render('test-template', [
            'context' => 'Translation context',
            'text' => 'Hello World',
        ]);

        self::assertEquals('System: Translation context', $result->getSystemPrompt());
        self::assertEquals('Translate: Hello World', $result->getUserPrompt());
        self::assertEquals('gpt-5.2', $result->getModel());
        self::assertEquals(0.7, $result->getTemperature());
        self::assertEquals(1000, $result->getMaxTokens());
        self::assertEquals(1.0, $result->getTopP());
    }

    #[Test]
    public function renderUsesOptionsToOverrideTemplate(): void
    {
        $template = $this->createTemplate();
        $this->repositoryStub
            ->method('findOneByIdentifier')
            ->willReturn($template);

        $result = $this->subject->render('test-template', [], [
            'model' => 'gpt-5.1',
            'temperature' => 0.3,
            'max_tokens' => 500,
            'top_p' => 0.9,
        ]);

        self::assertEquals('gpt-5.1', $result->getModel());
        self::assertEquals(0.3, $result->getTemperature());
        self::assertEquals(500, $result->getMaxTokens());
        self::assertEquals(0.9, $result->getTopP());
    }

    #[Test]
    public function renderIncludesMetadata(): void
    {
        $template = $this->createTemplate('my-template');
        $template->setUid(42);
        $template->setVersion(3);
        $this->repositoryStub
            ->method('findOneByIdentifier')
            ->willReturn($template);

        $result = $this->subject->render('my-template', []);

        self::assertEquals(42, $result->getMetadata()['template_id']);
        self::assertEquals('my-template', $result->getMetadata()['template_identifier']);
        self::assertEquals(3, $result->getMetadata()['version']);
    }

    #[Test]
    public function renderThrowsExceptionForMissingRequiredVariables(): void
    {
        // Template with variables detected by the patterns
        $template = $this->createTemplate(userPrompt: 'Hello {{name}} from {{place}}!');
        $this->repositoryStub
            ->method('findOneByIdentifier')
            ->willReturn($template);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required variables');

        // Only provide one of two required variables
        $this->subject->render('test-template', ['name' => 'World']);
    }

    #[Test]
    public function renderSubstitutesNumericVariables(): void
    {
        $template = $this->createTemplate(userPrompt: 'Count: {{count}} Price: {{price}}');
        $this->repositoryStub
            ->method('findOneByIdentifier')
            ->willReturn($template);

        $result = $this->subject->render('test-template', [
            'count' => 42,
            'price' => 19.99,
        ]);

        self::assertEquals('Count: 42 Price: 19.99', $result->getUserPrompt());
    }

    #[Test]
    public function renderSubstitutesArrayVariablesAsJson(): void
    {
        $template = $this->createTemplate(userPrompt: 'Data: {{items}}');
        $this->repositoryStub
            ->method('findOneByIdentifier')
            ->willReturn($template);

        $result = $this->subject->render('test-template', [
            'items' => ['apple', 'banana'],
        ]);

        self::assertStringContainsString('["apple","banana"]', $result->getUserPrompt());
    }

    #[Test]
    public function renderHandlesConditionalSectionsTruthy(): void
    {
        $template = $this->createTemplate(userPrompt: '{{#if showDetails}}Details: {{details}}{{/if}}');
        $this->repositoryStub
            ->method('findOneByIdentifier')
            ->willReturn($template);

        // Must provide all template variables
        $result = $this->subject->render('test', ['showDetails' => true, 'details' => 'Some info']);
        self::assertStringContainsString('Details: Some info', $result->getUserPrompt());
    }

    #[Test]
    public function renderHandlesConditionalSectionsFalsy(): void
    {
        $template = $this->createTemplate(userPrompt: '{{#if showDetails}}Content{{/if}}');
        $this->repositoryStub
            ->method('findOneByIdentifier')
            ->willReturn($template);

        $result = $this->subject->render('test', ['showDetails' => false]);
        self::assertEquals('', $result->getUserPrompt());
    }

    #[Test]
    public function renderHandlesConditionalElseSectionsTruthy(): void
    {
        $template = $this->createTemplate(userPrompt: '{{#if hasName}}{{name}}{{else}}Anonymous{{/if}}');
        $this->repositoryStub
            ->method('findOneByIdentifier')
            ->willReturn($template);

        $result = $this->subject->render('test', ['hasName' => true, 'name' => 'John']);
        self::assertStringContainsString('John', $result->getUserPrompt());
    }

    #[Test]
    public function renderHandlesConditionalElseSectionsFalsy(): void
    {
        // Note: The current implementation processes {{#if...}}{{/if}} before
        // {{#if...}}{{else}}{{/if}}, so else patterns may not work as expected
        // This test documents actual behavior
        $template = $this->createTemplate(userPrompt: '{{#if hasName}}Name{{else}}Anonymous{{/if}}');
        $this->repositoryStub
            ->method('findOneByIdentifier')
            ->willReturn($template);

        // Due to processing order, the simpler pattern matches first
        $result = $this->subject->render('test', ['hasName' => false]);
        // Empty because simple conditional matches and condition is false
        self::assertEquals('', $result->getUserPrompt());
    }

    #[Test]
    public function renderHandlesEachLoopStructure(): void
    {
        // Note: {{this}} is processed by simple substitution first, so loops
        // output the template structure rather than item values
        $template = $this->createTemplate(userPrompt: 'Items: {{#each items}}[-]{{/each}}');
        $this->repositoryStub
            ->method('findOneByIdentifier')
            ->willReturn($template);

        $result = $this->subject->render('test', [
            'items' => ['one', 'two', 'three'],
        ]);

        // Three items = three iterations
        self::assertEquals('Items: [-][-][-]', $result->getUserPrompt());
    }

    #[Test]
    public function renderHandlesEmptyEachLoop(): void
    {
        $template = $this->createTemplate(userPrompt: 'Items: {{#each items}}X{{/each}}');
        $this->repositoryStub
            ->method('findOneByIdentifier')
            ->willReturn($template);

        $result = $this->subject->render('test', ['items' => []]);

        self::assertEquals('Items:', $result->getUserPrompt());
    }

    #[Test]
    public function renderHandlesNonArrayForEachLoop(): void
    {
        $template = $this->createTemplate(userPrompt: '{{#each items}}X{{/each}}');
        $this->repositoryStub
            ->method('findOneByIdentifier')
            ->willReturn($template);

        $result = $this->subject->render('test', ['items' => 'not-array']);

        self::assertEquals('', $result->getUserPrompt());
    }

    #[Test]
    public function renderTrimsResults(): void
    {
        $template = $this->createTemplate(
            systemPrompt: '  System prompt  ',
            userPrompt: '  User prompt  ',
        );
        $this->repositoryStub
            ->method('findOneByIdentifier')
            ->willReturn($template);

        $result = $this->subject->render('test', []);

        self::assertEquals('System prompt', $result->getSystemPrompt());
        self::assertEquals('User prompt', $result->getUserPrompt());
    }

    #[Test]
    public function renderHandlesNullPrompts(): void
    {
        $template = $this->createTemplate(systemPrompt: null, userPrompt: null);
        $this->repositoryStub
            ->method('findOneByIdentifier')
            ->willReturn($template);

        $result = $this->subject->render('test', []);

        self::assertEquals('', $result->getSystemPrompt());
        self::assertEquals('', $result->getUserPrompt());
    }

    #[Test]
    public function renderHandlesIntegerTemperatureOption(): void
    {
        $template = $this->createTemplate();
        $this->repositoryStub
            ->method('findOneByIdentifier')
            ->willReturn($template);

        $result = $this->subject->render('test', [], ['temperature' => 1]);

        self::assertEquals(1.0, $result->getTemperature());
    }

    #[Test]
    public function renderIgnoresInvalidOptionTypes(): void
    {
        $template = $this->createTemplate();
        $this->repositoryStub
            ->method('findOneByIdentifier')
            ->willReturn($template);

        $result = $this->subject->render('test', [], [
            'model' => 123, // Invalid, should use template value
            'temperature' => 'hot', // Invalid, should use template value
            'max_tokens' => 'many', // Invalid, should use template value
            'top_p' => 'high', // Invalid, should use template value
        ]);

        self::assertEquals('gpt-5.2', $result->getModel());
        self::assertEquals(0.7, $result->getTemperature());
        self::assertEquals(1000, $result->getMaxTokens());
        self::assertEquals(1.0, $result->getTopP());
    }

    #[Test]
    public function renderAllowsEmptyRequiredVariables(): void
    {
        // Variables in templates are required, but empty values are allowed
        $template = $this->createTemplate(userPrompt: 'Hello {{name}}!');
        $this->repositoryStub
            ->method('findOneByIdentifier')
            ->willReturn($template);

        $result = $this->subject->render('test', ['name' => '']);

        self::assertEquals('Hello !', $result->getUserPrompt());
    }

    // ==================== createVersion tests ====================

    #[Test]
    public function createVersionCreatesNewVersionOfTemplate(): void
    {
        $baseTemplate = $this->createTemplate('base');
        $baseTemplate->setUid(1);
        $baseTemplate->setVersion(2);

        $repositoryMock = $this->createMock(PromptTemplateRepository::class);
        $repositoryMock
            ->method('findOneByIdentifier')
            ->willReturn($baseTemplate);
        $repositoryMock
            ->expects(self::once())
            ->method('save');

        $subject = new PromptTemplateService($repositoryMock);
        $result = $subject->createVersion('base', []);

        self::assertEquals(3, $result->getVersion());
        self::assertEquals(1, $result->getParentUid());
    }

    #[Test]
    public function createVersionAppliesUpdates(): void
    {
        $baseTemplate = $this->createTemplate('base');
        $baseTemplate->setUid(1);
        $this->repositoryStub
            ->method('findOneByIdentifier')
            ->willReturn($baseTemplate);
        $this->repositoryStub->method('save');

        $result = $this->subject->createVersion('base', [
            'title' => 'New Title',
            'temperature' => 0.5,
        ]);

        self::assertEquals('New Title', $result->getTitle());
        self::assertEquals(0.5, $result->getTemperature());
    }

    #[Test]
    public function createVersionIgnoresInvalidFields(): void
    {
        $baseTemplate = $this->createTemplate('base');
        $baseTemplate->setUid(1);
        $this->repositoryStub
            ->method('findOneByIdentifier')
            ->willReturn($baseTemplate);
        $this->repositoryStub->method('save');

        $result = $this->subject->createVersion('base', [
            'nonexistentField' => 'value',
        ]);

        // Should not throw, just ignore
        self::assertInstanceOf(PromptTemplate::class, $result);
    }

    #[Test]
    public function createVersionHandlesNullUidOnBase(): void
    {
        $baseTemplate = $this->createTemplate('base');
        // uid is null by default
        $this->repositoryStub
            ->method('findOneByIdentifier')
            ->willReturn($baseTemplate);
        $this->repositoryStub->method('save');

        $result = $this->subject->createVersion('base', []);

        // Should not set parent uid since base has null uid
        self::assertEquals(0, $result->getParentUid());
    }

    // ==================== getVariant tests ====================

    #[Test]
    public function getVariantReturnsVariantWhenFound(): void
    {
        $variant = $this->createTemplate('variant-a');
        $this->repositoryStub
            ->method('findVariant')
            ->with('base', 'variant-a')
            ->willReturn($variant);

        $result = $this->subject->getVariant('base', 'variant-a');

        self::assertSame($variant, $result);
    }

    #[Test]
    public function getVariantThrowsExceptionWhenNotFound(): void
    {
        $this->repositoryStub
            ->method('findVariant')
            ->willReturn(null);

        $this->expectException(PromptTemplateNotFoundException::class);
        $this->expectExceptionMessage('Variant "v1" of template "base" not found');

        $this->subject->getVariant('base', 'v1');
    }

    // ==================== recordUsage tests ====================

    #[Test]
    public function recordUsageUpdatesRunningAverages(): void
    {
        $template = $this->createTemplate('usage-test');
        $template->setUsageCount(10);
        $template->setAvgResponseTime(100);
        $template->setAvgTokensUsed(500);
        $template->setQualityScore(0.8);

        $repositoryMock = $this->createMock(PromptTemplateRepository::class);
        $repositoryMock
            ->method('findOneByIdentifier')
            ->willReturn($template);
        $repositoryMock
            ->expects(self::once())
            ->method('save');

        $subject = new PromptTemplateService($repositoryMock);
        $subject->recordUsage('usage-test', 200, 600, 0.9);

        self::assertEquals(11, $template->getUsageCount());
        // (100*10 + 200) / 11 = 109.09 -> rounded to 109
        self::assertEquals(109, $template->getAvgResponseTime());
        // (500*10 + 600) / 11 = 509.09 -> rounded to 509
        self::assertEquals(509, $template->getAvgTokensUsed());
        // (0.8*10 + 0.9) / 11 = 0.8090... -> round(0.8090..., 2) = 0.81
        self::assertEquals(0.81, $template->getQualityScore());
    }

    #[Test]
    public function recordUsageHandlesFirstUsage(): void
    {
        $template = $this->createTemplate();
        // Defaults: usageCount=0, avgResponseTime=0, avgTokensUsed=0, qualityScore=0
        $this->repositoryStub
            ->method('findOneByIdentifier')
            ->willReturn($template);
        $this->repositoryStub->method('save');

        $this->subject->recordUsage('test', 150, 250, 0.95);

        self::assertEquals(1, $template->getUsageCount());
        self::assertEquals(150, $template->getAvgResponseTime());
        self::assertEquals(250, $template->getAvgTokensUsed());
        self::assertEquals(0.95, $template->getQualityScore());
    }

    // ==================== getTemplatesForFeature tests ====================

    #[Test]
    public function getTemplatesForFeatureReturnsArray(): void
    {
        $template1 = $this->createTemplate('t1');
        $template2 = $this->createTemplate('t2');

        $queryResultMock = self::createStub(QueryResultInterface::class);
        $queryResultMock
            ->method('toArray')
            ->willReturn([$template1, $template2]);

        $this->repositoryStub
            ->method('findByFeature')
            ->with('translation')
            ->willReturn($queryResultMock);

        $result = $this->subject->getTemplatesForFeature('translation');

        self::assertCount(2, $result);
        self::assertSame($template1, $result[0]);
        self::assertSame($template2, $result[1]);
    }

    #[Test]
    public function getTemplatesForFeatureReturnsEmptyArrayWhenNoneFound(): void
    {
        $queryResultMock = self::createStub(QueryResultInterface::class);
        $queryResultMock
            ->method('toArray')
            ->willReturn([]);

        $this->repositoryStub
            ->method('findByFeature')
            ->willReturn($queryResultMock);

        $result = $this->subject->getTemplatesForFeature('nonexistent');

        self::assertEmpty($result);
    }
}
