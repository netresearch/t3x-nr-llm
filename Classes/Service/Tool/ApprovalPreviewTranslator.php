<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Closure;
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
    /** A label reference rather than a literal: `[LLL:]file-or-domain:key`, no spaces. */
    private const REFERENCE = '/^(?:LLL:)?[A-Za-z0-9_.\/-]+:[A-Za-z0-9_.-]+$/';

    public function __construct(
        private LanguageServiceFactory $languageServiceFactory,
    ) {}

    /**
     * @param int|string ...$arguments values for the label's `%s` / `%d` placeholders
     */
    public function text(?BackendUserAuthentication $user, ApprovalPreviewLabel $label, int|string ...$arguments): string
    {
        $text = trim($this->languageServiceFactory->createFromUserPreferences($user)->sL($label->reference()));
        if ($text === '') {
            return $label->value;
        }

        return $arguments === [] ? $text : vsprintf($text, $arguments);
    }

    /**
     * {@see self::text()} and {@see self::quoted()} bound to one acting user,
     * as the two short callables every preview is written with. `$excerpt`
     * flattens and truncates a value before it is quoted. Without an acting
     * user — a read tool's preview can be asked for a run that has none — the
     * texts are the English source, as core gives a user without a language.
     *
     * @param Closure(string): string $excerpt
     *
     * @return array{Closure(ApprovalPreviewLabel, int|string...): string, Closure(string): string}
     */
    public function boundTo(?BackendUserAuthentication $user, Closure $excerpt): array
    {
        return [
            fn(ApprovalPreviewLabel $label, int|string ...$arguments): string => $this->text($user, $label, ...$arguments),
            fn(string $value): string => $this->quoted($user, $excerpt($value)),
        ];
    }

    /**
     * A value as it appears on the card: in the language's quotation marks, or
     * a word for "empty" — an empty pair of quotes reads like a rendering bug.
     * The caller passes the value already flattened and truncated.
     */
    public function quoted(?BackendUserAuthentication $user, string $value): string
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
        $title    = $this->tca($table, 'ctrl', 'title');
        $resolved = $this->label($user, is_string($title) ? $title : '');

        return $resolved !== '' ? $resolved : $table;
    }

    /**
     * A column's name as an editor knows it, from its TCA label in the acting
     * user's language ("Überschrift", not `header`): the record type's
     * `columnsOverrides` label first, the column's own next. The column name
     * itself where neither resolves, so the line never reads empty.
     */
    public function columnLabel(BackendUserAuthentication $user, string $table, string $column, ?string $recordType = null): string
    {
        $override = $recordType === null ? null : $this->tca($table, 'types', $recordType, 'columnsOverrides', $column, 'label');
        $own      = $this->tca($table, 'columns', $column, 'label');

        foreach ([$override, $own] as $label) {
            $resolved = is_string($label) ? rtrim($this->label($user, $label), ':') : '';
            if ($resolved !== '') {
                return $resolved;
            }
        }

        return $column;
    }

    /**
     * The label of the static select item a column holds as `$value`, in the
     * acting user's language ("Zusammenfassung", not `summary`). The value
     * itself where the column declares no such item or its label does not
     * resolve.
     */
    public function itemLabel(BackendUserAuthentication $user, string $table, string $column, string $value): string
    {
        $items = $this->tca($table, 'columns', $column, 'config', 'items');
        foreach (is_array($items) ? $items : [] as $item) {
            if (!is_array($item) || !array_key_exists('value', $item) || !is_scalar($item['value']) || (string)$item['value'] !== $value) {
                continue;
            }

            $resolved = $this->label($user, is_string($item['label'] ?? null) ? $item['label'] : '');

            return $resolved !== '' ? $resolved : $value;
        }

        return $value;
    }

    /**
     * A TCA label in the acting user's language: an `LLL:` reference or, from
     * TYPO3 14 on, a translation domain reference (`frontend.db.tt_content:header`)
     * resolved for that user, a literal as written; '' where an `LLL:`
     * reference resolves to nothing. Core's own `sL()` tells the forms apart.
     */
    public function label(BackendUserAuthentication $user, string $label): string
    {
        $label    = trim($label);
        $resolved = trim($this->languageServiceFactory->createFromUserPreferences($user)->sL($label));

        // TYPO3 14 hands an unresolvable domain reference back unchanged
        // (`LanguageService::sL()` without the `LLL:` prefix); a key on the card
        // reads as a rendering bug, so it counts as no label at all and the
        // caller falls back to the column, table or value name.
        return $resolved === $label && preg_match(self::REFERENCE, $label) === 1 ? '' : $resolved;
    }

    /**
     * The value at a path below `$GLOBALS['TCA']`, or null where the path ends
     * early.
     */
    private function tca(string ...$path): mixed
    {
        $value = $GLOBALS['TCA'] ?? null;
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }
}
