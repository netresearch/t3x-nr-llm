<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Vision;

use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * The operator's settings for vision editor actions (ADR-235), read from the
 * extension configuration under `vision.`. A missing or unusable value falls
 * back to the shipped default, so a tool built by hand behaves like a fresh
 * installation.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class VisionSettings
{
    /** Longest edge of the image sent to the model, in pixels. */
    public const DEFAULT_MAX_IMAGE_EDGE = 1024;

    /** @var array<string, string> the shipped prompt per field */
    private const PROMPTS = [
        'alternative' => 'Write an alternative text for screen readers, under 125 characters: what the image shows and what matters about it. Do not start with "image of".',
        'title'       => 'Write a title under 60 characters that names what the image shows.',
        'description' => 'Write a description of the image under 500 characters: subject, setting and notable details.',
        'categories'  => 'Choose the categories that describe what the image shows, from the list below only. Choose none when none fits.',
    ];

    /** The field whose values are records below a configured root (ADR-268). */
    public const CATEGORIES = 'categories';

    public function __construct(
        private ?ExtensionConfiguration $extensionConfiguration = null,
    ) {}

    public function field(string $name): VisionField
    {
        $prompt = self::PROMPTS[$name] ?? '';

        $values = $this->vision()[$name] ?? null;
        $values = is_array($values) ? $values : [];

        $configuredPrompt = $values['prompt'] ?? '';
        $configuredLength = $values['maxLength'] ?? null;

        // Categories are chosen from a tree an operator names; without its root
        // there is nothing to choose from, so the field is off (ADR-268).
        $enabled = !in_array($values['enabled'] ?? true, ['0', 0, false], true)
            && ($name !== self::CATEGORIES || $this->categoryRoot() > 0);

        return new VisionField(
            $name,
            $enabled,
            is_numeric($configuredLength) && (int) $configuredLength > 0 ? (int) $configuredLength : 0,
            is_string($configuredPrompt) && trim($configuredPrompt) !== '' ? trim($configuredPrompt) : $prompt,
        );
    }

    /**
     * The `sys_category` uid below which categories may be proposed for a file
     * (`vision.categories.rootUid`), or 0 when none is configured (ADR-268).
     */
    public function categoryRoot(): int
    {
        $values = $this->vision()[self::CATEGORIES] ?? null;
        $root   = is_array($values) ? ($values['rootUid'] ?? null) : null;

        return is_numeric($root) && (int) $root > 0 ? (int) $root : 0;
    }

    public function maxImageEdge(): int
    {
        $edge = $this->vision()['maxImageEdge'] ?? null;

        return is_numeric($edge) && (int) $edge > 0 ? (int) $edge : self::DEFAULT_MAX_IMAGE_EDGE;
    }

    /**
     * Whether a file lies in a folder the operator excluded. An entry is a
     * combined folder identifier such as `1:/private/`, and it covers its
     * subfolders; the trailing slash is optional, so `1:/priv` does not cover
     * `1:/private/`.
     */
    public function isExcluded(string $combinedIdentifier): bool
    {
        $excluded = $this->vision()['excludedFolders'] ?? '';
        if (!is_string($excluded)) {
            return false;
        }

        foreach (explode(',', $excluded) as $folder) {
            $folder = trim($folder);
            if ($folder !== '' && str_starts_with($combinedIdentifier, rtrim($folder, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function vision(): array
    {
        if (!$this->extensionConfiguration instanceof ExtensionConfiguration) {
            return [];
        }

        try {
            $configuration = $this->extensionConfiguration->get('nr_llm');
        } catch (Throwable) {
            return [];
        }

        $vision = is_array($configuration) ? ($configuration['vision'] ?? null) : null;

        return is_array($vision) ? $vision : [];
    }
}
