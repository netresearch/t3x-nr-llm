<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use InvalidArgumentException;

/**
 * What a catalogue needs to know about a writing tool to offer it to a human
 * (ADR-152).
 *
 * A tool's {@see ToolSpec} is written FOR THE MODEL: an English wire name and a
 * paragraph of prose that tells a language model when to call it and what it
 * will refuse. None of that belongs in front of an editor, and none of it can
 * be translated. This value object is the second, human-facing declaration —
 * metadata only. It changes nothing about how the tool executes: an editor
 * action IS a {@see \Netresearch\NrLlm\Service\Tool\ToolInterface}, runs on the
 * tool path, and passes the same fence, approval pause and audit as any other
 * write.
 *
 * `$recordTypes` names the table(s) of the record the action's arguments
 * IDENTIFY — the subject a UI would offer the action on — not necessarily the
 * table the write lands in. `set_file_alternative_text` declares `sys_file`
 * because that is the uid it is given and the thing an editor selects; the row
 * it writes is the file's `sys_file_metadata`. A catalogue answers "what can I
 * do with this record?", and the subject is what answers it.
 */
final readonly class EditorAction
{
    /**
     * @param string       $labelKey       `LLL:` key of the action's human name
     * @param string       $descriptionKey `LLL:` key of one or two sentences for a human — NOT the model-facing description
     * @param string       $iconIdentifier an identifier registered in Configuration/Icons.php
     * @param list<string> $recordTypes    the tables whose records this action addresses, machine-readably
     */
    public function __construct(
        public string $labelKey,
        public string $descriptionKey,
        public string $iconIdentifier,
        public array $recordTypes,
    ) {
        if ($labelKey === '' || $descriptionKey === '' || $iconIdentifier === '') {
            throw new InvalidArgumentException(
                'An editor action needs a label key, a description key and an icon identifier.',
                1786406401,
            );
        }

        // An action nothing can place is worse than no declaration: a catalogue
        // that groups by record type would silently drop it.
        if ($recordTypes === [] || in_array('', $recordTypes, true)) {
            throw new InvalidArgumentException(
                'An editor action must name at least one non-empty record type.',
                1786406402,
            );
        }
    }
}
