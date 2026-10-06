<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * Turns an {@see ApprovalPreviewLabel} into text in the language of the
 * ACTING backend user of the run (ADR-213).
 *
 * The preview is produced when the run suspends, persisted with the
 * suspended state, and compared line by line when the run resumes
 * ({@see ApprovalPreviewComparator}, ADR-184). Its language therefore has to be
 * a function of the run, not of whoever renders the card or happens to run the
 * resume. The acting user is the only identity that is the same at suspend and
 * at resume, on the synchronous path and in the queue worker alike
 * ({@see ToolExecutionContext}), so the language is read from THAT user's
 * `lang` column and never from the ambient `$GLOBALS['LANG']`.
 *
 * A language with no catalogue of its own resolves to the English source text,
 * as every other label of this extension does. A label with no text at all
 * resolves to its key, so a gap is visible on the card instead of an empty
 * line; the catalogue test makes that case unreachable in a released build.
 *
 * @internal
 */
final readonly class ApprovalPreviewTranslator
{
    public function __construct(
        private LanguageServiceFactory $languageServiceFactory,
    ) {}

    /**
     * @param int|string ...$arguments values for the label's `%s` / `%d` placeholders
     */
    public function text(BackendUserAuthentication $user, ApprovalPreviewLabel $label, int|string ...$arguments): string
    {
        $text = trim($this->languageServiceFactory->createFromUserPreferences($user)->sL($label->reference()));
        if ($text === '') {
            return $label->value;
        }

        return $arguments === [] ? $text : vsprintf($text, $arguments);
    }

    /**
     * A value as it appears on the card: in the language's quotation marks, or
     * a word for "empty" — an empty pair of quotes reads like a rendering bug.
     * The caller passes the value already flattened and truncated.
     */
    public function quoted(BackendUserAuthentication $user, string $value): string
    {
        return $value === ''
            ? $this->text($user, ApprovalPreviewLabel::ValueEmpty)
            : $this->text($user, ApprovalPreviewLabel::ValueQuoted, $value);
    }

    /**
     * The last line of a preview: the identifiers an editor does not need but
     * support does, and that keep the approval bound to the exact records the
     * call names (ADR-184 compares the lines, and two pages can share a title).
     *
     * @param non-empty-list<string> $parts already translated
     */
    public function technical(BackendUserAuthentication $user, array $parts): string
    {
        return $this->text($user, ApprovalPreviewLabel::TechnicalDetails, implode(', ', $parts));
    }

    /**
     * A table's name as an editor knows it, from its TCA title in the acting
     * user's language ("Seiteninhalt", not `tt_content`). The table name itself
     * where the TCA declares no title or the label does not resolve, so the
     * line never reads empty.
     */
    public function tableLabel(BackendUserAuthentication $user, string $table): string
    {
        $tca        = $GLOBALS['TCA'] ?? null;
        $definition = is_array($tca) && is_array($tca[$table] ?? null) ? $tca[$table] : [];
        $ctrl       = is_array($definition['ctrl'] ?? null) ? $definition['ctrl'] : [];
        $title      = is_string($ctrl['title'] ?? null) ? trim($ctrl['title']) : '';
        if ($title === '') {
            return $table;
        }

        if (!str_starts_with($title, 'LLL:')) {
            return $title;
        }

        $resolved = trim($this->languageServiceFactory->createFromUserPreferences($user)->sL($title));

        return $resolved !== '' ? $resolved : $table;
    }
}
