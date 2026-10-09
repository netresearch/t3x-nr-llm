<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * One field a pending write call would change, as structured values: what is
 * stored now and what the call would store (ADR-214, item 9).
 *
 * A consumer renders "current" and "proposed" from it without parsing the
 * preview lines. Every member comes from the TOOL, never from the model's
 * prose: `field` is the TCA column the call writes, `label` the column's TCA
 * label in the reader's language, `current` the value the database holds now
 * (null where the column holds NULL or the record has none yet), `proposed`
 * the value the call would write, after the tool's own normalisation.
 *
 * **The values are raw data and are not sanitised.** A rich-text column
 * (`tt_content.bodytext`) carries its stored HTML as it is, and the proposed
 * value is model-chosen text. A consumer that renders either must escape it,
 * or sanitise it where it shows markup; nothing in nr_llm interprets it.
 *
 * @api
 */
final readonly class FieldProposal
{
    public function __construct(
        public string $field,
        public string $label,
        public ?string $current,
        public string $proposed,
        public ?FieldMeasure $measure = null,
    ) {}

    /**
     * @return array{field: string, label: string, current: string|null, proposed: string, measure: array{count: int, min: int|null, max: int|null}|null}
     */
    public function toArray(): array
    {
        return [
            'field'    => $this->field,
            'label'    => $this->label,
            'current'  => $this->current,
            'proposed' => $this->proposed,
            'measure'  => $this->measure?->toArray(),
        ];
    }
}
