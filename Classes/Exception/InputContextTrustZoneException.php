<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Exception;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use RuntimeException;

/**
 * A call was refused because the context it injects is classified above the
 * trust zone it can reach (ADR-144).
 *
 * The message names three things and no more: the configuration, the zone it
 * resolves to, and WHICH source carries the class. An operator told only
 * "forbidden" has to go looking; an operator told which snippet or skill did it
 * can act. The source is named — never quoted, because the whole premise is
 * that its content is sensitive.
 */
final class InputContextTrustZoneException extends RuntimeException implements NrLlmExceptionInterface
{
    public static function forConfiguration(
        string $configurationIdentifier,
        TrustZone $zone,
        ToolDataClass $declared,
        string $source,
    ): self {
        return new self(
            sprintf(
                'Configuration "%s" resolves to trust zone %s, which permits at most %s, '
                . 'but %s is classified %s. Raise the provider\'s trust zone, remove the source from this '
                . 'configuration, or correct the declared class.',
                $configurationIdentifier,
                $zone->value,
                $zone->maxDataClass()->value,
                $source !== '' ? $source : 'the injected context',
                $declared->value,
            ),
            1786665603,
        );
    }
}
