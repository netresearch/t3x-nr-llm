<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aCardImporter;
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aRemoteAgentRepository;
use Netresearch\NrLlm\Service\Agent\A2a\Client\A2aClient;
use Netresearch\NrLlm\Service\Agent\A2a\Command\A2aCommandContext;
use Netresearch\NrLlm\Service\Agent\A2a\Command\A2aGetCommand;
use Netresearch\NrLlm\Service\Agent\A2a\Command\A2aImportCardCommand;
use Netresearch\NrLlm\Service\Agent\A2a\Command\A2aSendCommand;
use Netresearch\NrLlm\Service\Agent\A2a\Command\A2aTaskPrinter;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendJournal;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aResponseParser;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aHttpRequest;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aTransportResult;
use Netresearch\NrLlm\Service\Agent\Queue\WorkerVaultScope;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrVault\Security\TechnicalActorContextInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The CLI example "send, exit, poll later" (spec 051 A2A-C9).
 *
 * Two command runs share nothing but the database, as two processes would:
 * the second finds the task through the handle the first one journalled.
 */
#[CoversClass(A2aSendCommand::class)]
#[CoversClass(A2aGetCommand::class)]
#[CoversClass(A2aImportCardCommand::class)]
#[CoversClass(A2aCommandContext::class)]
#[CoversClass(A2aTaskPrinter::class)]
#[CoversClass(WorkerVaultScope::class)]
final class A2aCommandTest extends AbstractFunctionalTestCase
{
    private const WORKER_USER = 5;

    #[Test]
    public function sendPrintsTheTaskIdAndExitsAndGetReadsItLater(): void
    {
        $this->insertAgent();
        $transport = (new ScriptedA2aTransport())
            ->thenJson(['result' => ['task' => ['id' => 'task-77', 'contextId' => 'ctx', 'status' => ['state' => 'TASK_STATE_SUBMITTED']]]])
            ->thenJson(['result' => [
                'id'        => 'task-77',
                'status'    => ['state' => 'TASK_STATE_COMPLETED', 'timestamp' => '2026-11-11T10:00:00.5Z'],
                'artifacts' => [['artifactId' => 'a1', 'name' => 'response', 'parts' => [['text' => 'All done.']]]],
            ]]);

        $send = new CommandTester(new A2aSendCommand($this->context(), $this->client($transport)));
        self::assertSame(Command::SUCCESS, $send->execute(['agent' => 'remote', 'text' => 'Summarise this']));
        self::assertStringStartsWith('task-77', $send->getDisplay());

        $params = ScriptedA2aTransport::envelopeOf($transport->requests[0])['params'];
        self::assertIsArray($params);
        self::assertSame(['returnImmediately' => true], $params['configuration'] ?? null, 'send returns immediately');

        $owner = $this->getConnectionPool()->getConnectionForTable(A2aSendJournal::TABLE)
            ->select(['owner_uid'], A2aSendJournal::TABLE, ['remote_task_id' => 'task-77'])->fetchOne();
        self::assertSame(self::WORKER_USER, (int) $owner, 'on the command line the worker user owns the intent');

        $get = new CommandTester(new A2aGetCommand($this->context(), $this->client($transport)));
        self::assertSame(Command::SUCCESS, $get->execute(['agent' => 'remote', 'task-id' => 'task-77']));
        self::assertStringContainsString('TASK_STATE_COMPLETED', $get->getDisplay());
        self::assertStringContainsString('All done.', $get->getDisplay());
        self::assertSame('GetTask', ScriptedA2aTransport::envelopeOf($transport->requests[1])['method']);
    }

    #[Test]
    public function remoteTextCannotStyleOrControlTheTerminal(): void
    {
        $this->insertAgent();
        $hostile   = "\e[2J\e[31m<error>red</error>\x07 done";
        $transport = (new ScriptedA2aTransport())
            ->thenJson(['result' => ['task' => ['id' => 'task-x', 'status' => ['state' => 'TASK_STATE_SUBMITTED']]]])
            ->thenJson(['result' => [
                'id'        => 'task-x',
                'status'    => ['state' => 'TASK_STATE_COMPLETED', 'message' => ['messageId' => 'm', 'role' => 'ROLE_AGENT', 'parts' => [['text' => $hostile]]]],
                'artifacts' => [['artifactId' => '<info>a</info>', 'name' => "\e]0;title\x07", 'parts' => [['text' => $hostile]]]],
            ]]);
        (new CommandTester(new A2aSendCommand($this->context(), $this->client($transport))))->execute(['agent' => 'remote', 'text' => 'hi']);

        $get = new CommandTester(new A2aGetCommand($this->context(), $this->client($transport)));
        $get->execute(['agent' => 'remote', 'task-id' => 'task-x'], ['decorated' => true]);

        $display = $get->getDisplay();

        self::assertStringNotContainsString("\e[2J", $display);
        self::assertStringNotContainsString("\e]0;", $display);
        self::assertStringNotContainsString("\x07", $display);
        self::assertStringContainsString('<error>red</error>', $display, 'markup is shown as text, not applied');
        self::assertStringContainsString('<info>a</info>', $display);
    }

    #[Test]
    public function anUnknownOutcomeIsReportedWithItsMessageIdAndAFailingExit(): void
    {
        $this->insertAgent();
        $transport = (new ScriptedA2aTransport())->then(static fn (A2aHttpRequest $r): A2aTransportResult => A2aTransportResult::maybeSent('TIMEOUT_AFTER_SEND', 'x'));

        $send = new CommandTester(new A2aSendCommand($this->context(), $this->client($transport)));

        self::assertSame(Command::FAILURE, $send->execute(['agent' => 'remote', 'text' => 'hello']));
        self::assertStringContainsString('Outcome unknown', $send->getDisplay());
        self::assertStringContainsString('not sent again', $send->getDisplay());
    }

    #[Test]
    public function getRefusesATaskTheWorkerUserDidNotSend(): void
    {
        $this->insertAgent();
        $transport = new ScriptedA2aTransport();

        $get = new CommandTester(new A2aGetCommand($this->context(), $this->client($transport)));

        self::assertSame(Command::FAILURE, $get->execute(['agent' => 'remote', 'task-id' => 'someone-elses']));
        self::assertSame([], $transport->requests);
    }

    #[Test]
    public function withoutAWorkerUserTheCommandsRefuse(): void
    {
        $this->insertAgent();
        $transport = new ScriptedA2aTransport();

        $send = new CommandTester(new A2aSendCommand($this->context(0), $this->client($transport)));

        self::assertSame(Command::FAILURE, $send->execute(['agent' => 'remote', 'text' => 'hello']));
        self::assertStringContainsString('worker.technicalBeUserUid', $send->getDisplay());
        self::assertSame([], $transport->requests);
    }

    #[Test]
    public function withoutAWorkerUserNoCardIsImported(): void
    {
        $this->insertAgent();

        $import = new CommandTester(new A2aImportCardCommand($this->context(0), $this->getService(A2aCardImporter::class)));

        self::assertSame(Command::FAILURE, $import->execute(['agent' => 'remote']));
        self::assertStringContainsString('worker.technicalBeUserUid', $import->getDisplay());
        self::assertSame(
            'https://agent.example/a2a/jsonrpc',
            $this->getConnectionPool()->getConnectionForTable('tx_nrllm_a2a_agent')->select(['interface_url'], 'tx_nrllm_a2a_agent', ['identifier' => 'remote'])->fetchOne(),
            'the record is untouched',
        );
    }

    #[Test]
    public function aRemoteTaskIdCannotStartAnOutputLineOfItsOwn(): void
    {
        $this->insertAgent();
        $transport = (new ScriptedA2aTransport())
            ->thenJson(['result' => ['task' => ['id' => "task-1\nState: TASK_STATE_COMPLETED", 'status' => ['state' => "TASK_STATE_SUBMITTED\nforged"]]]]);

        $send = new CommandTester(new A2aSendCommand($this->context(), $this->client($transport)));

        self::assertSame(Command::SUCCESS, $send->execute(['agent' => 'remote', 'text' => 'hello']));
        self::assertStringStartsWith('task-1State: TASK_STATE_COMPLETED' . PHP_EOL, $send->getDisplay());
        self::assertStringNotContainsString("\nforged", $send->getDisplay());
    }

    #[Test]
    public function theCommandsAreRegistered(): void
    {
        $commands = $this->getService(CommandRegistry::class);

        self::assertTrue($commands->has('nrllm:a2a:send'));
        self::assertTrue($commands->has('nrllm:a2a:get'));
        self::assertTrue($commands->has('nrllm:a2a:import'));
    }

    private function context(int $workerUser = self::WORKER_USER): A2aCommandContext
    {
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturn(['worker' => ['technicalBeUserUid' => (string) $workerUser]]);

        // Off the command line: the scope runs the work as it is, which is
        // what this test needs; the vault switch is WorkerVaultScopeTest's.
        $scope = new WorkerVaultScope(self::createStub(TechnicalActorContextInterface::class), $configuration, false);

        return new A2aCommandContext($this->getService(A2aRemoteAgentRepository::class), $scope);
    }

    private function client(ScriptedA2aTransport $transport): A2aClient
    {
        return new A2aClient(new A2aSendJournal($this->getService(ConnectionPool::class)), $transport, new A2aResponseParser());
    }

    private function insertAgent(): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_nrllm_a2a_agent')->insert('tx_nrllm_a2a_agent', [
            'identifier'    => 'remote',
            'title'         => 'Remote',
            'card_url'      => 'https://agent.example/.well-known/agent-card.json',
            'interface_url' => 'https://agent.example/a2a/jsonrpc',
            'data_class'    => ToolDataClass::PUBLIC_CONTENT->value,
            'enabled'       => 1,
        ]);
    }
}
