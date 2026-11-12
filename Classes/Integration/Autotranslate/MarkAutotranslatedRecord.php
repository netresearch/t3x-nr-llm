<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Integration\Autotranslate;

use Netresearch\NrLlm\EventListener\MarkAiWrittenRecord;
use ThieleUndKlose\Autotranslate\Event\AfterTranslateEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;

use function in_array;

/**
 * Sets nr-llm's AI-written marker (ADR-239) on a page or content element that
 * autotranslate machine-translated through nr-llm (ADR-289).
 *
 * The mark joins the translated columns, so autotranslate stores it in the
 * same write as the text: a translation that fails stores neither.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class MarkAutotranslatedRecord
{
    private const TABLES = ['pages', 'tt_content'];

    public function __construct(
        private AutotranslateSettings $settings,
    ) {}

    #[AsEventListener(identifier: 'nr-llm/mark-autotranslated-record')]
    public function __invoke(AfterTranslateEvent $event): void
    {
        if (!in_array($event->getTable(), self::TABLES, true) || !$this->settings->routesThroughNrLlm() || !MachineTranslatedText::in($event)) {
            return;
        }

        $event->setTranslatedColumn(MarkAiWrittenRecord::FIELD, 1);
    }
}
