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
 * arguments do not (ADR-136, ADR-305).
 *
 * The case it exists for is `adopt_client_file`: the arguments name only the
 * client's file number, while the card names the inbox folder the preview
 * was taken for. The folder can change while the run waits, so whether a
 * viewer may read the card has to be decided on the folder the card shows.
 *
 * The loop stores {@see self::previewSubject()} with the preview lines, as a
 * field of its own; the approvals inbox then asks
 * {@see self::mayViewerReadStoredPreview()} with that field instead of
 * {@see ToolPreviewInterface::mayViewerReadPreview()}. The decision never
 * reads the lines: they carry text a remote party chose.
 *
 * Contract: pure and read-only; false whenever the answer cannot be
 * established from the subject, including when there is none.
 *
 * @internal
 */
interface StoredPreviewVisibilityInterface
{
    /**
     * What the preview of this call is about, as a structured identifier the
     * tool can check a viewer against later (for `adopt_client_file`, the
     * canonical inbox folder); null when there is none. Asked when the
     * preview is taken, in the same context.
     *
     * @param array<string, mixed> $arguments the model-chosen arguments of the call
     */
    public function previewSubject(array $arguments, ToolExecutionContext $context): ?string;

    /**
     * Whether the viewer may read the stored preview of the call whose
     * subject was stored as `$subject`.
     *
     * @param array<string, mixed> $arguments the model-chosen arguments of the call
     */
    public function mayViewerReadStoredPreview(array $arguments, ?string $subject, BackendUserAuthentication $viewer): bool;
}
