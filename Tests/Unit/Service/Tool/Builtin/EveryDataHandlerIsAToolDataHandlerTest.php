<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * No code of this extension creates the core DataHandler itself (ADR-206).
 *
 * `ToolDataHandler` finishes a run a hook of the installation broke off and
 * records the failure for the tool loop. A writer that created the core class
 * instead would end its call with "tool failed" again, also for a write that
 * landed — and nothing else would show it.
 *
 * A source scan rather than a dependency rule: the writers still name the core
 * class in type declarations, and `ToolDataHandler` extends it.
 */
#[CoversNothing]
final class EveryDataHandlerIsAToolDataHandlerTest extends TestCase
{
    private const CLASSES = __DIR__ . '/../../../../../Classes';

    /** Creating the core class: through makeInstance(), the container, or with `new`. */
    private const CREATES_THE_CORE_CLASS = '/(?:makeInstance|->get)\(\s*(?:\\\\?TYPO3\\\\CMS\\\\Core\\\\DataHandling\\\\)?DataHandler::class|new\s+(?:\\\\?TYPO3\\\\CMS\\\\Core\\\\DataHandling\\\\)?DataHandler\s*\(/';

    #[Test]
    public function noClassCreatesTheCoreDataHandler(): void
    {
        $found = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::CLASSES, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            if (preg_match_all(self::CREATES_THE_CORE_CLASS, $source, $matches) > 0) {
                $found[] = $file->getFilename() . ': ' . implode(', ', $matches[0]);
            }
        }

        self::assertSame([], $found, 'Create ToolDataHandler instead (ADR-206).');
    }

    /**
     * The pattern matches both ways the core class can be created, and not the
     * one it must allow.
     */
    #[Test]
    public function thePatternTellsTheCoreClassFromTheToolClass(): void
    {
        self::assertMatchesRegularExpression(self::CREATES_THE_CORE_CLASS, '$x = GeneralUtility::makeInstance(DataHandler::class);');
        self::assertMatchesRegularExpression(self::CREATES_THE_CORE_CLASS, '$x = GeneralUtility::makeInstance(' . DataHandler::class . '::class);');
        self::assertMatchesRegularExpression(self::CREATES_THE_CORE_CLASS, '$x = new DataHandler();');
        self::assertMatchesRegularExpression(self::CREATES_THE_CORE_CLASS, '$x = $container->get(DataHandler::class);');
        self::assertDoesNotMatchRegularExpression(self::CREATES_THE_CORE_CLASS, '$x = GeneralUtility::makeInstance(ToolDataHandler::class);');
        self::assertDoesNotMatchRegularExpression(self::CREATES_THE_CORE_CLASS, '$x = new ToolDataHandler();');
        self::assertDoesNotMatchRegularExpression(self::CREATES_THE_CORE_CLASS, '$x = $container->get(ToolDataHandler::class);');
    }
}
