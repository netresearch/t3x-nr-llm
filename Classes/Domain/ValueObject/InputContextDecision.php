<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\TrustZone;

/**
 * The outcome of evaluating the input-context gate for one configuration
 * (ADR-144), as a value rather than as a thrown exception (ADR-157).
 *
 * {@see \Netresearch\NrLlm\Service\Context\InputContextTrustGate::assertPermitted()}
 * throws, which answers a caller but not an operator: observe mode does not
 * throw AT ALL, so "no exception" covers both a permitted send and one the
 * runtime records as blocked and lets through. Catching the exception in a
 * simulator would therefore report "allowed" for a configuration the audit says
 * was refused. This carries the distinction instead.
 *
 * The sibling of {@see ToolPolicyDecision} across the other direction of the
 * same axis: that one says what a tool may READ into a run, this one what the
 * run may SEND.
 *
 * **Nothing declared means nothing was consulted.** `zone` and `ceiling` are
 * null there, not filled with the zone that was never asked about — the same
 * discipline {@see RoutingReadout} applies to a fixed-mode configuration. It is
 * also why the gate can keep its early return: resolving a zone walks the
 * fallback chain through the repository, and an undeclared configuration must
 * not pay for a comparison it never makes.
 *
 * @internal
 */
final readonly class InputContextDecision
{
    /**
     * @param ToolDataClass|null $declaredClass the strictest class among the injected sources,
     *                                          null when nothing is classified
     * @param string             $source        which snippet or skill carries it — never its text
     *                                          (see {@see \Netresearch\NrLlm\Service\Context\InputContextClassifier})
     * @param TrustZone|null     $zone          null when nothing was declared, so no zone was resolved
     * @param ToolDataClass|null $ceiling       what that zone permits at most, null for the same reason
     * @param bool               $zoneRefused   the zone does not permit the declared class
     * @param bool|null          $enforcing     null unless the zone refused: the switch is read only then
     */
    private function __construct(
        public ?ToolDataClass $declaredClass,
        public string $source,
        public ?TrustZone $zone,
        public ?ToolDataClass $ceiling,
        public bool $zoneRefused,
        public ?bool $enforcing,
    ) {}

    /**
     * No snippet and no skill carries a classification, so nothing constrains
     * the send.
     */
    public static function undeclared(): self
    {
        return new self(null, '', null, null, false, null);
    }

    public static function permitted(ToolDataClass $declaredClass, string $source, TrustZone $zone): self
    {
        return new self($declaredClass, $source, $zone, $zone->maxDataClass(), false, null);
    }

    public static function refused(ToolDataClass $declaredClass, string $source, TrustZone $zone, bool $enforcing): self
    {
        return new self($declaredClass, $source, $zone, $zone->maxDataClass(), true, $enforcing);
    }

    /**
     * Whether the send proceeds. In observe mode a refused configuration still
     * proceeds — {@see self::isObservedOnly()} is what separates the two.
     *
     * Named `is…` so Fluid reaches it as `{decision.permitted}`.
     */
    public function isPermitted(): bool
    {
        return !$this->zoneRefused || $this->enforcing === false;
    }

    /**
     * The zone refused, and enforcement let the send through anyway. The
     * runtime records a `context_blocked` governance event for exactly this
     * case, which is why "no exception" cannot be read as "permitted".
     */
    public function isObservedOnly(): bool
    {
        return $this->zoneRefused && $this->enforcing === false;
    }

    /**
     * A short, admin-readable explanation. Names the source, never its text —
     * the classification exists because that text is sensitive.
     */
    public function message(): string
    {
        if (!$this->declaredClass instanceof ToolDataClass) {
            return 'No snippet or skill this configuration injects carries a data class, so the input-context gate constrains nothing.';
        }

        $zone    = $this->zone instanceof TrustZone ? $this->zone->value : '';
        $ceiling = $this->ceiling instanceof ToolDataClass ? $this->ceiling->value : '';

        if (!$this->zoneRefused) {
            return sprintf(
                'The injected context is classified %s, which a provider in the "%s" trust zone may receive (ceiling: %s). Declared by %s.',
                $this->declaredClass->value,
                $zone,
                $ceiling,
                $this->source,
            );
        }

        return sprintf(
            'The injected context is classified %s, which exceeds what a provider in the "%s" trust zone may receive (ceiling: %s). Declared by %s.%s',
            $this->declaredClass->value,
            $zone,
            $ceiling,
            $this->source,
            $this->isObservedOnly() ? ' Enforcement is in observe mode, so the send would proceed and be recorded as blocked.' : '',
        );
    }
}
