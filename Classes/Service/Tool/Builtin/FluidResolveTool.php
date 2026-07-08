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
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Show which physical Fluid file backs a template / partial / layout name
 * within an extension (ADR-045).
 *
 * Reports the candidate file paths in override order with an exists flag and
 * the winning path, so the model can see why a wrong file resolves or why a
 * name resolves to nothing. Paths only — no file contents, no secrets.
 *
 * Scope note: this resolves an extension's OWN `Resources/Private/{Templates,
 * Partials,Layouts}` paths (the common case). TypoScript-configured override
 * root paths (`plugin.tx_*.view.*RootPaths`) require a live rendering context
 * and are not reflected here — documented in ADR-045.
 */
final readonly class FluidResolveTool implements ToolInterface
{
    use SafeCastTrait;

    /** kind → Resources/Private subfolder. */
    private const SUBFOLDER = [
        'template' => 'Templates',
        'partial'  => 'Partials',
        'layout'   => 'Layouts',
    ];

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'fluid_resolve',
            'Show which physical Fluid file backs a template/partial/layout name in an extension, with the ordered '
            . 'candidate paths and whether each exists — to debug "wrong template wins" or "template not found".',
            [
                'type'       => 'object',
                'properties' => [
                    'name' => [
                        'type'        => 'string',
                        'description' => 'Template/partial/layout name without extension (e.g. "Backend/Playground/List", "MyPartial").',
                    ],
                    'type' => [
                        'type'        => 'string',
                        'enum'        => ['template', 'partial', 'layout'],
                        'description' => 'Which kind to resolve. Defaults to "template".',
                    ],
                    'extension' => [
                        'type'        => 'string',
                        'description' => 'Extension key whose Resources/Private paths to search (e.g. "nr_llm"). Required for resolution.',
                    ],
                ],
                'required' => ['name'],
            ],
        );
    }

    public function execute(array $arguments): string
    {
        $name = trim(self::toStr($arguments['name'] ?? ''));
        if ($name === '') {
            return 'Provide a template/partial/layout name.';
        }
        // Reject path traversal outright — names address files under the
        // extension's private folder, never elsewhere.
        if (str_contains($name, '..')) {
            return 'Invalid name.';
        }
        $name = preg_replace('/\.html$/i', '', $name) ?? $name;

        $type = strtolower(trim(self::toStr($arguments['type'] ?? 'template')));
        if (!isset(self::SUBFOLDER[$type])) {
            $type = 'template';
        }

        $extension = trim(self::toStr($arguments['extension'] ?? ''));
        if ($extension === '') {
            return 'Provide the "extension" key so the Fluid root paths can be resolved.';
        }
        if (preg_match('/^[a-z0-9_]+$/', $extension) !== 1) {
            return 'Invalid extension key.';
        }

        $subfolder = self::SUBFOLDER[$type];
        $candidate = sprintf('EXT:%s/Resources/Private/%s/%s.html', $extension, $subfolder, $name);
        $absolute  = GeneralUtility::getFileAbsFileName($candidate);

        if ($absolute === '') {
            return sprintf('Extension "%s" is not resolvable (not installed?).', $extension);
        }

        $exists   = is_file($absolute);
        $relative = $this->projectRelative($absolute);

        $lines = [
            sprintf('Fluid %s "%s" in EXT:%s', $type, $name, $extension),
            '',
            'Candidates (override order, first existing wins):',
            sprintf('  [%s] %s', $exists ? 'x' : ' ', $relative),
        ];

        $lines[] = '';
        $lines[] = $exists
            ? sprintf('Resolved: %s', $relative)
            : 'Resolved: (none — no candidate file exists)';
        $lines[] = '';
        $lines[] = 'Note: TypoScript-configured override root paths are not included (they need a live rendering context).';

        return implode("\n", $lines);
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    public function requiresAdmin(): bool
    {
        return false;
    }

    public function getGroup(): string
    {
        return 'configuration';
    }

    private function projectRelative(string $absolute): string
    {
        $root = rtrim(Environment::getProjectPath(), '/') . '/';

        return str_starts_with($absolute, $root)
            ? substr($absolute, strlen($root))
            : $absolute;
    }
}
