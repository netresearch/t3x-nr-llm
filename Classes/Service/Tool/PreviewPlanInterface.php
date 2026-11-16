<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Opt-in for a previewing tool that must carry out exactly what its card
 * showed (ADR-184, ADR-305).
 *
 * When the preview is taken, the tool names its plan as structured data:
 * string keys, string values, such as the folder and the name a file is
 * written as. The loop stores the plan with the preview as a field of its
 * own, the comparison at resume treats a changed plan like changed lines,
 * and an approved call receives the plan its approver saw in
 * {@see ToolExecutionContext::$approvedPlan}. The approvals inbox asks
 * {@see self::mayViewerReadStoredPreview()} with the stored plan instead of
 * {@see ToolPreviewInterface::mayViewerReadPreview()}.
 *
 * Nothing is ever derived from the lines: they are what a person reads, and
 * they carry text a remote party chose.
 *
 * Contract: both methods pure and read-only; false whenever the answer
 * cannot be established from the plan, including when there is none.
 *
 * @internal
 */
interface PreviewPlanInterface
{
    /**
     * What the call would do, as structured data, or null when it cannot be
     * carried out. Asked when the preview is taken, in the same context.
     *
     * @param array<string, mixed> $arguments the model-chosen arguments of the call
     *
     * @return array<string, string>|null
     */
    public function previewPlan(array $arguments, ToolExecutionContext $context): ?array;

    /**
     * Whether the viewer may read the stored preview of the call whose plan
     * was stored as `$plan`.
     *
     * @param array<string, mixed>       $arguments the model-chosen arguments of the call
     * @param array<string, string>|null $plan
     */
    public function mayViewerReadStoredPreview(array $arguments, ?array $plan, BackendUserAuthentication $viewer): bool;
}
