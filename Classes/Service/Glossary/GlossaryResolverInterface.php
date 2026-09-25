<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Glossary;

/**
 * Finds the glossary a site keeps for one language pair (ADR-208).
 *
 * Named in the constructor of the public TranslationService, so it carries no
 * internal marker; consumers receive the service from the container and never
 * need to implement it.
 */
interface GlossaryResolverInterface
{
    /**
     * The glossary for this site and language pair, or null when there is none
     * or it holds no usable term pair.
     *
     * Language codes may carry a region (`de-DE`); only the ISO 639-1 base code
     * takes part in the lookup.
     */
    public function resolve(string $siteIdentifier, string $sourceLanguage, string $targetLanguage): ?ResolvedGlossary;

    /**
     * Record the DeepL glossary created for a glossary record's current terms.
     */
    public function storeDeepLGlossary(int $uid, string $deeplGlossaryId, string $entriesHash): void;

    /**
     * Whether any glossary record other than $exceptUid still points at this
     * DeepL glossary. Deleting a glossary another row still uses — one
     * duplicated at database level, for example — would break that row's
     * translations.
     */
    public function isDeepLGlossaryReferenced(string $deeplGlossaryId, int $exceptUid): bool;
}
