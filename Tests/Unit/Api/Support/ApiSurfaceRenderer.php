<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api\Support;

use ReflectionClass;
use ReflectionClassConstant;
use ReflectionEnum;
use ReflectionEnumBackedCase;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use UnitEnum;

/**
 * Renders the public signatures of a set of classes as deterministic text.
 *
 * Extracted from ApiSurfaceSnapshotTest so the rendering rules can be
 * exercised against fixture classes — a snapshot test can only ever prove
 * that today's surface equals yesterday's, never that the renderer would
 * have noticed a given break.
 *
 * Determinism rules (why the rendering looks the way it does):
 * - DECLARED members only (`getDeclaringClass()`), because inherited core
 *   members differ between TYPO3 13.4 and 14.x.
 * - No default values: their `ReflectionParameter` rendering differs
 *   across PHP versions; a default-value change alone is not a signature
 *   break for callers.
 * - Type strings are self-built with sorted union members — never
 *   `(string)$type`, whose format has changed across PHP versions.
 * - Backed-enum cases render WITH their backing value (`case Read = "read"`):
 *   the values are the frozen vocabulary (persisted rows, wire formats), so a
 *   value change under a stable case name must be a visible diff — name-only
 *   lines let it pass green (netresearch/t3x-nr-vault#319 caught this first).
 * - The constructor renders as its own `constructor(...)` line rather than
 *   as a method, because it is keyed and classified separately: a widened
 *   constructor is a break for every value object a consumer builds with
 *   `new`, and the snapshot ignored it entirely until this class existed.
 * - The constructor is the ONE member that is not declared-only. The
 *   cross-version rationale above holds for a parent in TYPO3 core or in a
 *   vendor package, but not for one of ours: four `@api` services inherit
 *   their public constructor from `AbstractSpecializedService`, which is in
 *   this repository and identical on both TYPO3 legs, so a declared-only
 *   rule dropped their effective constructor from the frozen surface —
 *   exactly the break the constructor line exists to catch. It is therefore
 *   recorded from the nearest declaring class whenever that class is inside
 *   `Netresearch\NrLlm`; see {@see declaredPublicConstructor()}.
 */
final class ApiSurfaceRenderer
{
    /**
     * Namespace prefix of classes this repository owns, and therefore
     * controls across the whole CI matrix.
     */
    private const OWN_NAMESPACE_PREFIX = 'Netresearch\\NrLlm\\';

    /**
     * @param list<class-string> $classes
     */
    public function render(array $classes): string
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
     * Declared public methods, constructor excluded.
     *
     * The ADR-127 closure rule ("every type an `@api` signature mentions is
     * itself `@api`") is a statement about what a caller receives, so it runs
     * over these. Constructor parameters are deliberately not in it: a
     * DI-built service is injected with internals by design.
     *
     * @param ReflectionClass<object> $reflection
     *
     * @return list<ReflectionMethod>
     */
    public function declaredPublicMethods(ReflectionClass $reflection): array
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

    /**
     * @param ReflectionClass<object> $reflection
     *
     * @return list<ReflectionProperty>
     */
    public function declaredPublicProperties(ReflectionClass $reflection): array
    {
        $properties = [];
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }

            if ($property->isStatic()) {
                continue;
            }

            $properties[] = $property;
        }

        return $properties;
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
            \assert(\is_subclass_of($enumName, UnitEnum::class));
            $enum = new ReflectionEnum($enumName);
            foreach ($enum->getCases() as $case) {
                // The BACKING VALUE is rendered, not just the case name: for
                // `@api` enums the values are the frozen vocabulary (persisted
                // rows, wire formats, CSV round-trips), and a name-only line
                // let `case Read = 'read'` become `= 'reveal'` with the
                // snapshot staying byte-identical. The enum-case constant
                // path cannot catch it either: `isEnumCase()` rows are
                // skipped below and `getValue()` on a case renders as
                // `array`. json_encode keeps the rendering deterministic.
                $lines[] = $case instanceof ReflectionEnumBackedCase
                    ? sprintf('case %s = %s', $case->getName(), json_encode($case->getBackingValue(), JSON_THROW_ON_ERROR))
                    : 'case ' . $case->getName();
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
                \is_scalar($value) || $value === null ? json_encode($value, JSON_THROW_ON_ERROR) : 'array',
            );
        }

        foreach ($this->declaredPublicProperties($reflection) as $property) {
            $lines[] = sprintf(
                'property %s%s: %s',
                $property->isReadOnly() ? 'readonly ' : '',
                $property->getName(),
                $this->renderType($property->getType(), $reflection->getName()),
            );
        }

        $constructor = $this->declaredPublicConstructor($reflection);
        if ($constructor instanceof ReflectionMethod) {
            $lines[] = sprintf(
                'constructor(%s)',
                // Canonicalised against the DECLARING class, not this one: an
                // inherited constructor's `self` resolves to the parent, and
                // only that comparison turns it back into `self` on the PHP
                // versions that resolve it (see canonicalTypeName()).
                $this->renderParameters($constructor, $constructor->getDeclaringClass()->getName()),
            );
        }

        foreach ($this->declaredPublicMethods($reflection) as $method) {
            $lines[] = sprintf(
                'method %s%s(%s): %s',
                $method->isStatic() ? 'static ' : '',
                $method->getName(),
                $this->renderParameters($method, $reflection->getName()),
                $this->renderType($method->getReturnType(), $reflection->getName()),
            );
        }

        sort($lines);

        return $lines;
    }

    /**
     * The public constructor a caller actually reaches with `new`.
     *
     * Unlike every other member here this is NOT declared-only. `new Foo(...)`
     * binds to whatever constructor `Foo` inherits, so a declared-only rule
     * leaves a class that declares none with no constructor line at all — and
     * then a new required argument added to the parent moves nothing in the
     * snapshot. That is not hypothetical: `DallEImageService`,
     * `FalImageService`, `TextToSpeechService` and `WhisperTranscriptionService`
     * are `@api`, declare no constructor of their own, and inherit a ten-plus
     * argument one from `Specialized\AbstractSpecializedService`, which has
     * gained required arguments more than once.
     *
     * The exclusion that remains is the narrow one the cross-version rationale
     * actually supports: a constructor inherited from OUTSIDE
     * `Netresearch\NrLlm` — TYPO3 core's `AbstractEntity`, `\RuntimeException`
     * — is not ours, and differs between the 13.4 and 14.x legs, so recording
     * it would make the snapshot matrix-dependent.
     *
     * @param ReflectionClass<object> $reflection
     */
    private function declaredPublicConstructor(ReflectionClass $reflection): ?ReflectionMethod
    {
        $constructor = $reflection->getConstructor();

        if (!$constructor instanceof ReflectionMethod) {
            return null;
        }

        if (!$constructor->isPublic()) {
            return null;
        }

        $declaringClass = $constructor->getDeclaringClass()->getName();

        if ($declaringClass === $reflection->getName()) {
            return $constructor;
        }

        return str_starts_with($declaringClass, self::OWN_NAMESPACE_PREFIX) ? $constructor : null;
    }

    private function renderParameters(ReflectionMethod $method, string $selfClass): string
    {
        $params = [];
        foreach ($method->getParameters() as $parameter) {
            $params[] = sprintf(
                '%s%s$%s%s',
                $this->renderType($parameter->getType(), $selfClass),
                $parameter->isVariadic() ? ' ...' : ' ',
                $parameter->getName(),
                $parameter->isOptional() ? ' = …' : '',
            );
        }

        return implode(', ', $params);
    }

    private function renderType(?ReflectionType $type, string $selfClass): string
    {
        if (!$type instanceof ReflectionType) {
            return 'mixed';
        }

        if ($type instanceof ReflectionNamedType) {
            $name = $this->canonicalTypeName($type->getName(), $selfClass);

            return ($type->allowsNull() && $name !== 'null' && $name !== 'mixed' ? '?' : '') . $name;
        }

        if ($type instanceof ReflectionUnionType) {
            $parts = array_map(
                fn(ReflectionType $part): string => $this->renderTypeBare($part, $selfClass),
                $type->getTypes(),
            );
            sort($parts);

            return implode('|', $parts);
        }

        if ($type instanceof ReflectionIntersectionType) {
            $parts = array_map(
                fn(ReflectionType $part): string => $this->renderTypeBare($part, $selfClass),
                $type->getTypes(),
            );
            sort($parts);

            return implode('&', $parts);
        }

        return 'mixed';
    }

    /**
     * PHP versions differ in whether a declared `self` survives reflection
     * or comes back resolved to the class name (observed: 8.2 keeps `self`,
     * 8.5 resolves it). Canonicalise to `self` so the snapshot is identical
     * across the CI matrix. `static` is stable and passes through.
     */
    private function canonicalTypeName(string $name, string $selfClass): string
    {
        return $name === $selfClass ? 'self' : $name;
    }

    /**
     * Union/intersection members render WITHOUT the nullability marker —
     * `null` appears as its own sorted member instead.
     */
    private function renderTypeBare(ReflectionType $type, string $selfClass): string
    {
        if ($type instanceof ReflectionNamedType) {
            return $this->canonicalTypeName($type->getName(), $selfClass);
        }

        if ($type instanceof ReflectionIntersectionType) {
            $parts = array_map(
                fn(ReflectionType $part): string => $this->renderTypeBare($part, $selfClass),
                $type->getTypes(),
            );
            sort($parts);

            return '(' . implode('&', $parts) . ')';
        }

        return 'mixed';
    }
}
