<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api;

use FilesystemIterator;
use Netresearch\NrLlm\Tests\Unit\Api\Support\ApiSurfaceDiff;
use Netresearch\NrLlm\Tests\Unit\Api\Support\ApiSurfaceRenderer;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use SplFileInfo;

/**
 * Freezes the rendered `@api` surface (ADR-127) in `api-surface.txt`.
 *
 * Two assertions in one pass:
 *
 * 1. **Snapshot**: every `@api`-marked class's declared public signatures,
 *    rendered deterministically by ApiSurfaceRenderer, must equal the
 *    committed snapshot. An unintended signature change fails CI before
 *    review; an intended one updates the snapshot in the same PR — the diff
 *    is the review artifact.
 * 2. **Closure**: every `Netresearch\NrLlm` type a rendered signature
 *    mentions must itself be `@api`. This is the ADR-127 closure rule —
 *    phpat cannot express it (no docblock selector), the renderer can.
 *
 * The rendering rules live in ApiSurfaceRenderer and are tested against
 * fixture classes in ApiSurfaceRendererTest — a snapshot can only show that
 * today equals yesterday, never that a given break would be caught.
 *
 * The failure message is classified by ApiSurfaceDiff: additive (a new
 * class, method, property) is regenerated and noted; breaking (removed or
 * changed) needs a deliberate decision under
 * `Documentation/Api/Deprecation.rst`.
 *
 * To update intentionally: delete `api-surface.txt`, run the unit suite
 * twice (first run regenerates the file and fails, second is green), and
 * commit the regenerated file together with a CHANGELOG entry.
 */
#[CoversNothing]
final class ApiSurfaceSnapshotTest extends TestCase
{
    private const CLASSES_DIR = __DIR__ . '/../../../Classes';

    private const SNAPSHOT_PATH = __DIR__ . '/api-surface.txt';

    private const NAMESPACE_PREFIX = 'Netresearch\\NrLlm\\';

    #[Test]
    public function renderedApiSurfaceMatchesTheCommittedSnapshot(): void
    {
        $apiClasses = $this->discoverApiClasses();
        self::assertNotSame([], $apiClasses, 'No @api-marked classes found — the marker convention (ADR-127) has been abandoned?');

        $rendered = (new ApiSurfaceRenderer())->render($apiClasses);

        if (!is_file(self::SNAPSHOT_PATH)) {
            file_put_contents(self::SNAPSHOT_PATH, $rendered);
            self::fail(sprintf(
                'Snapshot regenerated at %s — inspect the diff and commit it. '
                . 'A removed or changed line is a break and needs a CHANGELOG entry.',
                self::SNAPSHOT_PATH,
            ));
        }

        $expected = file_get_contents(self::SNAPSHOT_PATH);
        self::assertNotFalse($expected);

        $diff = ApiSurfaceDiff::between($expected, $rendered);

        self::assertSame(
            $expected,
            $rendered,
            "The rendered @api surface differs from Tests/Unit/Api/api-surface.txt.\n\n"
            . $diff->describe(),
        );
    }

    #[Test]
    public function everyTypeInAnApiSignatureIsItselfApi(): void
    {
        $apiClasses = $this->discoverApiClasses();
        $apiSet     = array_fill_keys($apiClasses, true);
        $renderer   = new ApiSurfaceRenderer();
        $violations = [];

        foreach ($apiClasses as $fqcn) {
            $reflection = new ReflectionClass($fqcn);
            foreach ($renderer->declaredPublicMethods($reflection) as $method) {
                foreach ($this->ownTypesOf($method) as $type) {
                    if (!isset($apiSet[$type])) {
                        $violations[] = sprintf('%s::%s() mentions %s', $fqcn, $method->getName(), $type);
                    }
                }
            }

            // Public property types are surface too — a promoted readonly
            // property leaks its type exactly like a getter would.
            foreach ($renderer->declaredPublicProperties($reflection) as $property) {
                foreach ($this->ownNamespaceOnly($this->namedClassTypes($property->getType())) as $type) {
                    if (!isset($apiSet[$type])) {
                        $violations[] = sprintf('%s::$%s mentions %s', $fqcn, $property->getName(), $type);
                    }
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "Types reachable through @api signatures must be @api themselves (ADR-127 closure rule):\n"
            . implode("\n", $violations),
        );
    }

    // ==================== discovery ====================

    /**
     * @return list<class-string>
     */
    private function discoverApiClasses(): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::CLASSES_DIR, FilesystemIterator::SKIP_DOTS),
        );

        $classes = [];
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }

            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            if ($source === false) {
                continue;
            }

            if (preg_match('/^\s*\*\s*@api\b/m', $source) !== 1) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen(self::CLASSES_DIR) + 1, -4);
            $fqcn     = self::NAMESPACE_PREFIX . str_replace('/', '\\', $relative);
            self::assertTrue(
                class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn) || enum_exists($fqcn),
                sprintf('@api-marked file does not autoload as %s', $fqcn),
            );
            $classes[] = $fqcn;
        }

        sort($classes);

        /** @var list<class-string> $classes */
        return $classes;
    }

    // ==================== closure ====================

    /**
     * Own-namespace class names mentioned in the method's signature.
     *
     * @return list<string>
     */
    private function ownTypesOf(ReflectionMethod $method): array
    {
        $types = [];
        foreach ($method->getParameters() as $parameter) {
            $types = [...$types, ...$this->namedClassTypes($parameter->getType())];
        }

        $types = [...$types, ...$this->namedClassTypes($method->getReturnType())];

        return $this->ownNamespaceOnly($types);
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function ownNamespaceOnly(array $names): array
    {
        return array_values(array_unique(array_filter(
            $names,
            static fn(string $name): bool => str_starts_with($name, self::NAMESPACE_PREFIX),
        )));
    }

    /**
     * @return list<string>
     */
    private function namedClassTypes(?ReflectionType $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            return $type->isBuiltin() ? [] : [$type->getName()];
        }

        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            $names = [];
            foreach ($type->getTypes() as $part) {
                $names = [...$names, ...$this->namedClassTypes($part)];
            }

            return $names;
        }

        return [];
    }
}
