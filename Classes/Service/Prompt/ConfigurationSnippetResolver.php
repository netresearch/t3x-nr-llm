<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Prompt;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;

/**
 * Composes the snippets a configuration selects by tag (ADR-031, extended by
 * ADR-139) onto the effective system prompt.
 *
 * This is the only reader that turns a stored `snippet_tags` selection into
 * prompt text. It appends rather than prepends: the configuration's own system
 * prompt keeps the lead, and the snippet block is the editorial context added
 * behind it. Composing into the system prompt — instead of emitting extra
 * system messages — is what keeps the assembly order pinned by #637 intact:
 * a second system message ahead of the configuration's prompt would satisfy
 * {@see \Netresearch\NrLlm\Service\MessageShaper::applySystemPrompt()}'s guard
 * and silently drop that prompt from the run.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class ConfigurationSnippetResolver
{
    public function __construct(
        private PromptSnippetRepository $promptSnippetRepository,
        private PromptSnippetComposer $promptSnippetComposer,
        private SnippetCallerMark $callerMark = new SnippetCallerMark(),
    ) {}

    /**
     * Append the configuration's marked snippets to a caller's own system
     * message (ADR-214 item 8).
     *
     * A caller that sends a system message suppresses the configuration's
     * system prompt and its snippets (ADR-031, MessageShaper). A snippet still
     * joins that caller's first system message when both hold: the
     * configuration opts in, and an administrator marked the snippet's
     * current block. Returns the messages unchanged otherwise, and when the
     * caller sent no system message — the configuration prompt carries the
     * snippets then.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     *
     * @return list<ChatMessage|array<string, mixed>>
     */
    public function appendToCallerSystemMessage(array $messages, LlmConfiguration $configuration): array
    {
        if (!$configuration->isSnippetsInCallerSystemMessage()) {
            return $messages;
        }

        foreach ($messages as $index => $message) {
            $role = $message instanceof ChatMessage ? $message->role : ($message['role'] ?? null);
            // Only the head carries the block — the messages before the first
            // user turn, which the context fit keeps and where the skill
            // sections go too; a system message after it may be dropped.
            if ($role === 'user') {
                return $messages;
            }

            if ($role !== 'system') {
                continue;
            }

            $content = $message instanceof ChatMessage ? $message->content : ($message['content'] ?? null);
            if (!is_string($content)) {
                // A part list: nothing is appended into a shape this cannot
                // extend faithfully.
                return $messages;
            }

            $block = $this->callerSystemBlock($configuration);
            if ($block === '') {
                return $messages;
            }

            $joined           = $content === '' ? $block : $content . "\n\n" . $block;
            $messages[$index] = $message instanceof ChatMessage ? ChatMessage::system($joined) : ['role' => 'system', 'content' => $joined] + $message;

            return $messages;
        }

        return $messages;
    }

    /**
     * The block of the selected snippets whose current block is marked, in
     * composition order.
     */
    public function callerSystemBlock(LlmConfiguration $configuration): string
    {
        $blocks = [];
        foreach ($this->selectedSnippets($configuration) as $snippet) {
            if (!$this->callerMark->isMarked($snippet)) {
                continue;
            }

            $block = $this->callerMark->blockOf($snippet);
            if ($block !== '') {
                $blocks[] = $block;
            }
        }

        return implode("\n\n", $blocks);
    }

    /**
     * Append the configuration's tag-selected snippets to the given system
     * prompt.
     *
     * Returns `$systemPrompt` unchanged when the configuration selects no tags,
     * when no active snippet carries any of them (an unknown tag is empty, not
     * an error), or when every matching snippet has empty text — so a
     * configuration without tags behaves byte-identically to before the field
     * existed.
     */
    public function appendTo(string $systemPrompt, LlmConfiguration $configuration): string
    {
        $block = $this->compose($configuration);
        if ($block === '') {
            return $systemPrompt;
        }

        return $systemPrompt === '' ? $block : $systemPrompt . "\n\n" . $block;
    }

    /**
     * Render the selected snippets as labeled blocks, in tag-selection order
     * and, within a tag, in the repository's own order (sorting, then name).
     *
     * A snippet carrying two selected tags is rendered once:
     * {@see PromptSnippetRepository::findActiveByTag()} filters in PHP over all
     * active snippets, so the same record comes back from every matching tag.
     * The dedup key is the snippet identifier, falling back to the uid for
     * records that carry none.
     *
     * Hidden records are dropped here rather than in the repository: every
     * repository in this extension ignores enable fields on purpose (the
     * backend modules list hidden records, and `is_active` is the runtime
     * switch), so the query must keep returning them. Editorial and operational
     * roles are separate, though — an editor who hides a snippet in the list
     * module must not keep shipping it into every production prompt — so the
     * one reader that turns snippets into prompt text filters them out.
     */
    private function compose(LlmConfiguration $configuration): string
    {
        $blocks = [];

        foreach ($this->selectedSnippets($configuration) as $snippet) {
            // One snippet per composer call: the composer keys its sections
            // by label, so two snippets sharing a name would collide in a
            // single call and one would be dropped.
            $block = $this->promptSnippetComposer->composeSections([$snippet->getName() => $snippet]);
            if ($block !== '') {
                $blocks[] = $block;
            }
        }

        return implode("\n\n", $blocks);
    }

    /**
     * The snippet RECORDS this configuration composes, in composition order.
     *
     * Split out of {@see self::compose()} so the classification gate (ADR-144)
     * asks the same question about the same records rather than re-deriving the
     * selection. A second implementation of "which snippets does this
     * configuration send" would be a gate that can disagree with the prompt it
     * is guarding.
     *
     * @return list<PromptSnippet>
     */
    public function selectedSnippets(LlmConfiguration $configuration): array
    {
        $selected = [];
        $seen     = [];

        foreach ($configuration->getSnippetTagList() as $tag) {
            foreach ($this->promptSnippetRepository->findActiveByTag($tag) as $snippet) {
                if ($snippet->isHidden()) {
                    continue;
                }

                $key = $this->dedupKey($snippet);
                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $selected[] = $snippet;
            }
        }

        return $selected;
    }

    private function dedupKey(PromptSnippet $snippet): string
    {
        $identifier = trim($snippet->getIdentifier());
        if ($identifier !== '') {
            return 'id:' . strtolower($identifier);
        }

        return 'uid:' . ($snippet->getUid() ?? spl_object_id($snippet));
    }
}
