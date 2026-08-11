<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\UseCase;

/**
 * The question onboarding starts with: what do you want to do? (ADR-163).
 *
 * The cases are the answers an operator recognises before they know what a
 * provider is. They are a vocabulary for grouping packs, nothing else — no
 * behaviour is switched on a case, and a use case with no pack is a legitimate
 * state the entry step reports rather than hides.
 *
 * @internal
 */
enum UseCase: string
{
    /**
     * Drafting, rewriting, proofreading and summarising editorial copy.
     */
    case EDITORIAL = 'editorial';

    /**
     * Translating existing content into further site languages.
     */
    case TRANSLATION = 'translation';

    /**
     * Titles, descriptions, keywords and other page metadata.
     */
    case METADATA = 'metadata';

    /**
     * Alternative texts, captions and transcripts for media.
     */
    case MEDIA_ACCESSIBILITY = 'media-accessibility';

    /**
     * Multi-step agent runs with tools and approvals.
     */
    case AGENT_WORKFLOWS = 'agent-workflows';

    /**
     * Using nr_llm as a provider layer from your own extension code.
     */
    case DEVELOPER_INTEGRATION = 'developer-integration';

    /**
     * Named `get…` because Fluid reaches a method only through the get/is/has
     * convention: `{useCase.labelKey}` resolves `getLabelKey()`, and a plain
     * `labelKey()` silently yields null — which reaches `f:translate` as an
     * empty key and throws at render time.
     */
    public function getLabelKey(): string
    {
        return 'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:usecase.' . $this->name;
    }

    public function getDescriptionKey(): string
    {
        return $this->getLabelKey() . '.description';
    }
}
