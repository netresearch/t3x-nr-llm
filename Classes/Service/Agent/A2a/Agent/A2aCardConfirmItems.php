<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Agent;

use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * The one item of the `card_confirm` select: the card the form shows.
 *
 * Its value is {@see A2aRemoteAgent::cardDigest()} of the interface URL,
 * tenant and capabilities the form was built from, so a confirmation names
 * the card the administrator saw. {@see A2aRemoteAgentSaveHook} confirms only
 * when that digest still matches the stored card.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class A2aCardConfirmItems
{
    private const LL = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_a2a_agent.card_confirm.item';

    /**
     * @param array{items: array<int, array{label: string, value: string}>, row?: array<string, mixed>} $params
     */
    public function addItems(array &$params): void
    {
        $row = is_array($params['row'] ?? null) ? $params['row'] : [];
        $url = $this->text($row['interface_url'] ?? null);
        if ($url === '') {
            return;
        }

        $language = $GLOBALS['LANG'] ?? null;
        $label    = $this->text($language instanceof LanguageService ? $language->sL(self::LL) : '');

        $params['items'][] = [
            'label' => sprintf($label !== '' ? $label : 'Confirm: %s', $url),
            'value' => A2aRemoteAgent::cardDigest($url, $this->text($row['interface_tenant'] ?? null), $this->text($row['card_capabilities'] ?? null)),
        ];
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
