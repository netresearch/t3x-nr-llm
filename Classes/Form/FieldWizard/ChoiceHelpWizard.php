<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Form\FieldWizard;

use Netresearch\NrLlm\Form\ChoiceHelp\ChoiceSummary;
use Netresearch\NrLlm\Form\ChoiceHelp\ChoiceSummaryProvider;
use Netresearch\NrLlm\Form\FieldControl\ForeignTable;
use TYPO3\CMS\Backend\Form\AbstractNode;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Schema\Struct\SelectItem;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * Shows, under a record select, what the chosen option is: the record's
 * description and the facts its label does not show (ADR-255).
 *
 * A native <option> renders one line of text, so the description cannot sit
 * in the option itself. The text follows the selection (ChoiceHelp.js), is
 * tied to the select with aria-describedby and announced politely when the
 * selection changes.
 *
 * Registered as renderType `nrllmChoiceHelp`. Works for selectSingle and
 * selectMultipleSideBySide on the record tables ChoiceSummaryProvider knows.
 * Only the options FormEngine already offers are described.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class ChoiceHelpWizard extends AbstractNode
{
    public function __construct(private readonly ChoiceSummaryProvider $summaryProvider) {}

    /**
     * @return array<string, mixed>
     */
    public function render(): array
    {
        $result = $this->initializeResultArray();

        $options   = ForeignTable::options($this->data, 'fieldWizardOptions');
        $table     = ForeignTable::of($this->data, $options);
        $summaries = $table === '' ? [] : $this->summaryProvider->summarize($table, $this->offeredUids());
        if ($summaries === []) {
            /** @var array<string, mixed> $result */
            return $result;
        }

        $map = [];
        foreach ($summaries as $uid => $summary) {
            $map[(string) $uid] = ['description' => $summary->description, 'facts' => $summary->facts];
        }

        $id      = StringUtility::getUniqueId('nrllm-choice-help-');
        $current = $summaries[$this->currentUid()] ?? null;

        // JSON in an attribute, escaped twice over: json_encode's HEX flags
        // keep markup characters out of the JSON, htmlspecialchars keeps the
        // attribute intact. The script writes the values with textContent.
        $json = json_encode($map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $result['html'] = sprintf(
            '<div class="form-text nrllm-choice-help" id="%s" aria-live="polite" aria-atomic="true" data-nrllm-choice-help="%s">%s</div>',
            htmlspecialchars($id, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($json, ENT_QUOTES, 'UTF-8'),
            $current !== null ? self::markup($current) : '',
        );

        /** @var list<JavaScriptModuleInstruction> $jsModules */
        $jsModules   = $result['javaScriptModules'] ?? [];
        $jsModules[] = JavaScriptModuleInstruction::create('@netresearch/nr-llm/Backend/ChoiceHelp.js')
            ->instance('#' . $id);
        $result['javaScriptModules'] = $jsModules;

        /** @var array<string, mixed> $result */
        return $result;
    }

    /**
     * The same markup ChoiceHelp.js builds, so the first paint needs no script.
     */
    public static function markup(ChoiceSummary $summary): string
    {
        $html = '';
        if ($summary->description !== '') {
            $html .= '<span class="nrllm-choice-help-description d-block">' . htmlspecialchars($summary->description, ENT_QUOTES, 'UTF-8') . '</span>';
        }

        if ($summary->facts !== []) {
            $html .= '<span class="nrllm-choice-help-facts d-block form-description">' . htmlspecialchars(implode(' · ', $summary->facts), ENT_QUOTES, 'UTF-8') . '</span>';
        }

        return $html;
    }

    /**
     * @return list<int>
     */
    private function offeredUids(): array
    {
        $parameterArray = is_array($this->data['parameterArray'] ?? null) ? $this->data['parameterArray'] : [];
        $fieldConf      = is_array($parameterArray['fieldConf'] ?? null) ? $parameterArray['fieldConf'] : [];
        $config         = is_array($fieldConf['config'] ?? null) ? $fieldConf['config'] : [];
        $items          = is_array($config['items'] ?? null) ? $config['items'] : [];

        $uids = [];
        foreach ($items as $item) {
            $value = match (true) {
                $item instanceof SelectItem => $item->getValue(),
                is_array($item)             => $item['value'] ?? null,
                default                     => null,
            };
            if (is_numeric($value) && (int) $value > 0) {
                $uids[] = (int) $value;
            }
        }

        return $uids;
    }

    /**
     * The chosen uid: of a single select, or of a multiple select holding
     * exactly one item; 0 otherwise, and the text appears once an option is
     * chosen.
     */
    private function currentUid(): int
    {
        $parameterArray = is_array($this->data['parameterArray'] ?? null) ? $this->data['parameterArray'] : [];
        $value          = $parameterArray['itemFormElValue'] ?? null;
        if (is_array($value)) {
            $value = count($value) === 1 ? reset($value) : null;
        }

        return is_numeric($value) ? (int) $value : 0;
    }
}
