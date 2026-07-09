<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Package\PackageManager;

/**
 * List the installed TYPO3 extensions (ADR-048).
 *
 * One line per active package: extension key, version, composer name and
 * title — the inventory a model needs to reason about "which extension could
 * cause X" or "is Y installed at all".
 *
 * Security contract (see {@see ToolInterface}): admin-only — the extension
 * inventory enumerates the instance's attack surface. Package PATHS never
 * egress; the listing is naturally bounded by the package count.
 */
final readonly class ListExtensionsTool implements ToolInterface
{
    use SafeCastTrait;

    public function __construct(
        private PackageManager $packageManager,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'list_extensions',
            'List the installed (active) TYPO3 extensions: extension key, version, composer name and title.',
            [
                'type'       => 'object',
                'properties' => [],
            ],
        );
    }

    public function execute(array $arguments): string
    {
        $lines = [];
        foreach ($this->packageManager->getActivePackages() as $package) {
            $meta     = $package->getPackageMetaData();
            $composer = self::toStr($package->getValueFromComposerManifest('name'));
            $title    = self::toStr($meta->getTitle() ?? '');

            $lines[$package->getPackageKey()] = sprintf(
                '- %s %s%s%s',
                $package->getPackageKey(),
                self::toStr($meta->getVersion()) ?: '(no version)',
                $composer !== '' ? sprintf(' (%s)', $composer) : '',
                $title !== '' ? sprintf(' — %s', $title) : '',
            );
        }

        if ($lines === []) {
            return 'No active packages.';
        }

        ksort($lines);

        return sprintf("Active extensions (%d):\n", count($lines)) . implode("\n", $lines);
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    public function requiresAdmin(): bool
    {
        // Admin-only: the extension inventory enumerates the attack surface.
        return true;
    }

    public function getGroup(): string
    {
        return 'system';
    }
}
