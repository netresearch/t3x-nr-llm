<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Context;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;

/**
 * How sensitive the context a call injects is, and which source said so
 * (ADR-144).
 *
 * The class alone is not enough to act on: an operator told that a call was
 * refused because something in it is CONFIDENTIAL still has to find out WHICH
 * something. So the effective class travels with the name of the source that
 * set it — a snippet identifier or a skill name, never its text.
 *
 * @internal
 */
final readonly class InputContextClassification
{
    /**
     * @param ToolDataClass|null $effective the strictest class any injected source declared, or null when nothing declared one
     * @param string             $source    what set it (a snippet identifier or a skill name); empty when nothing did
     */
    private function __construct(
        public ?ToolDataClass $effective,
        public string $source,
    ) {}

    /**
     * Nothing injected carries a declared class.
     *
     * Distinct from PUBLIC_CONTENT: that is a statement, this is the absence of
     * one. The gate treats an absent statement as an absent constraint, which
     * is what keeps installations that never classified anything working
     * exactly as before.
     */
    public static function undeclared(): self
    {
        return new self(null, '');
    }

    public static function of(ToolDataClass $class, string $source): self
    {
        return new self($class, $source);
    }

    /**
     * The stricter of two classifications, keeping the source that set it.
     *
     * An undeclared side never wins: it has nothing to say, so it cannot raise
     * or lower the answer.
     */
    public function withStricter(self $other): self
    {
        if (!$other->effective instanceof ToolDataClass) {
            return $this;
        }

        if (!$this->effective instanceof ToolDataClass) {
            return $other;
        }

        return $this->effective->isAtMost($other->effective) ? $other : $this;
    }

    public function isDeclared(): bool
    {
        return $this->effective instanceof ToolDataClass;
    }
}
