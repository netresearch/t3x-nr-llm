<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Netresearch\NrLlm\Domain\Model\PromptTemplate;
use Netresearch\NrLlm\Domain\Model\RenderedPrompt;
use Netresearch\NrLlm\Domain\Repository\PromptTemplateRepository;
use Netresearch\NrLlm\Exception\PromptTemplateNotFoundException;
use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * Service for managing and rendering prompt templates
 *
 * Handles template loading, variable substitution, versioning,
 * and performance tracking.
 */
class PromptTemplateService
{
    public function __construct(
        private readonly PromptTemplateRepository $repository,
    ) {}

    /**
     * Get active prompt template by identifier
     *
     * @param string $identifier Unique prompt identifier
     * @return PromptTemplate
     * @throws PromptTemplateNotFoundException
     */
    public function getPrompt(string $identifier): PromptTemplate
    {
        $template = $this->repository->findByIdentifier($identifier);

        if ($template === null) {
            throw new PromptTemplateNotFoundException(
                sprintf('Prompt template "%s" not found', $identifier)
            );
        }

        return $template;
    }

    /**
     * Render prompt with variables
     *
     * @param string $identifier Prompt template identifier
     * @param array $variables Template variables
     * @param array $options Rendering options
     * @return RenderedPrompt
     * @throws PromptTemplateNotFoundException
     * @throws InvalidArgumentException
     */
    public function render(
        string $identifier,
        array $variables = [],
        array $options = []
    ): RenderedPrompt {
        $template = $this->getPrompt($identifier);

        // Validate required variables
        $this->validateVariables($template, $variables);

        // Render system prompt
        $systemPrompt = $this->substitute(
            $template->getSystemPrompt() ?? '',
            $variables
        );

        // Render user prompt
        $userPrompt = $this->substitute(
            $template->getUserPromptTemplate() ?? '',
            $variables
        );

        return new RenderedPrompt(
            systemPrompt: trim($systemPrompt),
            userPrompt: trim($userPrompt),
            model: $options['model'] ?? $template->getModel(),
            temperature: $options['temperature'] ?? $template->getTemperature(),
            maxTokens: $options['max_tokens'] ?? $template->getMaxTokens(),
            topP: $options['top_p'] ?? $template->getTopP(),
            metadata: [
                'template_id' => $template->getUid(),
                'template_identifier' => $identifier,
                'version' => $template->getVersion(),
            ]
        );
    }

    /**
     * Create new version of existing template
     *
     * @param string $identifier Base template identifier
     * @param array $updates Fields to update
     * @return PromptTemplate
     * @throws PromptTemplateNotFoundException
     */
    public function createVersion(string $identifier, array $updates): PromptTemplate
    {
        $baseTemplate = $this->getPrompt($identifier);

        $newTemplate = clone $baseTemplate;
        $newTemplate->setVersion($baseTemplate->getVersion() + 1);
        $newTemplate->setParentUid($baseTemplate->getUid());

        // Apply updates
        foreach ($updates as $field => $value) {
            $setter = 'set' . ucfirst($field);
            if (method_exists($newTemplate, $setter)) {
                $newTemplate->$setter($value);
            }
        }

        $this->repository->save($newTemplate);

        return $newTemplate;
    }

    /**
     * Get A/B test variant
     *
     * @param string $identifier Base template identifier
     * @param string $variantName Variant name/tag
     * @return PromptTemplate
     * @throws PromptTemplateNotFoundException
     */
    public function getVariant(string $identifier, string $variantName): PromptTemplate
    {
        $template = $this->repository->findVariant($identifier, $variantName);

        if ($template === null) {
            throw new PromptTemplateNotFoundException(
                sprintf(
                    'Variant "%s" of template "%s" not found',
                    $variantName,
                    $identifier
                )
            );
        }

        return $template;
    }

    /**
     * Record usage statistics for prompt
     *
     * @param string $identifier Prompt template identifier
     * @param int $responseTime Response time in milliseconds
     * @param int $tokensUsed Total tokens used
     * @param float $qualityScore Quality score (0.0-1.0)
     * @return void
     */
    public function recordUsage(
        string $identifier,
        int $responseTime,
        int $tokensUsed,
        float $qualityScore
    ): void {
        $template = $this->getPrompt($identifier);

        // Update running averages
        $usageCount = $template->getUsageCount();
        $newCount = $usageCount + 1;

        $avgResponseTime = (int) round(
            (($template->getAvgResponseTime() * $usageCount) + $responseTime) / $newCount
        );

        $avgTokens = (int) round(
            (($template->getAvgTokensUsed() * $usageCount) + $tokensUsed) / $newCount
        );

        $avgQuality = round(
            (($template->getQualityScore() * $usageCount) + $qualityScore) / $newCount,
            2
        );

        $template->setUsageCount($newCount);
        $template->setAvgResponseTime($avgResponseTime);
        $template->setAvgTokensUsed($avgTokens);
        $template->setQualityScore($avgQuality);

        $this->repository->save($template);
    }

    /**
     * Get all templates for a feature
     *
     * @param string $feature Feature identifier
     * @return array Array of PromptTemplate objects
     */
    public function getTemplatesForFeature(string $feature): array
    {
        return $this->repository->findByFeature($feature);
    }

    /**
     * Substitute variables in template
     *
     * Supports:
     * - Simple substitution: {{variable}}
     * - Conditional sections: {{#if variable}}...{{/if}}
     * - Loops: {{#each items}}...{{/each}}
     *
     * @param string $template Template string
     * @param array $variables Variables to substitute
     * @return string Rendered template
     */
    private function substitute(string $template, array $variables): string
    {
        // Simple variable substitution: {{variable}}
        $result = preg_replace_callback(
            '/\{\{(\w+)\}\}/',
            function ($matches) use ($variables) {
                $key = $matches[1];
                return $variables[$key] ?? '';
            },
            $template
        );

        // Conditional sections: {{#if variable}}...{{/if}}
        $result = preg_replace_callback(
            '/\{\{#if (\w+)\}\}(.*?)\{\{\/if\}\}/s',
            function ($matches) use ($variables) {
                $key = $matches[1];
                $content = $matches[2];
                return !empty($variables[$key]) ? $content : '';
            },
            $result
        );

        // Conditional else: {{#if variable}}...{{else}}...{{/if}}
        $result = preg_replace_callback(
            '/\{\{#if (\w+)\}\}(.*?)\{\{else\}\}(.*?)\{\{\/if\}\}/s',
            function ($matches) use ($variables) {
                $key = $matches[1];
                $ifContent = $matches[2];
                $elseContent = $matches[3];
                return !empty($variables[$key]) ? $ifContent : $elseContent;
            },
            $result
        );

        // Loop: {{#each items}}{{this}}{{/each}}
        $result = preg_replace_callback(
            '/\{\{#each (\w+)\}\}(.*?)\{\{\/each\}\}/s',
            function ($matches) use ($variables) {
                $key = $matches[1];
                $template = $matches[2];
                $items = $variables[$key] ?? [];

                if (!is_array($items)) {
                    return '';
                }

                $output = '';
                foreach ($items as $item) {
                    $itemStr = is_array($item) ? json_encode($item) : (string) $item;
                    $output .= str_replace('{{this}}', $itemStr, $template);
                }

                return $output;
            },
            $result
        );

        return $result;
    }

    /**
     * Validate required variables are provided
     *
     * @param PromptTemplate $template
     * @param array $variables
     * @throws InvalidArgumentException
     */
    private function validateVariables(PromptTemplate $template, array $variables): void
    {
        $requiredVars = $template->getRequiredVariables();

        if (empty($requiredVars)) {
            return;
        }

        $missing = array_diff($requiredVars, array_keys($variables));

        if (!empty($missing)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Missing required variables for template "%s": %s',
                    $template->getIdentifier(),
                    implode(', ', $missing)
                )
            );
        }
    }
}
