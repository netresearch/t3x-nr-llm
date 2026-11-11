<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Protocol;

use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * `SendMessageConfiguration` without the push config, which is stage 5b.
 *
 * `returnImmediately` defaults to false, which the proto defines as BLOCKING
 * until a terminal or interrupted state (A2A-D1). The prose of §3.1.1 says the
 * opposite; the proto is normative.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aSendMessageConfiguration
{
    /**
     * @param list<string> $acceptedOutputModes
     */
    public function __construct(
        public bool $returnImmediately = false,
        public array $acceptedOutputModes = [],
        public ?int $historyLength = null,
    ) {
        if ($historyLength !== null && $historyLength < 0) {
            throw new InvalidArgumentException('historyLength must not be negative.', 1799100010);
        }
    }

    public static function returningImmediately(): self
    {
        return new self(returnImmediately: true);
    }

    /**
     * The ProtoJSON form, in proto field order; a default member is left out,
     * an explicit `historyLength` of 0 is kept (proto3 `optional`).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $json = [];
        if ($this->acceptedOutputModes !== []) {
            $json['acceptedOutputModes'] = $this->acceptedOutputModes;
        }

        if ($this->historyLength !== null) {
            $json['historyLength'] = $this->historyLength;
        }

        if ($this->returnImmediately) {
            $json['returnImmediately'] = true;
        }

        return $json;
    }
}
