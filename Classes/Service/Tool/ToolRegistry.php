<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use LogicException;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Collects every tool and exposes it to the agent loop.
 *
 * Two sources. Builtin tools arrive through the `nr_llm.tool` tagged iterator
 * and are known when the container compiles. Tools that exist because operator
 * configuration exists — an MCP server's imported catalogue (ADR-116) — arrive
 * through {@see ToolProviderInterface}, whose implementations are themselves
 * compile-fixed: the set of providers is declarative, only the set of tools is
 * not.
 *
 * Builtins are indexed in the constructor, as before: a duplicate name among
 * them is a developer error and still fails fast. Providers are merged on first
 * use instead, because the registry is constructed on paths that have nothing
 * to do with an agent run — a FormEngine itemsProcFunc and the Overview module
 * both build it — and a provider must not be consulted merely because a backend
 * page rendered.
 *
 * A provider-supplied name that collides with an already-indexed one is NOT
 * fatal: it came from operator configuration, and a configuration mistake must
 * not be able to break every page that builds the registry. The colliding tool
 * is dropped and builtins win.
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

    /** @var list<string> */
    private array $builtinNames = [];

    private bool $providersHydrated = false;

    /**
     * @param iterable<ToolInterface>         $tools
     * @param iterable<ToolProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator(ToolInterface::TAG_NAME)]
        iterable $tools = [],
        // Defaulted so the hand-constructions across the test suite keep
        // compiling; production autowires the tagged iterator.
        #[AutowireIterator(ToolProviderInterface::TAG_NAME)]
        private readonly iterable $providers = [],
        private readonly ?LoggerInterface $logger = null,
    ) {
        foreach ($tools as $tool) {
            $name = $tool->getSpec()->name;
            if (isset($this->byName[$name])) {
                throw new LogicException(
                    sprintf('Duplicate tool name "%s".', $name),
                    1782700001,
                );
            }
            // ADR-105 M1: a tool may not be both approval- and input-gated. The
            // approval-resume path carries no user input and would silently drop
            // the mandatory data; the combination is unsupported, so reject it
            // at registration rather than fail open at resume.
            if ($tool instanceof RequiresApprovalInterface && $tool instanceof RequiresInputInterface) {
                throw new LogicException(
                    sprintf(
                        'Tool "%s" may not implement both RequiresApprovalInterface and RequiresInputInterface; combined approval+input pauses are unsupported (ADR-105).',
                        $name,
                    ),
                    1784600104,
                );
            }
            $this->byName[$name] = $tool;
        }

        $this->builtinNames = array_keys($this->byName);
    }

    /**
     * Merge in the provider-supplied tools, once.
     *
     * Deferred, unlike the builtins above: the registry is constructed on paths
     * that have nothing to do with an agent run — a FormEngine itemsProcFunc
     * and the Overview module both build it — and a provider must not be
     * consulted merely because a backend page rendered.
     */
    private function hydrateProviders(): void
    {
        if ($this->providersHydrated) {
            return;
        }
        $this->providersHydrated = true;

        foreach ($this->providers as $provider) {
            foreach ($provider->tools() as $tool) {
                $name = $tool->getSpec()->name;
                if (isset($this->byName[$name])) {
                    // Not fatal, deliberately: the name came from operator
                    // configuration, and a configuration mistake must not take
                    // down every page that builds the registry.
                    $this->logger?->warning('A provided tool was dropped because its name is already taken', [
                        'tool'     => $name,
                        'provider' => $provider::class,
                    ]);

                    continue;
                }

                $this->byName[$name] = $tool;
            }
        }
    }

    public function get(string $name): ?ToolInterface
    {
        $this->hydrateProviders();

        return $this->byName[$name] ?? null;
    }

    /**
     * The names of the compile-time builtin tools only.
     *
     * The coverage tests that guarantee every builtin has a declared data class
     * and a known effect are scoped to this, so a provider-supplied tool can
     * neither satisfy nor break a guarantee that is about code in this
     * repository. Provided tools carry their own assertions.
     *
     * @return list<string>
     */
    public function builtinNames(): array
    {
        // Deliberately does NOT hydrate: this is the compile-time set, and the
        // coverage tests that use it must not be able to see a provider's tools
        // at all.
        return $this->builtinNames;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        $this->hydrateProviders();

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
        $this->hydrateProviders();

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
