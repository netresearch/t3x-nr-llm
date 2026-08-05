<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionEnum;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use SplFileInfo;
use UnitEnum;

/**
 * Freezes the rendered `@api` surface (ADR-127) in `api-surface.txt`.
 *
 * Two assertions in one pass:
 *
 * 1. **Snapshot**: every `@api`-marked class's declared public signatures,
 *    rendered deterministically, must equal the committed snapshot. An
 *    unintended signature change fails CI before review; an intended one
 *    updates the snapshot in the same PR — the diff is the review artifact.
 * 2. **Closure**: every `Netresearch\NrLlm` type a rendered signature
 *    mentions must itself be `@api`. This is the ADR-127 closure rule —
 *    phpat cannot express it (no docblock selector), the renderer can.
 *
 * Determinism rules (why the rendering looks the way it does):
 * - DECLARED members only (`getDeclaringClass()`), because inherited core
 *   members differ between TYPO3 13.4 and 14.x.
 * - No default values: their `ReflectionParameter` rendering differs
 *   across PHP versions; a default-value change alone is not a signature
 *   break for callers.
 * - Type strings are self-built with sorted union members — never
 *   `(string)$type`, whose format has changed across PHP versions.
 * - `__construct` is excluded: service constructors are DI wiring and out
 *   of contract (`Documentation/Api/Stability.rst`); value-object
 *   construction is covered by the promoted public properties and getters
 *   the snapshot does render.
 *
 * To update intentionally: delete `api-surface.txt`, run the unit suite
 * twice (first run regenerates the file and fails, second is green), and
 * commit the regenerated file together with a CHANGELOG entry when the
 * diff removes or changes a line (that is a break under the 0.x rules).
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

        $rendered = $this->render($apiClasses);

        if (!is_file(self::SNAPSHOT_PATH)) {
            file_put_contents(self::SNAPSHOT_PATH, $rendered);
            self::fail(sprintf(
                'Snapshot regenerated at %s — inspect the diff and commit it. '
                . 'A removed or changed line is a break under the 0.x rules and needs a CHANGELOG entry.',
                self::SNAPSHOT_PATH,
            ));
        }

        $expected = file_get_contents(self::SNAPSHOT_PATH);
        self::assertNotFalse($expected);

        self::assertSame(
            $expected,
            $rendered,
            'The rendered @api surface differs from api-surface.txt. '
            . 'Unintended: revert the signature change. Intended: delete api-surface.txt, '
            . 're-run the unit suite to regenerate it, and commit it (CHANGELOG entry for removed/changed lines).',
        );
    }

    #[Test]
    public function everyTypeInAnApiSignatureIsItselfApi(): void
    {
        $apiClasses = $this->discoverApiClasses();
        $apiSet     = array_fill_keys($apiClasses, true);
        $violations = [];

        foreach ($apiClasses as $fqcn) {
            $reflection = new ReflectionClass($fqcn);
            foreach ($this->declaredPublicMethods($reflection) as $method) {
                foreach ($this->ownTypesOf($method) as $type) {
                    if (!isset($apiSet[$type])) {
                        $violations[] = sprintf('%s::%s() mentions %s', $fqcn, $method->getName(), $type);
                    }
                }
            }

            // Public property types are surface too — a promoted readonly
            // property leaks its type exactly like a getter would.
            foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                if ($property->getDeclaringClass()->getName() !== $reflection->getName()) {
                    continue;
                }

                if ($property->isStatic()) {
                    continue;
                }

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

    // ==================== rendering ====================

    /**
     * @param list<class-string> $classes
     */
    private function render(array $classes): string
    {
        $blocks = [];
        foreach ($classes as $fqcn) {
            $reflection = new ReflectionClass($fqcn);
            $lines      = [sprintf('%s (%s)', $fqcn, $this->kindOf($reflection))];

            foreach ($this->declaredLines($reflection) as $line) {
                $lines[] = '  ' . $line;
            }

            $blocks[] = implode("\n", $lines);
        }

        return implode("\n\n", $blocks) . "\n";
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function kindOf(ReflectionClass $reflection): string
    {
        return match (true) {
            $reflection->isEnum()      => 'enum',
            $reflection->isInterface() => 'interface',
            $reflection->isTrait()     => 'trait',
            default                    => 'class',
        };
    }

    /**
     * @param ReflectionClass<object> $reflection
     *
     * @return list<string>
     */
    private function declaredLines(ReflectionClass $reflection): array
    {
        $lines = [];

        if ($reflection->isEnum()) {
            $enumName = $reflection->getName();
            assert(is_subclass_of($enumName, UnitEnum::class));
            $enum = new ReflectionEnum($enumName);
            foreach ($enum->getCases() as $case) {
                $lines[] = 'case ' . $case->getName();
            }
        }

        foreach ($reflection->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
            if ($constant->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }

            if ($constant->isEnumCase()) {
                continue;
            }

            $value   = $constant->getValue();
            $lines[] = sprintf(
                'const %s = %s',
                $constant->getName(),
                is_scalar($value) || $value === null ? json_encode($value, JSON_THROW_ON_ERROR) : 'array',
            );
        }

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }

            if ($property->isStatic()) {
                continue;
            }

            $lines[] = sprintf(
                'property %s%s: %s',
                $property->isReadOnly() ? 'readonly ' : '',
                $property->getName(),
                $this->renderType($property->getType()),
            );
        }

        foreach ($this->declaredPublicMethods($reflection) as $method) {
            $params = [];
            foreach ($method->getParameters() as $parameter) {
                $params[] = sprintf(
                    '%s%s$%s%s',
                    $this->renderType($parameter->getType()),
                    $parameter->isVariadic() ? ' ...' : ' ',
                    $parameter->getName(),
                    $parameter->isOptional() ? ' = …' : '',
                );
            }

            $lines[] = sprintf(
                'method %s%s(%s): %s',
                $method->isStatic() ? 'static ' : '',
                $method->getName(),
                implode(', ', $params),
                $this->renderType($method->getReturnType()),
            );
        }

        sort($lines);

        return $lines;
    }

    /**
     * @param ReflectionClass<object> $reflection
     *
     * @return list<ReflectionMethod>
     */
    private function declaredPublicMethods(ReflectionClass $reflection): array
    {
        $methods = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }

            if ($method->getName() === '__construct') {
                continue;
            }

            $methods[] = $method;
        }

        return $methods;
    }

    private function renderType(?ReflectionType $type): string
    {
        if (!$type instanceof ReflectionType) {
            return 'mixed';
        }

        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();

            return ($type->allowsNull() && $name !== 'null' && $name !== 'mixed' ? '?' : '') . $name;
        }

        if ($type instanceof ReflectionUnionType) {
            $parts = array_map(
                $this->renderTypeBare(...),
                $type->getTypes(),
            );
            sort($parts);

            return implode('|', $parts);
        }

        if ($type instanceof ReflectionIntersectionType) {
            $parts = array_map(
                $this->renderTypeBare(...),
                $type->getTypes(),
            );
            sort($parts);

            return implode('&', $parts);
        }

        return 'mixed';
    }

    /**
     * Union/intersection members render WITHOUT the nullability marker —
     * `null` appears as its own sorted member instead.
     */
    private function renderTypeBare(ReflectionType $type): string
    {
        if ($type instanceof ReflectionNamedType) {
            return $type->getName();
        }

        if ($type instanceof ReflectionIntersectionType) {
            $parts = array_map(
                $this->renderTypeBare(...),
                $type->getTypes(),
            );
            sort($parts);

            return '(' . implode('&', $parts) . ')';
        }

        return 'mixed';
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
