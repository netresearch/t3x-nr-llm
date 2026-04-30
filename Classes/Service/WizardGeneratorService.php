<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use Throwable;

/**
 * Generates configuration and task suggestions using a configured LLM.
 *
 * Uses the default LLM configuration to generate new configurations or tasks
 * from a natural-language description. Falls back to sensible defaults when
 * no LLM is available.
 */
final readonly class WizardGeneratorService implements WizardGeneratorServiceInterface
{
    use SafeCastTrait;

    public function __construct(
        private LlmServiceManagerInterface $llmServiceManager,
        private LlmConfigurationRepository $configurationRepository,
        private ModelRepository $modelRepository,
    ) {}

    /**
     * Resolve the configuration that will be used for generation.
     *
     * Public so controllers can show the user which LLM powers the wizard.
     */
    public function resolveConfiguration(?int $configurationUid = null): ?LlmConfiguration
    {
        if ($configurationUid !== null && $configurationUid > 0) {
            $config = $this->configurationRepository->findByUid($configurationUid);
            if ($config instanceof LlmConfiguration && $config->getLlmModel() !== null) {
                return $config;
            }
        }

        return $this->getDefaultConfiguration();
    }

    /**
     * Generate a configuration from a description.
     *
     * @return array<string, mixed> Generated configuration fields
     */
    public function generateConfiguration(string $description, ?LlmConfiguration $config = null): array
    {
        $config ??= $this->getDefaultConfiguration();
        if ($config === null) {
            return $this->fallbackConfiguration($description);
        }

        $context = $this->buildConfigurationContext();
        $prompt = $this->buildConfigurationPrompt($description, $context);

        try {
            $response = $this->llmServiceManager->chatWithConfiguration(
                [
                    ['role' => 'system', 'content' => $this->getConfigurationSystemPrompt()],
                    ['role' => 'user', 'content' => $prompt],
                ],
                $config,
            );

            $parsed = $this->parseJsonResponse($response->content);
            if ($parsed !== null) {
                return $this->normalizeConfigurationResult($parsed, $description);
            }
        } catch (Throwable) {
            // Fall through to fallback
        }

        return $this->fallbackConfiguration($description);
    }

    /**
     * Generate a task from a description.
     *
     * @return array<string, mixed> Generated task fields
     */
    public function generateTask(string $description, ?LlmConfiguration $config = null): array
    {
        $config ??= $this->getDefaultConfiguration();
        if ($config === null) {
            return $this->fallbackTask($description);
        }

        $context = $this->buildTaskContext();
        $prompt = $this->buildTaskPrompt($description, $context);

        try {
            $response = $this->llmServiceManager->chatWithConfiguration(
                [
                    ['role' => 'system', 'content' => $this->getTaskSystemPrompt()],
                    ['role' => 'user', 'content' => $prompt],
                ],
                $config,
            );

            $parsed = $this->parseJsonResponse($response->content);
            if ($parsed !== null) {
                return $this->normalizeTaskResult($parsed, $description);
            }
        } catch (Throwable) {
            // Fall through to fallback
        }

        return $this->fallbackTask($description);
    }

    /**
     * Generate a task with its full chain (task + configuration + model recommendation).
     *
     * Returns task fields, a dedicated configuration, and the best-fitting existing
     * model plus an AI-suggested model specification for cases where no good match exists.
     *
     * @return array<string, mixed> Keys: task, configuration, existing_model, suggested_model, generated
     */
    public function generateTaskWithChain(string $description, ?LlmConfiguration $config = null): array
    {
        $config ??= $this->getDefaultConfiguration();
        if ($config === null) {
            return $this->fallbackTaskChain($description);
        }

        $context = $this->buildFullChainContext();
        $prompt = sprintf("User request: %s\n\n%s", $description, $context);

        try {
            $response = $this->llmServiceManager->chatWithConfiguration(
                [
                    ['role' => 'system', 'content' => $this->getFullChainSystemPrompt()],
                    ['role' => 'user', 'content' => $prompt],
                ],
                $config,
            );

            $parsed = $this->parseJsonResponse($response->content);
            if ($parsed !== null) {
                return $this->normalizeFullChainResult($parsed, $description);
            }
        } catch (Throwable) {
            // Fall through to fallback
        }

        return $this->fallbackTaskChain($description);
    }

    /**
     * Find the best existing model for a recommended model ID.
     */
    public function findBestExistingModel(string $recommendedModelId): ?Model
    {
        if ($recommendedModelId === '') {
            return null;
        }

        /** @var list<Model> $activeModels */
        $activeModels = $this->modelRepository->findActive()->toArray();

        // Exact match on model_id
        foreach ($activeModels as $model) {
            if ($model->getModelId() === $recommendedModelId) {
                return $model;
            }
        }

        // Partial match (e.g., "gpt-4" matches "gpt-4-turbo")
        foreach ($activeModels as $model) {
            if (str_contains($model->getModelId(), $recommendedModelId)
                || str_contains($recommendedModelId, $model->getModelId())) {
                return $model;
            }
        }

        return null;
    }

    /**
     * Find the best existing configuration for a task's needs.
     */
    public function findBestExistingConfiguration(string $description): ?LlmConfiguration
    {
        foreach ($this->configurationRepository->findActive() as $config) {
            // Simple heuristic: config with a system prompt and a model is usable
            if ($config->getSystemPrompt() !== '' && $config->getLlmModel() !== null) {
                return $config;
            }
        }

        return $this->getDefaultConfiguration();
    }

    private function getDefaultConfiguration(): ?LlmConfiguration
    {
        $config = $this->configurationRepository->findDefault();
        if ($config instanceof LlmConfiguration && $config->getLlmModel() !== null) {
            return $config;
        }

        // Try first active config
        foreach ($this->configurationRepository->findAll() as $c) {
            if ($c instanceof LlmConfiguration && $c->isActive() && $c->getLlmModel() !== null) {
                return $c;
            }
        }

        return null;
    }

    private function getConfigurationSystemPrompt(): string
    {
        return <<<'PROMPT'
            You are an expert at configuring LLM integrations for a TYPO3 CMS website.
            Generate a professional configuration based on the user's description.

            IMPORTANT: The user's input is a rough description of their intent — it may contain typos,
            abbreviations, or informal language. You must interpret their intent and produce polished,
            professional output. NEVER copy the user's text verbatim. Always:
            - Fix all spelling and grammar errors
            - Write clear, professional English
            - Create proper names (capitalized, concise)
            - Write a well-crafted system prompt (not a paraphrase of the user's input)

            Return a JSON object with these fields:
            - "identifier": lowercase-hyphenated unique identifier (e.g., "blog-summarizer")
            - "name": professional human-readable name (e.g., "Blog Summarizer")
            - "description": polished one-sentence description of the use case
            - "system_prompt": a well-crafted, effective system prompt tailored for this use case (2-5 sentences).
              This should be a proper system instruction for an LLM, not a restatement of the user's request.
            - "temperature": recommended temperature (0.0-2.0)
            - "max_tokens": recommended max output tokens (256-16384)
            - "top_p": recommended top_p (0.0-1.0, use 1.0 unless specific need)
            - "frequency_penalty": recommended value (0.0-2.0, use 0.0 unless repetition is a concern)
            - "presence_penalty": recommended value (0.0-2.0, use 0.0 unless topic diversity needed)
            - "recommended_model": model_id of the best model from the available list

            Respond with valid JSON only. No markdown, no explanation.
            PROMPT;
    }

    private function getTaskSystemPrompt(): string
    {
        return <<<'PROMPT'
            You are an expert at creating LLM task templates for a TYPO3 CMS website.
            Generate a professional task configuration based on the user's description.

            IMPORTANT: The user's input is a rough description of their intent — it may contain typos,
            abbreviations, or informal language. You must interpret their intent and produce polished,
            professional output. NEVER copy the user's text verbatim. Always:
            - Fix all spelling and grammar errors
            - Write clear, professional English
            - Create proper names (capitalized, concise)
            - Write a well-crafted prompt template (not a paraphrase of the user's input)

            A task has a prompt template with {{input}} as placeholder for user input.

            Return a JSON object with these fields:
            - "identifier": lowercase-hyphenated unique identifier (e.g., "summarize-article")
            - "name": professional human-readable name (e.g., "Summarize Article")
            - "description": polished one-sentence description of what this task does
            - "category": one of "content", "log_analysis", "system", "developer", "general"
            - "prompt_template": the full prompt template. Use {{input}} where user input goes.
              Write clear, specific, professional instructions. Include formatting guidance if relevant.
              This should be a proper LLM instruction, not a restatement of the user's request.
            - "output_format": one of "markdown", "json", "plain", "html"

            Respond with valid JSON only. No markdown, no explanation.
            PROMPT;
    }

    private function buildConfigurationContext(): string
    {
        $models = [];
        foreach ($this->modelRepository->findActive() as $model) {
            $models[] = sprintf(
                '- %s (%s): %s [%s]',
                $model->getName(),
                $model->getModelId(),
                $model->getDescription(),
                $model->getCapabilities(),
            );
        }

        $configs = [];
        foreach ($this->configurationRepository->findAll() as $config) {
            if (!$config instanceof LlmConfiguration) {
                continue;
            }
            $configs[] = sprintf('- %s: %s', $config->getName(), $config->getDescription());
        }

        $parts = [];
        if ($models !== []) {
            $parts[] = "Available models:\n" . implode("\n", array_slice($models, 0, 10));
        }
        if ($configs !== []) {
            $parts[] = "Existing configurations (avoid duplicates):\n" . implode("\n", $configs);
        }

        return implode("\n\n", $parts);
    }

    private function buildTaskContext(): string
    {
        $configs = [];
        foreach ($this->configurationRepository->findAll() as $config) {
            if (!$config instanceof LlmConfiguration) {
                continue;
            }
            $configs[] = sprintf('- %s (identifier: %s)', $config->getName(), $config->getIdentifier());
        }

        $parts = [];
        $parts[] = 'Task categories: content, log_analysis, system, developer, general';
        $parts[] = 'Output formats: markdown, json, plain, html';
        $parts[] = 'Template placeholder: {{input}} — replaced with user-provided text at runtime';
        if ($configs !== []) {
            $parts[] = "Available configurations:\n" . implode("\n", $configs);
        }

        return implode("\n\n", $parts);
    }

    private function buildConfigurationPrompt(string $description, string $context): string
    {
        return sprintf("User request: %s\n\n%s", $description, $context);
    }

    private function buildTaskPrompt(string $description, string $context): string
    {
        return sprintf("User request: %s\n\n%s", $description, $context);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseJsonResponse(string $content): ?array
    {
        // Direct parse
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            /** @var array<string, mixed> $decoded */
            return $decoded;
        }

        // Extract from markdown code block
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $content, $matches)) {
            $decoded = json_decode(trim($matches[1]), true);
            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                return $decoded;
            }
        }

        // Find JSON object in text
        if (preg_match('/(\{[\s\S]*\})/', $content, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function normalizeConfigurationResult(array $data, string $description): array
    {
        return [
            'identifier' => $this->sanitizeIdentifier(self::toStr($data['identifier'] ?? '')),
            'name' => self::toStr($data['name'] ?? 'New Configuration') ?: 'New Configuration',
            'description' => self::toStr($data['description'] ?? $description) ?: $description,
            'system_prompt' => self::toStr($data['system_prompt'] ?? $data['systemPrompt'] ?? ''),
            'temperature' => $this->clamp(self::toFloat($data['temperature'] ?? 0.7), 0.0, 2.0),
            'max_tokens' => $this->clampInt(self::toInt($data['max_tokens'] ?? $data['maxTokens'] ?? 4096), 1, 128000),
            'top_p' => $this->clamp(self::toFloat($data['top_p'] ?? $data['topP'] ?? 1.0), 0.0, 1.0),
            'frequency_penalty' => $this->clamp(self::toFloat($data['frequency_penalty'] ?? $data['frequencyPenalty'] ?? 0.0), -2.0, 2.0),
            'presence_penalty' => $this->clamp(self::toFloat($data['presence_penalty'] ?? $data['presencePenalty'] ?? 0.0), -2.0, 2.0),
            'recommended_model' => self::toStr($data['recommended_model'] ?? $data['recommendedModel'] ?? ''),
            'generated' => true,
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function normalizeTaskResult(array $data, string $description): array
    {
        $validCategories = ['content', 'log_analysis', 'system', 'developer', 'general'];
        $validFormats = ['markdown', 'json', 'plain', 'html'];

        $category = self::toStr($data['category'] ?? 'general');
        $outputFormat = self::toStr($data['output_format'] ?? $data['outputFormat'] ?? 'markdown');

        return [
            'identifier' => $this->sanitizeIdentifier(self::toStr($data['identifier'] ?? '')),
            'name' => self::toStr($data['name'] ?? 'New Task') ?: 'New Task',
            'description' => self::toStr($data['description'] ?? $description) ?: $description,
            'category' => in_array($category, $validCategories, true) ? $category : 'general',
            'prompt_template' => self::toStr($data['prompt_template'] ?? $data['promptTemplate'] ?? ''),
            'output_format' => in_array($outputFormat, $validFormats, true) ? $outputFormat : 'markdown',
            'generated' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fallbackConfiguration(string $description): array
    {
        $identifier = $this->sanitizeIdentifier(substr($description, 0, 40));
        if ($identifier === '') {
            $identifier = 'new-config';
        }

        return [
            'identifier' => $identifier,
            'name' => 'New Configuration',
            'description' => $description,
            'system_prompt' => 'You are a helpful assistant. ' . $description,
            'temperature' => 0.7,
            'max_tokens' => 4096,
            'top_p' => 1.0,
            'frequency_penalty' => 0.0,
            'presence_penalty' => 0.0,
            'recommended_model' => '',
            'generated' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fallbackTask(string $description): array
    {
        $identifier = $this->sanitizeIdentifier(substr($description, 0, 40));
        if ($identifier === '') {
            $identifier = 'new-task';
        }

        return [
            'identifier' => $identifier,
            'name' => 'New Task',
            'description' => $description,
            'category' => 'general',
            'prompt_template' => $description . "\n\n{{input}}",
            'output_format' => 'markdown',
            'generated' => false,
        ];
    }

    private function getFullChainSystemPrompt(): string
    {
        return <<<'PROMPT'
            You are an expert at creating complete LLM task setups for a TYPO3 CMS website.
            Generate a task WITH its dedicated configuration and model recommendation.

            IMPORTANT: The user's input is a rough description of their intent — it may contain typos,
            abbreviations, or informal language. You must interpret their intent and produce polished,
            professional output. NEVER copy the user's text verbatim. Always:
            - Fix all spelling and grammar errors
            - Write clear, professional English
            - Create proper names (capitalized, concise)

            Return a JSON object with these sections:

            "task": {
                "identifier": lowercase-hyphenated (e.g., "summarize-article"),
                "name": professional name (e.g., "Summarize Article"),
                "description": polished one-sentence description,
                "category": one of "content", "log_analysis", "system", "developer", "general",
                "prompt_template": full prompt template using {{input}} placeholder. Write clear, professional instructions.,
                "output_format": one of "markdown", "json", "plain", "html"
            },

            "configuration": {
                "identifier": lowercase-hyphenated (e.g., "summarizer-config"),
                "name": professional name (e.g., "Article Summarizer"),
                "description": one-sentence description of this configuration's purpose,
                "system_prompt": well-crafted system prompt for this use case (2-5 sentences),
                "temperature": recommended 0.0-2.0,
                "max_tokens": recommended 256-16384,
                "top_p": recommended 0.0-1.0,
                "frequency_penalty": recommended 0.0-2.0,
                "presence_penalty": recommended 0.0-2.0
            },

            "recommended_model_id": the model_id of the best available model for this task,

            "suggested_model": {
                "name": human-readable model name suggestion (e.g., "GPT-4 Turbo"),
                "model_id": the API model identifier (e.g., "gpt-4-turbo"),
                "description": why this model is a good fit,
                "capabilities": comma-separated list (e.g., "chat,streaming,tools")
            }

            Respond with valid JSON only. No markdown, no explanation.
            PROMPT;
    }

    private function buildFullChainContext(): string
    {
        $models = [];
        foreach ($this->modelRepository->findActive() as $model) {
            $models[] = sprintf(
                '- %s (%s): %s [%s]',
                $model->getName(),
                $model->getModelId(),
                $model->getDescription(),
                $model->getCapabilities(),
            );
        }

        $configs = [];
        foreach ($this->configurationRepository->findAll() as $config) {
            if (!$config instanceof LlmConfiguration) {
                continue;
            }
            $configs[] = sprintf(
                '- %s (identifier: %s): %s',
                $config->getName(),
                $config->getIdentifier(),
                $config->getDescription(),
            );
        }

        $parts = [];
        $parts[] = 'Task categories: content, log_analysis, system, developer, general';
        $parts[] = 'Output formats: markdown, json, plain, html';
        $parts[] = 'Template placeholder: {{input}} — replaced with user-provided text at runtime';
        if ($models !== []) {
            $parts[] = "Available models (prefer these):\n" . implode("\n", array_slice($models, 0, 10));
        }
        if ($configs !== []) {
            $parts[] = "Existing configurations (for reference, avoid duplicate names):\n" . implode("\n", $configs);
        }

        return implode("\n\n", $parts);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function normalizeFullChainResult(array $data, string $description): array
    {
        $taskData = is_array($data['task'] ?? null) ? $data['task'] : $data;
        $configData = is_array($data['configuration'] ?? null) ? $data['configuration'] : [];
        $suggestedModel = is_array($data['suggested_model'] ?? null) ? $data['suggested_model'] : [];

        $validCategories = ['content', 'log_analysis', 'system', 'developer', 'general'];
        $validFormats = ['markdown', 'json', 'plain', 'html'];

        $category = self::toStr($taskData['category'] ?? 'general');
        $outputFormat = self::toStr($taskData['output_format'] ?? $taskData['outputFormat'] ?? 'markdown');

        return [
            'task' => [
                'identifier' => $this->sanitizeIdentifier(self::toStr($taskData['identifier'] ?? '')),
                'name' => self::toStr($taskData['name'] ?? 'New Task') ?: 'New Task',
                'description' => self::toStr($taskData['description'] ?? $description) ?: $description,
                'category' => in_array($category, $validCategories, true) ? $category : 'general',
                'prompt_template' => self::toStr($taskData['prompt_template'] ?? $taskData['promptTemplate'] ?? ''),
                'output_format' => in_array($outputFormat, $validFormats, true) ? $outputFormat : 'markdown',
            ],
            'configuration' => [
                'identifier' => $this->sanitizeIdentifier(self::toStr($configData['identifier'] ?? '')),
                'name' => self::toStr($configData['name'] ?? 'Task Configuration') ?: 'Task Configuration',
                'description' => self::toStr($configData['description'] ?? ''),
                'system_prompt' => self::toStr($configData['system_prompt'] ?? $configData['systemPrompt'] ?? ''),
                'temperature' => $this->clamp(self::toFloat($configData['temperature'] ?? 0.7), 0.0, 2.0),
                'max_tokens' => $this->clampInt(self::toInt($configData['max_tokens'] ?? $configData['maxTokens'] ?? 4096), 1, 128000),
                'top_p' => $this->clamp(self::toFloat($configData['top_p'] ?? $configData['topP'] ?? 1.0), 0.0, 1.0),
                'frequency_penalty' => $this->clamp(self::toFloat($configData['frequency_penalty'] ?? $configData['frequencyPenalty'] ?? 0.0), -2.0, 2.0),
                'presence_penalty' => $this->clamp(self::toFloat($configData['presence_penalty'] ?? $configData['presencePenalty'] ?? 0.0), -2.0, 2.0),
            ],
            'recommended_model_id' => self::toStr($data['recommended_model_id'] ?? $data['recommendedModelId'] ?? ''),
            'suggested_model' => [
                'name' => self::toStr($suggestedModel['name'] ?? ''),
                'model_id' => self::toStr($suggestedModel['model_id'] ?? $suggestedModel['modelId'] ?? ''),
                'description' => self::toStr($suggestedModel['description'] ?? ''),
                'capabilities' => self::toStr($suggestedModel['capabilities'] ?? 'chat'),
            ],
            'generated' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fallbackTaskChain(string $description): array
    {
        $identifier = $this->sanitizeIdentifier(substr($description, 0, 40));
        if ($identifier === '') {
            $identifier = 'new-task';
        }

        return [
            'task' => [
                'identifier' => $identifier,
                'name' => 'New Task',
                'description' => $description,
                'category' => 'general',
                'prompt_template' => $description . "\n\n{{input}}",
                'output_format' => 'markdown',
            ],
            'configuration' => [
                'identifier' => $identifier . '-config',
                'name' => 'New Task Configuration',
                'description' => 'Configuration for: ' . $description,
                'system_prompt' => 'You are a helpful assistant. ' . $description,
                'temperature' => 0.7,
                'max_tokens' => 4096,
                'top_p' => 1.0,
                'frequency_penalty' => 0.0,
                'presence_penalty' => 0.0,
            ],
            'recommended_model_id' => '',
            'suggested_model' => [
                'name' => '',
                'model_id' => '',
                'description' => '',
                'capabilities' => 'chat',
            ],
            'generated' => false,
        ];
    }

    private function sanitizeIdentifier(string $input): string
    {
        $identifier = strtolower(trim($input));
        $identifier = (string)preg_replace('/[\s_]+/', '-', $identifier);
        $identifier = (string)preg_replace('/[^a-z0-9-]/', '', $identifier);
        $identifier = (string)preg_replace('/-+/', '-', $identifier);
        return trim($identifier, '-');
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    private function clampInt(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
