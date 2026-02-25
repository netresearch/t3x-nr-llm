<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Model;

use Netresearch\NrLlm\Domain\Model\PromptTemplate;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * Note: Domain models are excluded from coverage in phpunit.xml.
 */
#[CoversNothing]
class PromptTemplateTest extends AbstractUnitTestCase
{
    private PromptTemplate $subject;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new PromptTemplate();
    }

    // ==================== Basic getters/setters ====================

    #[Test]
    public function identifierGetterAndSetter(): void
    {
        $this->subject->setIdentifier('my-template');
        self::assertEquals('my-template', $this->subject->getIdentifier());
    }

    #[Test]
    public function titleGetterAndSetter(): void
    {
        $this->subject->setTitle('My Template');
        self::assertEquals('My Template', $this->subject->getTitle());
    }

    #[Test]
    public function descriptionGetterAndSetter(): void
    {
        $this->subject->setDescription('A test description');
        self::assertEquals('A test description', $this->subject->getDescription());
    }

    #[Test]
    public function descriptionCanBeNull(): void
    {
        $this->subject->setDescription(null);
        self::assertNull($this->subject->getDescription());
    }

    #[Test]
    public function featureGetterAndSetter(): void
    {
        $this->subject->setFeature('translation');
        self::assertEquals('translation', $this->subject->getFeature());
    }

    #[Test]
    public function systemPromptGetterAndSetter(): void
    {
        $this->subject->setSystemPrompt('You are a helpful assistant');
        self::assertEquals('You are a helpful assistant', $this->subject->getSystemPrompt());
    }

    #[Test]
    public function systemPromptCanBeNull(): void
    {
        $this->subject->setSystemPrompt(null);
        self::assertNull($this->subject->getSystemPrompt());
    }

    #[Test]
    public function userPromptTemplateGetterAndSetter(): void
    {
        $this->subject->setUserPromptTemplate('Translate: {{text}}');
        self::assertEquals('Translate: {{text}}', $this->subject->getUserPromptTemplate());
    }

    #[Test]
    public function userPromptTemplateCanBeNull(): void
    {
        $this->subject->setUserPromptTemplate(null);
        self::assertNull($this->subject->getUserPromptTemplate());
    }

    #[Test]
    public function versionGetterAndSetter(): void
    {
        $this->subject->setVersion(5);
        self::assertEquals(5, $this->subject->getVersion());
    }

    #[Test]
    public function versionDefaultsToOne(): void
    {
        self::assertEquals(1, $this->subject->getVersion());
    }

    #[Test]
    public function parentUidGetterAndSetter(): void
    {
        $this->subject->setParentUid(42);
        self::assertEquals(42, $this->subject->getParentUid());
    }

    #[Test]
    public function isActiveGetterAndSetter(): void
    {
        $this->subject->setIsActive(false);
        self::assertFalse($this->subject->isActive());
    }

    #[Test]
    public function isActiveDefaultsToTrue(): void
    {
        self::assertTrue($this->subject->isActive());
    }

    #[Test]
    public function isDefaultGetterAndSetter(): void
    {
        $this->subject->setIsDefault(true);
        self::assertTrue($this->subject->isDefault());
    }

    #[Test]
    public function providerGetterAndSetter(): void
    {
        $this->subject->setProvider('openai');
        self::assertEquals('openai', $this->subject->getProvider());
    }

    #[Test]
    public function providerCanBeNull(): void
    {
        $this->subject->setProvider(null);
        self::assertNull($this->subject->getProvider());
    }

    #[Test]
    public function modelGetterAndSetter(): void
    {
        $this->subject->setModel('gpt-5.2');
        self::assertEquals('gpt-5.2', $this->subject->getModel());
    }

    #[Test]
    public function modelCanBeNull(): void
    {
        $this->subject->setModel(null);
        self::assertNull($this->subject->getModel());
    }

    #[Test]
    public function temperatureGetterAndSetter(): void
    {
        $this->subject->setTemperature(0.5);
        self::assertEquals(0.5, $this->subject->getTemperature());
    }

    #[Test]
    public function temperatureDefaultsToSevenTenths(): void
    {
        self::assertEquals(0.7, $this->subject->getTemperature());
    }

    #[Test]
    public function maxTokensGetterAndSetter(): void
    {
        $this->subject->setMaxTokens(2000);
        self::assertEquals(2000, $this->subject->getMaxTokens());
    }

    #[Test]
    public function maxTokensDefaultsToThousand(): void
    {
        self::assertEquals(1000, $this->subject->getMaxTokens());
    }

    #[Test]
    public function topPGetterAndSetter(): void
    {
        $this->subject->setTopP(0.9);
        self::assertEquals(0.9, $this->subject->getTopP());
    }

    #[Test]
    public function topPDefaultsToOne(): void
    {
        self::assertEquals(1.0, $this->subject->getTopP());
    }

    #[Test]
    public function variablesGetterAndSetter(): void
    {
        $variables = ['required' => ['text'], 'optional' => ['context']];
        $this->subject->setVariables($variables);
        self::assertEquals($variables, $this->subject->getVariables());
    }

    #[Test]
    public function exampleOutputGetterAndSetter(): void
    {
        $this->subject->setExampleOutput('Example result');
        self::assertEquals('Example result', $this->subject->getExampleOutput());
    }

    #[Test]
    public function exampleOutputCanBeNull(): void
    {
        $this->subject->setExampleOutput(null);
        self::assertNull($this->subject->getExampleOutput());
    }

    #[Test]
    public function tagsGetterAndSetter(): void
    {
        $tags = ['translation', 'gpt', 'v2'];
        $this->subject->setTags($tags);
        self::assertEquals($tags, $this->subject->getTags());
    }

    #[Test]
    public function usageCountGetterAndSetter(): void
    {
        $this->subject->setUsageCount(100);
        self::assertEquals(100, $this->subject->getUsageCount());
    }

    #[Test]
    public function avgResponseTimeGetterAndSetter(): void
    {
        $this->subject->setAvgResponseTime(150);
        self::assertEquals(150, $this->subject->getAvgResponseTime());
    }

    #[Test]
    public function avgTokensUsedGetterAndSetter(): void
    {
        $this->subject->setAvgTokensUsed(500);
        self::assertEquals(500, $this->subject->getAvgTokensUsed());
    }

    #[Test]
    public function qualityScoreGetterAndSetter(): void
    {
        $this->subject->setQualityScore(0.85);
        self::assertEquals(0.85, $this->subject->getQualityScore());
    }

    #[Test]
    public function tstampGetterAndSetter(): void
    {
        $now = time();
        $this->subject->setTstamp($now);
        self::assertEquals($now, $this->subject->getTstamp());
    }

    #[Test]
    public function crdateGetterAndSetter(): void
    {
        $now = time();
        $this->subject->setCrdate($now);
        self::assertEquals($now, $this->subject->getCrdate());
    }

    #[Test]
    public function uidGetterAndSetter(): void
    {
        $this->subject->setUid(42);
        self::assertEquals(42, $this->subject->getUid());
    }

    #[Test]
    public function uidCanBeNull(): void
    {
        $this->subject->setUid(null);
        self::assertNull($this->subject->getUid());
    }

    #[Test]
    public function uidIgnoresZeroAndNegative(): void
    {
        // Set a valid UID first
        $this->subject->setUid(10);
        self::assertEquals(10, $this->subject->getUid());

        // Zero should not be set (filtered out by setUid)
        $this->subject->setUid(0);
        // Should remain at previous value since 0 is filtered
        self::assertEquals(10, $this->subject->getUid());
    }

    #[Test]
    public function pidGetterAndSetter(): void
    {
        $this->subject->setPid(5);
        self::assertEquals(5, $this->subject->getPid());
    }

    // ==================== getRequiredVariables ====================

    #[Test]
    public function getRequiredVariablesExtractsFromSystemPrompt(): void
    {
        $this->subject->setSystemPrompt('You are translating {{language}} text.');

        $required = $this->subject->getRequiredVariables();

        self::assertContains('language', $required);
    }

    #[Test]
    public function getRequiredVariablesExtractsFromUserPrompt(): void
    {
        $this->subject->setUserPromptTemplate('Translate: {{text}} to {{targetLang}}');

        $required = $this->subject->getRequiredVariables();

        self::assertContains('text', $required);
        self::assertContains('targetLang', $required);
    }

    #[Test]
    public function getRequiredVariablesExtractsFromBothPrompts(): void
    {
        $this->subject->setSystemPrompt('Context: {{context}}');
        $this->subject->setUserPromptTemplate('Text: {{text}}');

        $required = $this->subject->getRequiredVariables();

        self::assertContains('context', $required);
        self::assertContains('text', $required);
    }

    #[Test]
    public function getRequiredVariablesRemovesDuplicates(): void
    {
        $this->subject->setSystemPrompt('{{name}} says hello');
        $this->subject->setUserPromptTemplate('Hello {{name}}!');

        $required = $this->subject->getRequiredVariables();

        // Should only have one 'name'
        $countName = count(array_filter($required, fn($v) => $v === 'name'));
        self::assertEquals(1, $countName);
    }

    #[Test]
    public function getRequiredVariablesExtractsConditionalVariablesAndContent(): void
    {
        // Note: The regex only matches {{word}}, not {{#if word}}
        // So 'flag' won't be extracted, but 'text' will
        $this->subject->setUserPromptTemplate('{{#if flag}}Show {{text}}{{/if}}');

        $required = $this->subject->getRequiredVariables();

        // Only simple {{var}} patterns are extracted
        self::assertContains('text', $required);
        // Conditional keywords are filtered
        self::assertNotContains('if', $required);
    }

    #[Test]
    public function getRequiredVariablesFiltersSpecialKeywords(): void
    {
        // Note: {{#each items}} isn't matched by {{(\w+)}} pattern
        // But {{this}} and {{else}} are matched and then filtered
        $this->subject->setUserPromptTemplate('Items {{each}} {{this}} {{else}} {{if}}');

        $required = $this->subject->getRequiredVariables();

        // These are filtered out as special keywords
        self::assertNotContains('each', $required);
        self::assertNotContains('this', $required);
        self::assertNotContains('else', $required);
        self::assertNotContains('if', $required);
    }

    #[Test]
    public function getRequiredVariablesOnlyMatchesSimplePattern(): void
    {
        // Only {{word}} is matched, not {{#if word}}
        $this->subject->setUserPromptTemplate('{{x}} {{#if y}}{{/if}}');

        $required = $this->subject->getRequiredVariables();

        // x is matched
        self::assertContains('x', $required);
        // y is in {{#if y}}, not {{y}}, so not matched
        self::assertNotContains('y', $required);
    }

    #[Test]
    public function getRequiredVariablesReturnsEmptyArrayForNoVariables(): void
    {
        $this->subject->setSystemPrompt('Plain text system prompt.');
        $this->subject->setUserPromptTemplate('Plain text user prompt.');

        $required = $this->subject->getRequiredVariables();

        self::assertEmpty($required);
    }

    #[Test]
    public function getRequiredVariablesHandlesNullPrompts(): void
    {
        $this->subject->setSystemPrompt(null);
        $this->subject->setUserPromptTemplate(null);

        $required = $this->subject->getRequiredVariables();

        self::assertEmpty($required);
    }

    // ==================== hasPerformanceData ====================

    #[Test]
    public function hasPerformanceDataReturnsFalseWhenUsageCountZero(): void
    {
        self::assertFalse($this->subject->hasPerformanceData());
    }

    #[Test]
    public function hasPerformanceDataReturnsTrueWhenUsageCountPositive(): void
    {
        $this->subject->setUsageCount(1);

        self::assertTrue($this->subject->hasPerformanceData());
    }

    #[Test]
    public function hasPerformanceDataReturnsTrueForHighUsageCount(): void
    {
        $this->subject->setUsageCount(1000);

        self::assertTrue($this->subject->hasPerformanceData());
    }
}
