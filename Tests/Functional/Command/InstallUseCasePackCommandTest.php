<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Command;

use Netresearch\NrLlm\Command\InstallUseCasePackCommand;
use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Service\UseCase\EditorialStarterPackProvider;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The unattended install path (ADR-186).
 *
 * Functional rather than unit, and deliberately fetched from the container
 * rather than constructed: the point of this command is that a provisioning
 * script can reach the installer without a backend session, and a hand-wired
 * instance would prove the class works while leaving the `console.command`
 * registration — the part that makes it reachable at all — untested.
 */
#[CoversClass(InstallUseCasePackCommand::class)]
final class InstallUseCasePackCommandTest extends AbstractFunctionalTestCase
{
    private TaskRepository $taskRepository;

    private PromptSnippetRepository $snippetRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->taskRepository    = $this->getService(TaskRepository::class);
        $this->snippetRepository = $this->getService(PromptSnippetRepository::class);

        // Providers and models the Editorial Starter preset's `chat`
        // requirement can resolve against.
        $this->importFixture('Providers.csv');
        $this->importFixture('Models.csv');
    }

    #[Test]
    public function installsEveryRecordTheNamedPackDeclares(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['identifier' => 'editorial-starter']);

        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());

        $pack = (new EditorialStarterPackProvider())->getPacks()[0];
        foreach ($pack->tasks as $packTask) {
            self::assertInstanceOf(
                Task::class,
                $this->taskRepository->findOneByIdentifier($packTask->identifier),
                $packTask->identifier . ' is missing after a reported success.',
            );
        }

        foreach ($pack->snippets as $packSnippet) {
            self::assertInstanceOf(
                PromptSnippet::class,
                $this->snippetRepository->findOneByIdentifier($packSnippet->identifier),
                $packSnippet->identifier . ' is missing after a reported success.',
            );
        }
    }

    #[Test]
    public function aSecondRunSucceedsAndCreatesNothing(): void
    {
        // The property that makes this safe in a deploy step that runs every
        // time. Without it the command would either duplicate records or need
        // a caller that knows whether it has run before.
        $this->tester()->execute(['identifier' => 'editorial-starter']);

        $tester = $this->tester();
        $status = $tester->execute(['identifier' => 'editorial-starter']);

        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        self::assertStringContainsString('0 created', $tester->getDisplay());
    }

    #[Test]
    public function anUnknownIdentifierFailsAndNamesWhatIsDeclared(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['identifier' => 'no-such-pack']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('no-such-pack', $tester->getDisplay());
        self::assertStringContainsString('editorial-starter', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        return new CommandTester($this->get(InstallUseCasePackCommand::class));
    }
}
