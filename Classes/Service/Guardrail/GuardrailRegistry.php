<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail;

use LogicException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Traversable;

/**
 * The identifier-level authority on guardrail policy (ADR-106).
 *
 * Collects both tagged guardrail iterators, and for each logical identifier
 * (shared across the input and output sides) computes the canonical
 * mandatory-ness verdict the {@see GuardrailPolicyResolver} consumes. It fails
 * closed: if one side declares an identifier mandatory and another declares it
 * optional, the build throws — a copy-paste error that flips one secret-redaction
 * class to optional fails the container rather than shipping a one-sided leak.
 *
 * Public because the TCA itemsProcFunc reaches it via GeneralUtility::makeInstance
 * to list the selectable (optional-only) guardrails.
 */
final class GuardrailRegistry
{
    /** @var array<string, bool>|null identifier => true (mandatory); memoised */
    private ?array $mandatory = null;

    /** @var list<string>|null optional-only identifiers, sorted; memoised */
    private ?array $selectable = null;

    /**
     * @param iterable<GuardrailInterface>      $outputGuardrails
     * @param iterable<InputGuardrailInterface> $inputGuardrails
     */
    public function __construct(
        #[AutowireIterator(GuardrailInterface::TAG_NAME)]
        private readonly iterable $outputGuardrails,
        #[AutowireIterator(InputGuardrailInterface::TAG_NAME)]
        private readonly iterable $inputGuardrails,
    ) {}

    /**
     * The authoritative per-identifier verdict. True for a mandatory guardrail
     * that no configuration may disable.
     */
    public function isMandatoryIdentifier(string $identifier): bool
    {
        $this->build();

        return isset($this->mandatory[$identifier]);
    }

    /**
     * The optional-only identifiers, deduplicated and sorted — the source for
     * the per-configuration selection picker. A mandatory identifier is never
     * listed (it cannot be un-selected, so offering it would mislead).
     *
     * @return list<string>
     */
    public function selectableIdentifiers(): array
    {
        $this->build();

        return $this->selectable ?? [];
    }

    private function build(): void
    {
        if ($this->mandatory !== null) {
            return;
        }

        /** @var array<string, array{hasMandatory: bool, hasOptional: bool}> $flags */
        $flags = [];
        foreach ([$this->toList($this->outputGuardrails), $this->toList($this->inputGuardrails)] as $set) {
            foreach ($set as $guardrail) {
                $id           = $guardrail->getIdentifier();
                $flags[$id] ??= ['hasMandatory' => false, 'hasOptional' => false];
                if ($guardrail->isMandatory()) {
                    $flags[$id]['hasMandatory'] = true;
                } else {
                    $flags[$id]['hasOptional'] = true;
                }
            }
        }

        $mandatory  = [];
        $selectable = [];
        foreach ($flags as $id => $flag) {
            if ($flag['hasMandatory'] && $flag['hasOptional']) {
                // Fail closed: a mixed-mandatory identifier must never ship — it
                // would let a config disable one side of a security guardrail.
                throw new LogicException(sprintf(
                    'Guardrail identifier "%s" is declared mandatory on one side and optional on another. '
                    . 'Mandatory-ness is a property of the identifier; set isMandatory() consistently on every '
                    . 'class sharing this slug.',
                    $id,
                ), 1752000000);
            }
            if ($flag['hasMandatory']) {
                $mandatory[$id] = true;
            } else {
                $selectable[] = $id;
            }
        }
        sort($selectable);

        $this->mandatory  = $mandatory;
        $this->selectable = $selectable;
    }

    /**
     * @template T of GuardrailIdentity
     *
     * @param iterable<T> $guardrails
     *
     * @return list<T>
     */
    private function toList(iterable $guardrails): array
    {
        return $guardrails instanceof Traversable ? iterator_to_array($guardrails, false) : array_values($guardrails);
    }
}
