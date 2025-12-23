<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Option;

/**
 * Trait for services to resolve options from mixed input
 *
 * Enables backwards-compatible handling of both typed Option objects
 * and legacy array-based options.
 */
trait OptionsResolverTrait
{
    /**
     * Resolve ChatOptions from mixed input
     *
     * @param ChatOptions|array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function resolveChatOptions(ChatOptions|array $options): array
    {
        if ($options instanceof ChatOptions) {
            return $options->toArray();
        }
        return $options;
    }

    /**
     * Resolve EmbeddingOptions from mixed input
     *
     * @param EmbeddingOptions|array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function resolveEmbeddingOptions(EmbeddingOptions|array $options): array
    {
        if ($options instanceof EmbeddingOptions) {
            return $options->toArray();
        }
        return $options;
    }

    /**
     * Resolve VisionOptions from mixed input
     *
     * @param VisionOptions|array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function resolveVisionOptions(VisionOptions|array $options): array
    {
        if ($options instanceof VisionOptions) {
            return $options->toArray();
        }
        return $options;
    }

    /**
     * Resolve TranslationOptions from mixed input
     *
     * @param TranslationOptions|array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function resolveTranslationOptions(TranslationOptions|array $options): array
    {
        if ($options instanceof TranslationOptions) {
            return $options->toArray();
        }
        return $options;
    }

    /**
     * Resolve ToolOptions from mixed input
     *
     * @param ToolOptions|array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function resolveToolOptions(ToolOptions|array $options): array
    {
        if ($options instanceof ToolOptions) {
            return $options->toArray();
        }
        return $options;
    }

    /**
     * Resolve any AbstractOptions from mixed input
     *
     * @param AbstractOptions|array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function resolveOptions(AbstractOptions|array $options): array
    {
        if ($options instanceof AbstractOptions) {
            return $options->toArray();
        }
        return $options;
    }
}
