<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\UseCase;

use Netresearch\NrLlm\Domain\DTO\ModelSelectionCriteria;
use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Domain\Enum\TaskCategory;
use Netresearch\NrLlm\Domain\Enum\TaskInputType;
use Netresearch\NrLlm\Domain\Enum\TaskOutputFormat;
use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Service\Governance\GovernanceProfile;
use Netresearch\NrLlm\Service\Preset\ConfigurationPreset;

/**
 * The Editorial Starter pack (ADR-163).
 *
 * The first pack, and for now the only one: four tasks an editor recognises,
 * two snippets that make the tone a decision instead of a habit, one
 * configuration that requires nothing but chat.
 *
 * `chat` is the ONLY required capability on purpose. Every adapter in the
 * extension provides it, so the pack installs against a local Ollama exactly as
 * it does against a hosted provider, and the preflight can only fail when there
 * is genuinely no active model at all.
 *
 * The task prompts take the text through `{{input}}` — the placeholder
 * {@see \Netresearch\NrLlm\Domain\Model\Task::buildPrompt()} substitutes — and
 * each says what to return, because a task whose output shape is a surprise is
 * a task an editor stops using.
 */
final readonly class EditorialStarterPackProvider implements UseCasePackProviderInterface
{
    /**
     * @return list<UseCasePack>
     */
    public function getPacks(): array
    {
        return [
            new UseCasePack(
                identifier: 'editorial-starter',
                useCase: UseCase::EDITORIAL,
                name: 'Editorial Starter',
                description: 'Four everyday editing tasks — summarise, rewrite, proofread, suggest headlines — '
                    . 'on one configuration, with a house-style and an audience snippet composed into every prompt.',
                configurationPreset: new ConfigurationPreset(
                    identifier: 'nr_llm.editorial_starter',
                    name: 'Editorial Starter',
                    // No `allowedToolGroups` here, and the description must not
                    // read as if there were: an empty group list means NO group
                    // restriction, not none allowed. Which tools exist at all
                    // is the Tools module's admin enable.
                    description: 'Configuration for the Editorial Starter tasks: low temperature, room for a '
                        . 'medium-length article, no tool group restriction of its own.',
                    criteria: new ModelSelectionCriteria(capabilities: [ModelCapability::CHAT->value]),
                    systemPrompt: 'You are an editorial assistant for a website. You edit text that has already '
                        . 'been written: you do not invent facts, add claims, or change meaning. Keep the '
                        . "author's voice. When the instruction and the text disagree, follow the instruction "
                        . 'and say so in one short line at the end.',
                    temperature: 0.3,
                    maxTokens: 2000,
                ),
                // RECOMMENDED, never applied (ADR-145). Editorial drafts are
                // unpublished content leaving the installation, which is the
                // posture "controlled cloud" describes.
                recommendedGovernanceProfile: GovernanceProfile::CONTROLLED_CLOUD,
                tasks: [
                    new PackTask(
                        identifier: 'editorial-starter-summarise',
                        name: 'Summarise for a teaser',
                        description: 'Condense an article into a teaser of two to three sentences.',
                        promptTemplate: 'Summarise the text below into a teaser of two to three sentences that '
                            . 'works on its own on an overview page. Keep every factual claim that survives the '
                            . "cut and drop the rest. Do not add a headline.\n\n{{input}}",
                        category: TaskCategory::CONTENT,
                        inputType: TaskInputType::MANUAL,
                        outputFormat: TaskOutputFormat::PLAIN,
                    ),
                    new PackTask(
                        identifier: 'editorial-starter-rewrite',
                        name: 'Rewrite for clarity',
                        description: 'Shorten sentences and remove filler without changing what the text says.',
                        promptTemplate: 'Rewrite the text below so it is easier to read: shorter sentences, '
                            . 'active voice, no filler. Change no facts, no names, no numbers. Return only the '
                            . "rewritten text.\n\n{{input}}",
                        category: TaskCategory::CONTENT,
                        inputType: TaskInputType::MANUAL,
                        outputFormat: TaskOutputFormat::MARKDOWN,
                    ),
                    new PackTask(
                        identifier: 'editorial-starter-proofread',
                        name: 'Proofread',
                        description: 'List spelling, grammar and punctuation corrections without rewriting.',
                        promptTemplate: 'Proofread the text below. List every spelling, grammar and punctuation '
                            . 'correction as a bullet of the form `wrong -> right`, with the sentence it occurs '
                            . 'in. Do not rewrite the text and do not comment on style. If you find nothing, say '
                            . "so in one line.\n\n{{input}}",
                        category: TaskCategory::CONTENT,
                        inputType: TaskInputType::MANUAL,
                        outputFormat: TaskOutputFormat::MARKDOWN,
                    ),
                    new PackTask(
                        identifier: 'editorial-starter-headlines',
                        name: 'Suggest headlines',
                        description: 'Five headline options for an existing text, each under 70 characters.',
                        promptTemplate: 'Suggest five headlines for the text below. Each one must be under 70 '
                            . 'characters, must be supported by the text, and must not be a question. Number '
                            . "them and add nothing else.\n\n{{input}}",
                        category: TaskCategory::CONTENT,
                        inputType: TaskInputType::MANUAL,
                        outputFormat: TaskOutputFormat::MARKDOWN,
                    ),
                ],
                snippets: [
                    new PackSnippet(
                        identifier: 'editorial-starter-house-style',
                        name: 'House style',
                        description: 'The tone every Editorial Starter task writes in. Edit this before anything else.',
                        snippet: 'Write plainly. Prefer short sentences and everyday words. Avoid marketing '
                            . 'superlatives, exclamation marks and rhetorical questions. Use the spelling and '
                            . 'terminology already present in the text.',
                        tags: ['tone_of_voice'],
                        // Editorial guidance about how to write, not content:
                        // it is the least sensitive thing in the pack.
                        dataClass: ToolDataClass::PUBLIC_CONTENT,
                    ),
                    new PackSnippet(
                        identifier: 'editorial-starter-audience',
                        name: 'Target audience',
                        description: 'Who the text is for. Replace the placeholder description with your own.',
                        snippet: 'The audience is a general web visitor with no prior knowledge of the subject. '
                            . 'Explain a technical term the first time it appears, in half a sentence.',
                        tags: ['audience'],
                        dataClass: ToolDataClass::PUBLIC_CONTENT,
                    ),
                ],
                // Named so an admin knows which switch would extend the pack;
                // enabling stays a decision in the Tools module.
                recommendedToolGroups: ['content'],
                // No editor action, deliberately (ADR-168). The four tasks are
                // text transforms an editor runs in the Tasks module; none of
                // them writes a record. And an editor action runs on the
                // DEFAULT configuration, not on the pack's, so this pack's
                // house-style snippet would not reach one — naming an action
                // here would claim a link its records do not have. A Media
                // Accessibility or Translation pack is where the field earns
                // its keep.
                recommendedEditorActions: [],
            ),
        ];
    }
}
