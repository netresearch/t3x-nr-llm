<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use LogicException;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Collects every DI-tagged ToolInterface and exposes it to the agent loop.
 *
 * Tools are injected through the `nr_llm.tool` tagged iterator and indexed by
 * their spec name; a duplicate name is a developer error and fails fast with a
 * LogicException at construction time.
 *
 * The registry is the authoritative allow-set: `specs()` filters the declared
 * tool specs against an optional allow-list, dropping any name that does not
 * map to a registered tool. An explicit empty allow-list therefore yields no
 * tools, while `null` means "no restriction" and returns every registered
 * spec.
 */
final class ToolRegistry
{
    /** @var array<string, ToolInterface> */
    private array $byName = [];

    /**
     * @param iterable<ToolInterface> $tools
     */
    public function __construct(
        #[AutowireIterator(ToolInterface::TAG_NAME)]
        iterable $tools,
    ) {
        foreach ($tools as $tool) {
            $name = $tool->getSpec()->name;
            if (isset($this->byName[$name])) {
                throw new LogicException(
                    sprintf('Duplicate tool name "%s".', $name),
                    1782700001,
                );
            }
            $this->byName[$name] = $tool;
        }
    }

    public function get(string $name): ?ToolInterface
    {
        return $this->byName[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->byName);
    }

    /**
     * Return the specs of all registered tools, or only those whose name is in
     * `$allowedNames`. Unknown declared names are dropped (the registry is the
     * authoritative allow-set); `[]` yields no specs, `null` yields all.
     *
     * @param list<string>|null $allowedNames
     *
     * @return list<ToolSpec>
     */
    public function specs(?array $allowedNames = null): array
    {
        $specs = [];
        foreach ($this->byName as $name => $tool) {
            if ($allowedNames !== null && !in_array($name, $allowedNames, true)) {
                continue;
            }
            $specs[] = $tool->getSpec();
        }

        return $specs;
    }
}
