<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

/**
 * Domain model for prompt snippets.
 *
 * A prompt snippet is a small named prompt fragment (persona, tone of
 * voice, target audience, image style, layout, ...) that editors manage
 * centrally. Consuming extensions query snippets by tag and compose them
 * into their prompts.
 *
 * Deliberately NOT a PromptTemplate: templates are complete prompts with
 * model parameters and versioning, snippets are reusable fragments.
 * See ADR-031.
 */
class PromptSnippet extends AbstractEntity
{
    protected string $identifier = '';
    protected string $name = '';
    protected string $description = '';

    /** Comma-separated free-form tags, e.g. "audience,tone_of_voice". */
    protected string $tags = '';

    /** The prompt fragment text. */
    protected string $snippet = '';

    /**
     * Optional metadata as JSON object string ('' when unused),
     * e.g. {"voice":"nova"} on persona snippets.
     */
    protected string $metadata = '';

    protected bool $isActive = true;
    protected int $sorting = 0;

    // Getters

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getTags(): string
    {
        return $this->tags;
    }

    public function getSnippet(): string
    {
        return $this->snippet;
    }

    public function getMetadata(): string
    {
        return $this->metadata;
    }

    /**
     * Getter pair as in the other models (Provider, Model, Task, ...): Fluid resolves
     * the template path {snippet.isActive} via getIsActive() — isActive() alone makes
     * ObjectAccess fall back to the protected property and crash the module.
     */
    public function getIsActive(): bool
    {
        return $this->isActive;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getSorting(): int
    {
        return $this->sorting;
    }

    // Setters

    public function setIdentifier(string $identifier): void
    {
        $this->identifier = $identifier;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    public function setTags(string $tags): void
    {
        $this->tags = $tags;
    }

    public function setSnippet(string $snippet): void
    {
        $this->snippet = $snippet;
    }

    public function setMetadata(string $metadata): void
    {
        $this->metadata = $metadata;
    }

    public function setIsActive(bool $isActive): void
    {
        $this->isActive = $isActive;
    }

    public function setSorting(int $sorting): void
    {
        $this->sorting = $sorting;
    }

    /**
     * Get the tags as a normalized list.
     *
     * Each tag is trimmed and lowercased; empty entries are dropped.
     *
     * @return list<string>
     */
    public function getTagList(): array
    {
        $tags = array_map(
            static fn(string $tag): string => strtolower(trim($tag)),
            explode(',', $this->tags),
        );

        return array_values(array_filter(
            $tags,
            static fn(string $tag): bool => $tag !== '',
        ));
    }

    /**
     * Get the metadata as a decoded JSON object.
     *
     * Returns an empty array when the metadata field is empty or does not
     * contain a valid JSON object — bad editor input never throws.
     *
     * @return array<string, mixed>
     */
    public function getMetadataArray(): array
    {
        if (trim($this->metadata) === '') {
            return [];
        }

        $decoded = json_decode($this->metadata, true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            return [];
        }

        $metadata = [];
        foreach ($decoded as $key => $value) {
            $metadata[(string)$key] = $value;
        }

        return $metadata;
    }
}
