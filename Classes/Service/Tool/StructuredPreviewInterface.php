<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\FieldProposal;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Opt-in: a write tool that states, per field, the value stored now and the
 * value a pending call would write, as structured values (ADR-214, item 9).
 *
 * The approval card carries it next to the preview lines of
 * {@see ToolPreviewInterface}, which stay as they are, so a consumer can show
 * "current" and "proposed" without parsing prose. It is additive: a tool that
 * does not implement it gives an empty list, and the lines still describe the
 * call.
 *
 * Contract for implementors:
 *
 * - **Every value comes from the tool.** `current` is read from the database,
 *   `proposed` is the value the call would write after the tool's own
 *   checks, and `label` is the column's TCA label. Nothing is taken from the
 *   model's text but the argument value itself.
 * - **Read as `$reader`, and only what `$reader` may see.** The card is
 *   rendered for one backend user; authorise that user explicitly, never the
 *   ambient `$GLOBALS['BE_USER']` (ADR-083). A record the reader may not
 *   edit, a call the tool would refuse, and a field the reader holds no grant
 *   on give no entry. Return an empty list when in doubt.
 * - **Values are raw.** A rich-text value is the stored HTML; do not
 *   sanitise, excerpt or quote it. The consumer escapes.
 * - **Measure only from configuration.** A range comes from
 *   {@see FieldMeasurer}, never from the model or from a constant of the tool.
 * - **Never write.** It runs every time a card is rendered.
 *
 * It is not part of the approval binding: ADR-184 compares the preview lines,
 * and the write re-checks everything when it runs. `current` is read when the
 * card is rendered, so it can be newer than the lines captured at the pause.
 *
 * @api Extension point: third-party write tools may implement this. No new
 *      abstract member within a major version.
 */
interface StructuredPreviewInterface
{
    /**
     * @param array<string, mixed>      $arguments the model-chosen arguments of the pending call
     * @param BackendUserAuthentication $reader    the backend user the card is rendered for
     *
     * @return list<FieldProposal>
     */
    public function structuredPreview(array $arguments, BackendUserAuthentication $reader): array;
}
