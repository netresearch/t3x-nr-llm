<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Scope;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\AssignmentScope;

/**
 * The scope a call is held to (ADR-295): the scope the starter bound the run
 * to, capped by the configuration as it is now.
 *
 * Computed for every call and never stored, so a configuration changed while a
 * run waits applies to the call that resumes. Null means the run is not bound.
 * A configuration that requires a scope and a run that has none yield the scope
 * that admits nothing, so every write is refused and the reason is the
 * missing scope.
 *
 * @internal
 */
final class EffectiveAssignmentScope
{
    public static function of(LlmConfiguration $configuration, AiActorContext $actor): ?AssignmentScope
    {
        $scope = $actor->assignmentScope;
        if (!$scope instanceof AssignmentScope) {
            return $configuration->getRequireAssignmentScope() ? AssignmentScope::none() : null;
        }

        return $scope->cappedToDepth($configuration->getAssignmentScopeMaxDepth());
    }

    /**
     * Whether the run has no scope although the configuration requires one.
     */
    public static function isMissing(LlmConfiguration $configuration, AiActorContext $actor): bool
    {
        return $configuration->getRequireAssignmentScope() && !$actor->assignmentScope instanceof AssignmentScope;
    }
}
