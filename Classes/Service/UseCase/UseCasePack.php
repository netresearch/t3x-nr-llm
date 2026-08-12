<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\UseCase;

use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Governance\GovernanceProfile;
use Netresearch\NrLlm\Service\Preset\ConfigurationPreset;

/**
 * A named bundle for one use case (ADR-163).
 *
 * A pack is DATA. It declares what a working setup for one use case looks
 * like — a configuration preset, tasks, snippets, a governance posture worth
 * aiming at, the tool groups the tasks would benefit from — and knows how to
 * do exactly none of it. {@see UseCasePackInstaller} is the only thing that
 * writes, and it writes ordinary records through the ordinary services.
 *
 * Three of the fields are deliberately not what they look like:
 *
 * - **The configuration is a {@see ConfigurationPreset}, not a record.** The
 *   preset mechanism (ADR-056) already expresses model REQUIREMENTS rather
 *   than a chosen model, already preflights them against the installed models,
 *   and already owns import and drift resolution. A pack declaring its own
 *   configuration shape would be a second configuration system.
 * - **The governance profile is a RECOMMENDATION.** ADR-145 decided that a
 *   profile describes a posture and never applies one. A pack names the
 *   posture its content was written for; comparing it against what is actually
 *   in force is the governance readout's job, and changing it is the
 *   operator's.
 * - **The tool groups are RECOMMENDED, never enabled.** Enabling a group is an
 *   admin decision made in the Tools module, and it stays there. A pack that
 *   enabled its own tools would hand an install button the authority of the
 *   tool gate.
 *
 * Skills are absent by design — see ADR-163.
 */
final readonly class UseCasePack
{
    /** Lowercase segments separated by single dashes, e.g. `editorial-starter`. */
    private const IDENTIFIER_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** The `tx_nrllm_configuration.snippet_tags` column is varchar(255). */
    private const SNIPPET_TAGS_MAX_LENGTH = 255;

    /**
     * @param string            $identifier            Pack identifier, unique across all providers
     * @param UseCase           $useCase               The question this pack answers
     * @param string            $name                  Human-readable pack name
     * @param string            $description           What an operator gets from installing it
     * @param list<PackTask>    $tasks                 Task records to create
     * @param list<PackSnippet> $snippets              Snippet records to create
     * @param list<string>      $recommendedToolGroups Tool groups the tasks benefit from; shown, never enabled
     */
    public function __construct(
        public string $identifier,
        public UseCase $useCase,
        public string $name,
        public string $description,
        public ConfigurationPreset $configurationPreset,
        public GovernanceProfile $recommendedGovernanceProfile,
        public array $tasks = [],
        public array $snippets = [],
        public array $recommendedToolGroups = [],
    ) {
        if (preg_match(self::IDENTIFIER_PATTERN, $identifier) !== 1) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid use-case pack identifier "%s": expected lowercase segments separated by dashes, e.g. "editorial-starter".',
                    $identifier,
                ),
                1791460021,
            );
        }

        if ($name === '') {
            throw new InvalidArgumentException(
                sprintf('Use-case pack "%s" declares no name.', $identifier),
                1791460022,
            );
        }

        // A duplicate identifier inside one pack would silently install one of
        // the two: the second is skipped as "already installed" by the record
        // the first just created.
        $this->assertUniqueIdentifiers(
            array_map(static fn(PackTask $task): string => $task->identifier, $this->tasks),
            'task',
            1791460023,
        );
        $this->assertUniqueIdentifiers(
            array_map(static fn(PackSnippet $snippet): string => $snippet->identifier, $this->snippets),
            'snippet',
            1791460024,
        );

        // The derived tag list is written to `tx_nrllm_configuration.snippet_tags`,
        // a varchar(255). Extbase writes it without FormEngine's max check.
        if (mb_strlen(implode(',', $this->getSnippetTags())) > self::SNIPPET_TAGS_MAX_LENGTH) {
            throw new InvalidArgumentException(
                sprintf(
                    'Use-case pack "%s" derives snippet tags exceeding the %d-character snippet_tags column limit.',
                    $identifier,
                    self::SNIPPET_TAGS_MAX_LENGTH,
                ),
                1791460025,
            );
        }
    }

    /**
     * The model capabilities the pack cannot work without, as declared by its
     * configuration preset. One source: the same list the preflight checks.
     *
     * @return list<string>
     */
    public function getRequiredCapabilities(): array
    {
        return $this->configurationPreset->criteria->capabilities;
    }

    /**
     * The snippet tags that link the pack's snippets to its configuration.
     *
     * DERIVED from the declared snippets rather than declared separately: a
     * configuration composes the active snippets carrying any of its tags
     * (ADR-031), so a hand-written tag list could name a tag no snippet has —
     * and the snippets a pack installs would then be read by nothing.
     *
     * @return list<string>
     */
    public function getSnippetTags(): array
    {
        $tags = [];
        foreach ($this->snippets as $snippet) {
            foreach ($snippet->tags as $tag) {
                $tags[$tag] = true;
            }
        }

        return array_keys($tags);
    }

    /**
     * @param list<string> $identifiers
     */
    private function assertUniqueIdentifiers(array $identifiers, string $kind, int $code): void
    {
        $duplicates = array_keys(array_filter(array_count_values($identifiers), static fn(int $count): bool => $count > 1));
        if ($duplicates !== []) {
            throw new InvalidArgumentException(
                sprintf(
                    'Use-case pack "%s" declares the %s identifier(s) %s more than once.',
                    $this->identifier,
                    $kind,
                    implode(', ', $duplicates),
                ),
                $code,
            );
        }
    }
}
