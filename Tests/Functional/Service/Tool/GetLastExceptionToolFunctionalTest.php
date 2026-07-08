<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\GetLastExceptionTool;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * End-to-end wiring test for get_last_exception (ADR-044): the DI-built
 * tool (default Environment paths) parses a seeded log entry from the test
 * instance's var/log and expands source context for a frame inside the
 * instance.
 */
#[CoversClass(GetLastExceptionTool::class)]
final class GetLastExceptionToolFunctionalTest extends AbstractFunctionalTestCase
{
    private ToolInterface $tool;

    private string $frameFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1);

        // A source file inside the instance the stack frame can point at.
        $this->frameFile = Environment::getProjectPath() . '/probe-fixture/Sample.php';
        GeneralUtility::mkdir_deep(dirname($this->frameFile));
        file_put_contents(
            $this->frameFile,
            implode("\n", array_map(static fn(int $i): string => sprintf('// sample %d', $i), range(1, 12))),
        );

        // A realistic FileWriter record in the instance's var/log.
        $logDir = Environment::getVarPath() . '/log';
        GeneralUtility::mkdir_deep($logDir);
        $exceptionJson = json_encode([
            'exception' => "DomainException: functional kaboom in {$this->frameFile}:6\n"
                . "Stack trace:\n#0 {$this->frameFile}(6): boom()",
        ], JSON_THROW_ON_ERROR);
        file_put_contents(
            $logDir . '/typo3_functional.log',
            'Wed, 09 Jul 2026 08:00:00 +0200 [CRITICAL] request="fx" component="Acme.Functional": functional failure - '
            . $exceptionJson . "\n",
        );

        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $tool = $registry->get('get_last_exception');
        self::assertInstanceOf(ToolInterface::class, $tool);
        $this->tool = $tool;
    }

    #[Test]
    public function parsesSeededLogAndExpandsSourceContext(): void
    {
        $output = $this->tool->execute([]);

        self::assertStringContainsString('DomainException', $output);
        self::assertStringContainsString('functional failure', $output);
        self::assertStringContainsString('>    6 | // sample 6', $output);
    }

    #[Test]
    public function searchFilterNarrowsToTheSeededEntry(): void
    {
        $output = $this->tool->execute(['search' => 'functional kaboom or nothing']);

        // A non-matching search yields the no-entries message…
        self::assertStringContainsString('No error-level entries', $output);

        // …while a matching one finds the seeded record.
        self::assertStringContainsString('DomainException', $this->tool->execute(['search' => 'DomainException']));
    }

    protected function tearDown(): void
    {
        $seededLog = Environment::getVarPath() . '/log/typo3_functional.log';
        if (is_file($seededLog)) {
            unlink($seededLog);
        }
        GeneralUtility::rmdir(Environment::getProjectPath() . '/probe-fixture', true);
        parent::tearDown();
    }
}
