<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\UseCase;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Preset\ConfigurationPresetImportService;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * Plans and installs a use-case pack (ADR-163).
 *
 * The small installer the pack is data for. It creates ordinary records through
 * the ordinary services — the configuration through
 * {@see ConfigurationPresetImportService}, tasks and snippets through their
 * repositories — and adds no state of its own: nothing marks a record as
 * pack-owned, and nothing has to be cleaned up if a pack is later removed from
 * the code.
 *
 * **"Already installed" means a record with that identifier exists.** Nothing
 * more. The installer never updates, never overwrites, and never compares
 * contents: an operator who renamed a pack task or rewrote its prompt owns that
 * record, and a second install must be a no-op for it. Identifier lookups run
 * through the repositories' backend query settings, which ignore enable fields,
 * so a record the operator DISABLED still counts as installed — otherwise
 * "install again" would quietly resurrect what they switched off.
 *
 * The configuration is the one hard requirement: when it is neither present nor
 * importable, the install is refused rather than half-applied, because tasks
 * pointing at a configuration that does not exist would fall back to the
 * default one and quietly run under settings the pack never described.
 *
 * The one field the installer writes on an EXISTING record is the
 * configuration's `snippet_tags` selection, and it only ever adds to it. The
 * exception exists because the pack is not the only way that record appears:
 * the configuration can also be created by importing the pack's preset in the
 * Configuration module (the same preset, published by
 * {@see UseCasePackPresetProvider}), and that import knows nothing about
 * snippets. Without the link the pack would install its snippets and leave them
 * composed into nothing. The addition is shown on the plan screen before it
 * happens, and tags the operator selected themselves are kept.
 *
 * {@see plan()} additionally reports the state of the tool groups and editor
 * actions the pack RECOMMENDS (ADR-168). {@see install()} does not read those
 * lists at all: there is no branch here that enables a tool group, enables an
 * editor action or runs one, and {@see PackToolReadinessInterface} has no
 * writing method to call.
 */
final readonly class UseCasePackInstaller
{
    /** The pack's configuration is absent and no active model satisfies its criteria. */
    public const CODE_CONFIGURATION_UNSATISFIABLE = 1791460041;

    /** Adding the pack's tags to the existing selection would overflow the column. */
    public const CODE_SNIPPET_TAGS_TOO_LONG = 1791460042;

    /** The `tx_nrllm_configuration.snippet_tags` column is varchar(255). */
    private const SNIPPET_TAGS_MAX_LENGTH = 255;

    public function __construct(
        private ConfigurationPresetImportService $presetImportService,
        private LlmConfigurationRepository $configurationRepository,
        private TaskRepository $taskRepository,
        private PromptSnippetRepository $snippetRepository,
        private PersistenceManagerInterface $persistenceManager,
        private PackToolReadinessInterface $toolReadiness,
    ) {}

    /**
     * Read-only: what installing this pack would create, and what is already
     * there. This is the screen the operator confirms.
     */
    public function plan(UseCasePack $pack): UseCasePackPlan
    {
        $preset = $pack->configurationPreset;
        $existingConfiguration = $this->configurationRepository->findOneByIdentifier($preset->identifier);

        $configurationItem = new UseCasePackPlanItem(
            identifier: $preset->identifier,
            label: $preset->name,
            installed: $existingConfiguration instanceof LlmConfiguration,
        );

        $tasks = [];
        foreach ($pack->tasks as $packTask) {
            $tasks[] = new UseCasePackPlanItem(
                identifier: $packTask->identifier,
                label: $packTask->name,
                installed: $this->taskRepository->findOneByIdentifier($packTask->identifier) instanceof Task,
            );
        }

        $snippets = [];
        $pendingSnippetTags = [];
        foreach ($pack->snippets as $packSnippet) {
            $installed = $this->snippetRepository->findOneByIdentifier($packSnippet->identifier) instanceof PromptSnippet;
            $snippets[] = new UseCasePackPlanItem(
                identifier: $packSnippet->identifier,
                label: $packSnippet->name,
                installed: $installed,
            );

            // Only the composed half (ADR-186): a snippet the declaring
            // extension resolves by uid reaches no configuration by tag, so it
            // pulls no other configuration into the plan either.
            if (!$installed && $packSnippet->composedByConfiguration) {
                $pendingSnippetTags = [...$pendingSnippetTags, ...$this->normalizeTags($packSnippet->tags)];
            }
        }

        $missingSnippetTags = $this->missingSnippetTags($pack, $existingConfiguration);

        return new UseCasePackPlan(
            pack: $pack,
            configuration: $configurationItem,
            tasks: $tasks,
            snippets: $snippets,
            preflight: $this->presetImportService->preflight($preset),
            missingSnippetTags: $missingSnippetTags,
            affectedConfigurations: $this->configurationsReachedBy($preset->identifier, $pendingSnippetTags),
            incomingSnippets: $this->snippetsPulledInBy($pack, $missingSnippetTags),
            // What installing does NOT do (ADR-168). Both lists are read
            // through the tool module's own availability service; the plan
            // states their current state and neither can block the install.
            editorActions: $this->toolReadiness->editorActionStates($pack->recommendedEditorActions),
            toolGroups: $this->toolReadiness->toolGroupStates($pack->recommendedToolGroups),
        );
    }

    /**
     * Create every declared record that does not exist yet, and nothing else.
     *
     * @throws InvalidArgumentException when the pack's configuration is missing
     *                                  and cannot be imported (code
     *                                  {@see self::CODE_CONFIGURATION_UNSATISFIABLE},
     *                                  or one of the preset service's own codes),
     *                                  or when the tag link would overflow the
     *                                  column ({@see self::CODE_SNIPPET_TAGS_TOO_LONG})
     */
    public function install(UseCasePack $pack): UseCasePackInstallResult
    {
        $configuration = $this->resolveOrImportConfiguration($pack);
        $createdConfiguration = $configuration['created'];
        $record = $configuration['record'];

        // Before any record is created: a refusal here must not leave snippets
        // behind that nothing reads.
        $addedSnippetTags = $this->linkSnippetTags($pack, $record);

        $createdTasks = [];
        $skippedTasks = [];
        foreach ($pack->tasks as $packTask) {
            if ($this->taskRepository->findOneByIdentifier($packTask->identifier) instanceof Task) {
                $skippedTasks[] = $packTask->identifier;
                continue;
            }

            $this->taskRepository->add($this->buildTask($packTask, $record));
            $createdTasks[] = $packTask->identifier;
        }

        $createdSnippets = [];
        $skippedSnippets = [];
        foreach ($pack->snippets as $packSnippet) {
            if ($this->snippetRepository->findOneByIdentifier($packSnippet->identifier) instanceof PromptSnippet) {
                $skippedSnippets[] = $packSnippet->identifier;
                continue;
            }

            $this->snippetRepository->add($this->buildSnippet($packSnippet));
            $createdSnippets[] = $packSnippet->identifier;
        }

        $this->persistenceManager->persistAll();

        return new UseCasePackInstallResult(
            createdConfiguration: $createdConfiguration,
            createdTasks: $createdTasks,
            skippedTasks: $skippedTasks,
            createdSnippets: $createdSnippets,
            skippedSnippets: $skippedSnippets,
            addedSnippetTags: $addedSnippetTags,
        );
    }

    /**
     * @throws InvalidArgumentException
     *
     * @return array{record: LlmConfiguration, created: bool}
     */
    private function resolveOrImportConfiguration(UseCasePack $pack): array
    {
        $preset = $pack->configurationPreset;

        $existing = $this->configurationRepository->findOneByIdentifier($preset->identifier);
        if ($existing instanceof LlmConfiguration) {
            return ['record' => $existing, 'created' => false];
        }

        $preflight = $this->presetImportService->preflight($preset);
        if (!$preflight->satisfiable) {
            throw new InvalidArgumentException(
                sprintf(
                    'Use-case pack "%s" cannot be installed: no active model satisfies its configuration requirement (%s).',
                    $pack->identifier,
                    (string)$preflight->missingRequirement,
                ),
                self::CODE_CONFIGURATION_UNSATISFIABLE,
            );
        }

        return ['record' => $this->presetImportService->import($preset), 'created' => true];
    }

    /**
     * Add the pack's snippet tags to its configuration's selection.
     *
     * A configuration composes the active snippets carrying any of its tags
     * (ADR-031), so without this the pack's snippets exist and are read by
     * nothing. It runs on every install, not only when the installer created
     * the record: the same configuration can be created by importing the pack's
     * preset in the Configuration module, and that import writes no
     * `snippet_tags` — ADR-056 does not own that field.
     *
     * It only ADDS. Tags the operator selected themselves stay, and a tag
     * already selected is a no-op, so a second install writes nothing. Whatever
     * it would add is listed on the plan screen the operator confirms.
     *
     * @throws InvalidArgumentException when the merged selection would not fit
     *                                  the varchar(255) column
     *
     * @return list<string> the tags actually written, so the result can report
     *                      the one record an install changes without creating
     */
    private function linkSnippetTags(UseCasePack $pack, LlmConfiguration $configuration): array
    {
        $missing = $this->missingSnippetTags($pack, $configuration);
        if ($missing === []) {
            return [];
        }

        $merged = implode(',', [...$configuration->getSnippetTagList(), ...$missing]);
        if (mb_strlen($merged) > self::SNIPPET_TAGS_MAX_LENGTH) {
            throw new InvalidArgumentException(
                sprintf(
                    'Use-case pack "%s" cannot be installed: adding its snippet tags to configuration "%s" would exceed the %d-character snippet_tags column limit. Remove tags there first.',
                    $pack->identifier,
                    $configuration->getIdentifier(),
                    self::SNIPPET_TAGS_MAX_LENGTH,
                ),
                self::CODE_SNIPPET_TAGS_TOO_LONG,
            );
        }

        $configuration->setSnippetTags($merged);
        $this->configurationRepository->update($configuration);

        return $missing;
    }

    /**
     * The pack's snippet tags the given configuration does not select yet.
     *
     * @return list<string>
     */
    private function missingSnippetTags(UseCasePack $pack, ?LlmConfiguration $configuration): array
    {
        $selected = $configuration instanceof LlmConfiguration ? $configuration->getSnippetTagList() : [];

        return array_values(array_filter(
            $this->normalizeTags($pack->getSnippetTags()),
            static fn(string $tag): bool => !in_array($tag, $selected, true),
        ));
    }

    /**
     * Existing configurations OTHER than the pack's own whose prompts the
     * snippets this install would create end up in.
     *
     * Selection is by tag, not by owner: a configuration that already selects
     * `tone_of_voice` composes every active snippet carrying it, including one
     * created a minute ago. That is ADR-031 working as designed and the
     * installer does not prevent it — it reports it, so the operator confirms
     * an effect they were shown.
     *
     * @param list<string> $tags tags of the snippets that would be CREATED;
     *                           snippets already present change nothing
     *
     * @return list<array{identifier: string, name: string}>
     */
    private function configurationsReachedBy(string $packConfigurationIdentifier, array $tags): array
    {
        $tags = $this->normalizeTags($tags);
        if ($tags === []) {
            return [];
        }

        $reached = [];
        foreach ($this->configurationRepository->findAll() as $configuration) {
            if (!$configuration instanceof LlmConfiguration) {
                continue;
            }

            if ($configuration->getIdentifier() === $packConfigurationIdentifier) {
                continue;
            }

            if (array_intersect($configuration->getSnippetTagList(), $tags) === []) {
                continue;
            }

            $reached[] = [
                'identifier' => $configuration->getIdentifier(),
                'name' => $configuration->getName(),
            ];
        }

        return $reached;
    }

    /**
     * Existing snippets the tags this install ADDS would pull INTO the pack's
     * own configuration.
     *
     * The mirror image of {@see self::configurationsReachedBy()}, and the
     * direction with the sharper edge: selection is by tag and not by owner
     * (ADR-031), so adding `tone_of_voice` to the pack's configuration composes
     * every active snippet already carrying that tag — including operator
     * snippets the pack never saw. The tag vocabulary is free-form and shared,
     * so a collision is the normal case rather than an exotic one.
     *
     * The data class is reported with each of them because it is the part that
     * can break a send rather than only change its wording: the input-context
     * classification takes the STRICTEST class over the composed snippets
     * (ADR-115), so one CONFIDENTIAL operator snippet raises the whole
     * configuration and an enforcing trust gate then refuses it.
     *
     * Hidden records are skipped, matching
     * {@see \Netresearch\NrLlm\Service\Prompt\ConfigurationSnippetResolver::selectedSnippets()}
     * — a snippet that is not composed must not be reported as one that would
     * be. The pack's own snippets are skipped too: the plan table above already
     * lists them.
     *
     * @param list<string> $addedTags tags the install would ADD to the pack's
     *                                configuration; tags already selected pull
     *                                in nothing new
     *
     * @return list<array{identifier: string, name: string, dataClass: string}>
     */
    private function snippetsPulledInBy(UseCasePack $pack, array $addedTags): array
    {
        if ($addedTags === []) {
            return [];
        }

        $own = [];
        foreach ($pack->snippets as $packSnippet) {
            $own[strtolower(trim($packSnippet->identifier))] = true;
        }

        $pulled = [];
        $seen = [];
        foreach ($addedTags as $tag) {
            foreach ($this->snippetRepository->findActiveByTag($tag) as $snippet) {
                if ($snippet->isHidden()) {
                    continue;
                }

                $identifier = strtolower(trim($snippet->getIdentifier()));
                if ($identifier !== '' && isset($own[$identifier])) {
                    continue;
                }

                $key = $identifier !== ''
                    ? 'id:' . $identifier
                    : 'uid:' . ($snippet->getUid() ?? spl_object_id($snippet));
                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $pulled[] = [
                    'identifier' => $snippet->getIdentifier(),
                    'name' => $snippet->getName(),
                    'dataClass' => $snippet->getDataClass(),
                ];
            }
        }

        return $pulled;
    }

    /**
     * Trimmed, lowercased, de-duplicated — the same normalization
     * {@see LlmConfiguration::getSnippetTagList()} and
     * {@see PromptSnippet::getTagList()} apply, so both sides of every tag
     * comparison here are in the same shape.
     *
     * @param list<string> $tags
     *
     * @return list<string>
     */
    private function normalizeTags(array $tags): array
    {
        $normalized = [];
        foreach ($tags as $tag) {
            $tag = strtolower(trim($tag));
            if ($tag !== '' && !in_array($tag, $normalized, true)) {
                $normalized[] = $tag;
            }
        }

        return $normalized;
    }

    private function buildTask(PackTask $packTask, LlmConfiguration $configuration): Task
    {
        $task = new Task();
        $task->setIdentifier($packTask->identifier);
        $task->setName($packTask->name);
        $task->setDescription($packTask->description);
        $task->setPromptTemplate($packTask->promptTemplate);
        $task->setCategory($packTask->category->value);
        $task->setInputType($packTask->inputType->value);
        $task->setOutputFormat($packTask->outputFormat->value);
        $task->setConfiguration($configuration);
        $task->setIsActive(true);
        // Not a system task: `is_system` marks records the extension itself
        // ships and maintains. A pack task belongs to the operator the moment
        // it is created — they may edit or delete it like any other.
        $task->setIsSystem(false);

        return $task;
    }

    private function buildSnippet(PackSnippet $packSnippet): PromptSnippet
    {
        $snippet = new PromptSnippet();
        $snippet->setIdentifier($packSnippet->identifier);
        $snippet->setName($packSnippet->name);
        $snippet->setDescription($packSnippet->description);
        $snippet->setSnippet($packSnippet->snippet);
        $snippet->setTags($packSnippet->tagList());
        $snippet->setDataClass($packSnippet->dataClass instanceof ToolDataClass ? $packSnippet->dataClass->value : '');
        // ADR-186: the reader interprets the keys, the installer only stores
        // them. A persona's `voice` and a layout's `imageSize` are the two the
        // consuming extension needs and neither means anything here.
        $snippet->setMetadata($packSnippet->metadataJson());
        $snippet->setIsActive(true);

        return $snippet;
    }
}
