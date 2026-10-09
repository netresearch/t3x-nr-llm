<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill;

use Netresearch\NrLlm\Domain\ValueObject\SkillPin;

/**
 * What a load decided (ADR-214 item 4): the tool-result text the model reads
 * — a confirmation or a refusal, never the body — and, for a new load, the
 * section the loop writes into the system message with the pin it records.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class SkillLoadOutcome
{
    private function __construct(
        public string $message,
        public bool $isError,
        public string $section = '',
        public ?SkillPin $pin = null,
        public string $skillName = '',
    ) {}

    public static function refused(string $message): self
    {
        return new self($message, true);
    }

    public static function alreadyLoaded(string $message): self
    {
        return new self($message, false);
    }

    public static function loaded(string $message, string $section, SkillPin $pin, string $skillName): self
    {
        return new self($message, false, $section, $pin, $skillName);
    }

    public function appendsSection(): bool
    {
        return $this->pin instanceof SkillPin && $this->section !== '';
    }
}
