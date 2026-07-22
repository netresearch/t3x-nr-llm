<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Traversable;

/**
 * Narrows a guardrail collection to what a configuration permits (ADR-106) —
 * the single filter used by the output, input and streaming application points,
 * so all three honour the same policy and the same mandatory floor.
 *
 * The rule, once: DROP a guardrail only if the configuration has a NON-empty
 * selection AND the guardrail's identifier is not registry-mandatory AND the
 * identifier is not in the selection. Consequences: a null configuration or an
 * empty selection means "no restriction" (all run, unchanged from before this
 * feature); a mandatory guardrail is kept unconditionally against ANY selection
 * value; an unknown selected id is ignored.
 */
final readonly class GuardrailPolicyResolver
{
    public function __construct(
        private GuardrailRegistry $registry,
    ) {}

    /**
     * @template T of GuardrailIdentity
     *
     * @param iterable<T> $guardrails
     *
     * @return list<T>
     */
    public function filter(iterable $guardrails, ?LlmConfiguration $configuration): array
    {
        $all = $guardrails instanceof Traversable ? iterator_to_array($guardrails, false) : array_values($guardrails);

        if ($configuration === null) {
            return $all;
        }

        $selection = $configuration->getAllowedGuardrailsList();
        if ($selection === []) {
            return $all;
        }

        // The KEEP predicate's first term is the registry's identifier-level
        // verdict, so no selection value — empty, partial, hostile, unknown, or
        // all-unknown — can drop a mandatory guardrail on any axis.
        return array_values(array_filter(
            $all,
            fn(GuardrailIdentity $guardrail): bool => $this->registry->isMandatoryIdentifier($guardrail->getIdentifier())
                || in_array($guardrail->getIdentifier(), $selection, true),
        ));
    }
}
