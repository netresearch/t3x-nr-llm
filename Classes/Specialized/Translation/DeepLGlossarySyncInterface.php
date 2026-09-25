<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Translation;

use Netresearch\NrLlm\Service\Glossary\ResolvedGlossary;

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
}
