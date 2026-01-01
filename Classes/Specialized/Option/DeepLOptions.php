<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Option;

use Netresearch\NrLlm\Service\Option\AbstractOptions;

/**
 * Options specific to DeepL translation service.
 */
final class DeepLOptions extends AbstractOptions
{
    private const array VALID_FORMALITIES = ['default', 'more', 'less', 'prefer_more', 'prefer_less'];
    private const array VALID_TAG_HANDLING = ['xml', 'html'];

    /**
     * @param array<int, string>|null $ignoreTags
     * @param array<int, string>|null $nonSplittingTags
     */
    public function __construct(
        public readonly ?string $formality = 'default',
        public readonly ?string $glossaryId = null,
        public readonly ?bool $preserveFormatting = null,
        public readonly ?bool $splitSentences = null,
        public readonly ?string $tagHandling = null,
        public readonly ?array $ignoreTags = null,
        public readonly ?array $nonSplittingTags = null,
    ) {
        if ($this->formality !== null) {
            self::validateEnum($this->formality, self::VALID_FORMALITIES, 'formality');
        }
        if ($this->tagHandling !== null) {
            self::validateEnum($this->tagHandling, self::VALID_TAG_HANDLING, 'tagHandling');
        }
    }

    public function toArray(): array
    {
        return $this->filterNull([
            'formality' => $this->formality,
            'glossary_id' => $this->glossaryId,
            'preserve_formatting' => $this->preserveFormatting,
            'split_sentences' => $this->splitSentences,
            'tag_handling' => $this->tagHandling,
            'ignore_tags' => $this->ignoreTags,
            'non_splitting_tags' => $this->nonSplittingTags,
        ]);
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options): static
    {
        $formality = $options['formality'] ?? null;
        $glossaryId = $options['glossary_id'] ?? $options['glossaryId'] ?? null;
        $preserveFormatting = $options['preserve_formatting'] ?? $options['preserveFormatting'] ?? null;
        $splitSentences = $options['split_sentences'] ?? $options['splitSentences'] ?? null;
        $tagHandling = $options['tag_handling'] ?? $options['tagHandling'] ?? null;
        $ignoreTags = $options['ignore_tags'] ?? $options['ignoreTags'] ?? null;
        $nonSplittingTags = $options['non_splitting_tags'] ?? $options['nonSplittingTags'] ?? null;

        return new self(
            formality: is_string($formality) ? $formality : null,
            glossaryId: is_string($glossaryId) ? $glossaryId : null,
            preserveFormatting: is_bool($preserveFormatting) ? $preserveFormatting : null,
            splitSentences: is_bool($splitSentences) ? $splitSentences : null,
            tagHandling: is_string($tagHandling) ? $tagHandling : null,
            ignoreTags: is_array($ignoreTags) ? array_values(array_filter($ignoreTags, is_string(...))) : null,
            nonSplittingTags: is_array($nonSplittingTags) ? array_values(array_filter($nonSplittingTags, is_string(...))) : null,
        );
    }

    /**
     * Create options for formal language style.
     */
    public static function formal(): self
    {
        return new self(formality: 'more');
    }

    /**
     * Create options for informal language style.
     */
    public static function informal(): self
    {
        return new self(formality: 'less');
    }

    /**
     * Create options for HTML content.
     */
    public static function html(): self
    {
        return new self(tagHandling: 'html', preserveFormatting: true);
    }

    /**
     * Create options for XML content.
     */
    public static function xml(): self
    {
        return new self(tagHandling: 'xml', preserveFormatting: true);
    }

    /**
     * Create options with a specific glossary.
     */
    public static function withGlossary(string $glossaryId): self
    {
        return new self(glossaryId: $glossaryId);
    }
}
