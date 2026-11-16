<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Opt-in for a previewing tool whose stored preview names something its
 * arguments do not: the approvals inbox asks this one instead of
 * {@see ToolPreviewInterface::mayViewerReadPreview()} and hands it the lines
 * it is about to show (ADR-136, ADR-305).
 *
 * The case it exists for is `adopt_client_file`: the arguments name only the
 * client's file number, while the card names the inbox folder the preview
 * was taken for. The folder can change while the run waits, so whether a
 * viewer may read the card has to be decided on the folder the card shows.
 *
 * Contract: pure and read-only; false whenever the answer cannot be
 * established from the lines.
 *
 * @internal
 */
interface StoredPreviewVisibilityInterface
{
    /**
     * Whether the viewer may read these stored preview lines of the call.
     *
     * @param array<string, mixed> $arguments the model-chosen arguments of the call
     * @param list<string>         $lines     the preview lines as they were stored
     */
    public function mayViewerReadStoredPreview(array $arguments, array $lines, BackendUserAuthentication $viewer): bool;
}
