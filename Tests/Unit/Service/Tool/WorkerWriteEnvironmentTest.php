<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Service\Tool\WorkerWriteEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * The backend environment around a write the worker carries out (ADR-281):
 * set for that call as the run's acting user, put back afterwards, and only in
 * a process that has no backend user of its own.
 */
#[CoversClass(WorkerWriteEnvironment::class)]
final class WorkerWriteEnvironmentTest extends TestCase
{
    private ?BackendUserAuthentication $actingUser = null;

    private LanguageService $language;

    protected function setUp(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        $this->actingUser = self::createStub(BackendUserAuthentication::class);
        $this->language   = self::createStub(LanguageService::class);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
    }

    #[Test]
    public function aBareWorkerGetsTheActingUserAndALanguageServiceForTheCallOnly(): void
    {
        $seen = null;

        $result = $this->environment()->around($this->actingUser, function () use (&$seen): string {
            $seen = [$GLOBALS['BE_USER'] ?? null, $GLOBALS['LANG'] ?? null];

            return 'written';
        });

        self::assertSame('written', $result);
        self::assertSame([$this->actingUser, $this->language], $seen);
        self::assertArrayNotHasKey('BE_USER', $GLOBALS);
        self::assertArrayNotHasKey('LANG', $GLOBALS);
    }

    #[Test]
    public function theEnvironmentIsPutBackWhenTheCallThrows(): void
    {
        try {
            $this->environment()->around($this->actingUser, $this->failingWrite(...));
            $swallowed = true;
        } catch (RuntimeException) {
            $swallowed = false;
        }

        self::assertFalse($swallowed, 'the exception was swallowed');

        self::assertArrayNotHasKey('BE_USER', $GLOBALS);
        self::assertArrayNotHasKey('LANG', $GLOBALS);
    }

    #[Test]
    public function theUserTheCommandLineBootsBelongsToNobodyAndIsRestored(): void
    {
        $cli                = self::createStub(CommandLineUserAuthentication::class);
        $GLOBALS['BE_USER'] = $cli;
        $seen               = null;

        $this->environment()->around($this->actingUser, function () use (&$seen): void {
            $seen = $GLOBALS['BE_USER'] ?? null;
        });

        self::assertSame($this->actingUser, $seen);
        self::assertSame($cli, $GLOBALS['BE_USER']);
    }

    #[Test]
    public function aRequestKeepsTheUserItBelongsTo(): void
    {
        $login              = self::createStub(BackendUserAuthentication::class);
        $GLOBALS['BE_USER'] = $login;
        $seen               = null;

        $this->environment()->around($this->actingUser, function () use (&$seen): void {
            $seen = [$GLOBALS['BE_USER'] ?? null, $GLOBALS['LANG'] ?? null];
        });

        self::assertSame([$login, null], $seen);
    }

    #[Test]
    public function outsideTheCommandLineAndWithoutAnActingUserTheCallRunsAsItIs(): void
    {
        $seen = [];
        (new WorkerWriteEnvironment($this->factory(), false))->around($this->actingUser, function () use (&$seen): void {
            $seen[] = $GLOBALS['BE_USER'] ?? null;
        });
        $this->environment()->around(null, function () use (&$seen): void {
            $seen[] = $GLOBALS['BE_USER'] ?? null;
        });

        self::assertSame([null, null], $seen);
    }

    private function failingWrite(): string
    {
        throw new RuntimeException('the hook failed', 1799400901);
    }

    private function environment(): WorkerWriteEnvironment
    {
        return new WorkerWriteEnvironment($this->factory(), true);
    }

    private function factory(): LanguageServiceFactory
    {
        $factory = self::createStub(LanguageServiceFactory::class);
        $factory->method('createFromUserPreferences')->willReturn($this->language);

        return $factory;
    }
}
