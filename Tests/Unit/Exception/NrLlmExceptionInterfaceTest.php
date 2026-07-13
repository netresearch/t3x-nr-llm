<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Exception;

use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Exception\NrLlmExceptionInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Throwable;

/**
 * Locks the ADR-053 contract: every exception class this extension
 * throws is catchable via the single `NrLlmExceptionInterface` marker,
 * including the `fromArray()` normalisation errors of the chat/tool
 * value objects — so a consumer's `catch (NrLlmExceptionInterface $e)`
 * cannot silently miss a class that a future change adds or rethrows.
 */
#[CoversNothing]
final class NrLlmExceptionInterfaceTest extends TestCase
{
    private const EXCEPTION_DIRS = [
        __DIR__ . '/../../../Classes/Exception',
        __DIR__ . '/../../../Classes/Provider/Exception',
    ];

    #[Test]
    public function everyExceptionClassImplementsTheMarkerInterface(): void
    {
        $checked = 0;

        foreach (self::EXCEPTION_DIRS as $dir) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $class = $this->classNameFromFile($file);
                $reflection = new ReflectionClass($class);

                if ($reflection->isInterface()) {
                    continue;
                }

                self::assertTrue(
                    $reflection->implementsInterface(NrLlmExceptionInterface::class),
                    sprintf('%s must implement %s (ADR-053).', $class, NrLlmExceptionInterface::class),
                );
                ++$checked;
            }
        }

        self::assertGreaterThanOrEqual(6, $checked, 'The reflection sweep must actually find the exception classes.');
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
     * @return class-string
     */
    private function classNameFromFile(SplFileInfo $file): string
    {
        $contents = file_get_contents($file->getPathname());
        self::assertNotFalse($contents);

        $matched = preg_match('/^namespace\s+([^;]+);/m', $contents, $namespace);
        self::assertSame(1, $matched, $file->getPathname() . ' must declare a namespace');

        /** @var class-string $class */
        $class = $namespace[1] . '\\' . $file->getBasename('.php');

        return $class;
    }
}
