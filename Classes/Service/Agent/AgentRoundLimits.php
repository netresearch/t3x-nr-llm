<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

use function is_array;
use function is_int;
use function is_string;

/**
 * Reads the round limits from the `agent.*` extension settings.
 *
 * `agent.defaultMaxIterations` is the default, `agent.maxIterations` the
 * ceiling. A value that is empty, not a whole number, or below 1 is not a
 * value: the fallback applies, 5 for the default and
 * {@see AgentRuntime::MAX_ITERATIONS} for the ceiling. The settings form checks
 * the range, but a value written into settings.php or additional.php never
 * passes it, so it is checked here again. A default above the ceiling is the
 * ceiling.
 *
 * Nothing is cached: the settings are read on every call, so a value changed
 * in the backend applies to the next run without a restart of anything that
 * holds this service.
 *
 * A null configuration reads as unset and gives the fallbacks, for the
 * positional test wiring of the services that hold this one.
 */
final readonly class AgentRoundLimits implements AgentRoundLimitsInterface
{
    /** The default when none is configured: the tool loop's long-standing default. */
    public const FALLBACK_DEFAULT = 5;

    public function __construct(
        private ?ExtensionConfiguration $extensionConfiguration = null,
    ) {}

    public function defaultRounds(): int
    {
        return min($this->configuredDefault(), $this->ceiling());
    }

    public function ceiling(): int
    {
        return self::positiveIntOr($this->settings()['maxIterations'] ?? null, AgentRuntime::MAX_ITERATIONS);
    }

    public function roundsFor(?int $requested): int
    {
        return $requested !== null && $requested > 0
            ? min($requested, $this->ceiling())
            : $this->defaultRounds();
    }

    /**
     * Whether the configured default is above the ceiling and the ceiling
     * applies instead. For the effective-policy readout, which says so.
     *
     * @internal
     */
    public function defaultIsClamped(): bool
    {
        return $this->configuredDefault() > $this->ceiling();
    }

    private function configuredDefault(): int
    {
        return self::positiveIntOr($this->settings()['defaultMaxIterations'] ?? null, self::FALLBACK_DEFAULT);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function settings(): array
    {
        if (!$this->extensionConfiguration instanceof ExtensionConfiguration) {
            return [];
        }

        try {
            $config = $this->extensionConfiguration->get('nr_llm');
        } catch (Throwable) {
            return [];
        }

        $agent = is_array($config) ? ($config['agent'] ?? null) : null;

        return is_array($agent) ? $agent : [];
    }

    private static function positiveIntOr(mixed $value, int $fallback): int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : $fallback;
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value !== '' && ctype_digit($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return $fallback;
    }
}
