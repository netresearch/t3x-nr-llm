<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Translation;

use Netresearch\NrLlm\Service\Glossary\ResolvedGlossary;
use Throwable;

/**
 * Turns a site glossary into a DeepL glossary id (ADR-208).
 *
 * Named in the constructor of the public TranslationService, so it carries no
 * internal marker; consumers receive the service from the container and never
 * need to implement it.
 */
interface DeepLGlossarySyncInterface
{
    /**
     * The id of a DeepL glossary holding exactly these terms for this language
     * pair, creating one when the stored one is missing or stale. Null when
     * DeepL cannot hold a glossary for the pair: the translation then runs
     * without one.
     */
    public function glossaryIdFor(ResolvedGlossary $glossary): ?string;

    /**
     * Whether a failed translate call means DeepL did not accept the stored
     * glossary id, so that creating the glossary again can help.
     */
    public function isStaleGlossaryError(Throwable $e): bool;

    /**
     * Forget the stored DeepL glossary of this record and create it again.
     * Null when DeepL cannot hold a glossary for the pair.
     */
    public function recreate(ResolvedGlossary $glossary): ?string;
}
