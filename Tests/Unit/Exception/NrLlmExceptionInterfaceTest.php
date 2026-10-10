<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Exception;

use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Exception\NrLlmExceptionInterface;
use Netresearch\NrLlm\Service\Agent\Exception\AgentRuntimeException;
use Netresearch\NrLlm\Service\Tool\Mcp\Exception\McpTransportException;
use Netresearch\NrLlm\Specialized\Exception\SpecializedServiceException;
use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Throwable;

/**
 * Guards ADR-053 for named source exception classes and statically resolved constructors.
 * Dynamic exception construction and propagated dependency failures need boundary tests.
 */
#[CoversNothing]
final class NrLlmExceptionInterfaceTest extends TestCase
{
    private const EXCEPTION_DIRS = [__DIR__ . '/../../../Classes'];

    #[Test]
    public function everyExceptionClassImplementsTheMarkerInterface(): void
    {
        $checked = [];
        $finder = new NodeFinder();
        foreach ($this->sourceAsts() as [$path, $ast]) {
            require_once $path;
            foreach ($finder->findInstanceOf($ast, Class_::class) as $declaration) {
                if ($declaration->namespacedName === null) {
                    continue;
                }

                /** @var class-string $class */
                $class = $declaration->namespacedName->toString();
                $reflection = new ReflectionClass($class);
                if (!$reflection->isSubclassOf(Throwable::class)) {
                    continue;
                }

                self::assertTrue(
                    $reflection->implementsInterface(NrLlmExceptionInterface::class),
                    sprintf(
                        '%s must implement %s (ADR-053).',
                        $class,
                        NrLlmExceptionInterface::class,
                    ),
                );
                $checked[] = $class;
            }
        }

        self::assertContains(
            AgentRuntimeException::class,
            $checked,
        );
        self::assertContains(
            SpecializedServiceException::class,
            $checked,
        );
        self::assertContains(
            McpTransportException::class,
            $checked,
        );
    }

    #[Test]
    public function chatMessageNormalisationErrorIsCatchableViaTheMarker(): void
    {
        try {
            ChatMessage::fromArray([]);
            self::fail('fromArray([]) must throw');
        } catch (Throwable $e) {
            self::assertInstanceOf(NrLlmExceptionInterface::class, $e);
        }
    }

    #[Test]
    public function toolSpecNormalisationErrorIsCatchableViaTheMarker(): void
    {
        try {
            ToolSpec::fromArray([]);
            self::fail('fromArray([]) must throw');
        } catch (Throwable $e) {
            self::assertInstanceOf(NrLlmExceptionInterface::class, $e);
        }
    }

    #[Test]
    public function toolCallNormalisationErrorIsCatchableViaTheMarker(): void
    {
        try {
            ToolCall::fromArray([]);
            self::fail('fromArray([]) must throw');
        } catch (Throwable $e) {
            self::assertInstanceOf(NrLlmExceptionInterface::class, $e);
        }
    }

    /**
     * @return iterable<array{string, array<Node>}>
     */
    private function sourceAsts(): iterable
    {
        $parser = (new ParserFactory())->createForHostVersion();
        foreach (self::EXCEPTION_DIRS as $dir) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = file_get_contents($file->getPathname());
                self::assertNotFalse($source);
                $ast = $parser->parse($source);
                self::assertNotNull($ast);
                $traverser = new NodeTraverser(new NameResolver());
                yield [$file->getPathname(), $traverser->traverse($ast)];
            }
        }
    }

    #[Test]
    public function directlyConstructedExceptionTypesHaveTheMarker(): void
    {
        $finder = new NodeFinder();
        $violations = [];
        $checkedFiles = 0;
        foreach ($this->sourceAsts() as [$path, $ast]) {
            foreach ($finder->findInstanceOf($ast, New_::class) as $node) {
                $type = $node->class;
                if ($type instanceof Name && is_a($type->toString(), Throwable::class, true) && !is_a($type->toString(), NrLlmExceptionInterface::class, true)) {
                    $violations[] = $path . ':' . $node->getStartLine() . ': ' . $type->toString();
                }

                if ($type instanceof Class_ && $type->extends instanceof Name && is_a($type->extends->toString(), Throwable::class, true) && !is_a($type->extends->toString(), NrLlmExceptionInterface::class, true) && !in_array(
                    NrLlmExceptionInterface::class,
                    array_map(
                        static fn(Name $name): string => $name->toString(),
                        $type->implements,
                    ),
                    true,
                )) {
                    $violations[] = $path . ':' . $node->getStartLine() . ': anonymous exception';
                }
            }

            ++$checkedFiles;
        }

        self::assertGreaterThan(0, $checkedFiles);
        self::assertSame(
            [],
            $violations,
            'Internally constructed exceptions need the marker; native catches for propagated dependency errors remain valid.',
        );
    }
}
