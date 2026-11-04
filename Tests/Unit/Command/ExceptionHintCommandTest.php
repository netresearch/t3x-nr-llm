<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Command;

use Netresearch\NrLlm\Command\ExceptionHintCommand;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Tool\ExceptionHintService;
use Netresearch\NrLlm\Service\Tool\LogExceptionReader;
use Netresearch\NrLlm\Service\Tool\SourcePathGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ExceptionHintCommand::class)]
final class ExceptionHintCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = realpath(sys_get_temp_dir()) . '/nrllm-hint-cmd-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/log', 0o777, true);
        file_put_contents(
            $this->dir . '/log/typo3_x.log',
            'Tue, 03 Nov 2026 10:00:00 +0000 [CRITICAL] request="r" component="TYPO3.CMS.Core": Oops - '
            . json_encode(['exception' => 'RuntimeException: Oops in ' . $this->dir . '/packages/a/A.php:5' . "\nStack trace:\n#0 " . $this->dir . "/packages/a/B.php(9): A->run('pw')\n"]) . "\n",
        );
    }

    protected function tearDown(): void
    {
        unlink($this->dir . '/log/typo3_x.log');
        rmdir($this->dir . '/log');
        rmdir($this->dir);
        parent::tearDown();
    }

    private function tester(string $answer, ?LlmConfiguration $configuration): CommandTester
    {
        $llm = self::createStub(LlmServiceManagerInterface::class);
        $llm->method('chatWithConfiguration')->willReturn(new CompletionResponse($answer, 'm', new UsageStatistics(1, 1, 2)));
        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findDefault')->willReturn($configuration);
        $repository->method('findOneByIdentifier')->willReturn(null);

        return new CommandTester(new ExceptionHintCommand(
            new LogExceptionReader($this->dir . '/log'),
            new ExceptionHintService($llm, new TrustZoneResolver(), new SourcePathGuard($this->dir)),
            $repository,
        ));
    }

    private function local(): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setTrustZone(TrustZone::LOCAL->value);

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('local');
        $configuration->setLlmModel($model);

        return $configuration;
    }

    #[Test]
    public function anAnchoredHintIsShownWithItsFrame(): void
    {
        $tester = $this->tester('{"cause": "A fails.", "next_step": "Fix A.", "frame": 0}', $this->local());

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('Exception: RuntimeException', $display);
        self::assertStringContainsString("Hint:\n", $display);
        self::assertStringContainsString('Likely cause: A fails.', $display);
        self::assertStringContainsString('Frame #0: ' . $this->dir . '/packages/a/A.php:5', $display);
    }

    #[Test]
    public function anUnanchoredHintSaysItIsAGuess(): void
    {
        $tester = $this->tester('{"cause": "A fails.", "next_step": "Fix A.", "frame": null}', $this->local());

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Hint (a guess, not anchored in a project frame):', $tester->getDisplay());
    }

    #[Test]
    public function noConfigurationOrNoEntryFails(): void
    {
        $tester = $this->tester('{}', null);
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('No default LLM configuration', $tester->getDisplay());

        $tester = $this->tester('{}', $this->local());
        self::assertSame(Command::FAILURE, $tester->execute(['--configuration' => 'missing']));
        self::assertStringContainsString('No LLM configuration "missing"', $tester->getDisplay());

        self::assertSame(Command::FAILURE, $tester->execute(['--index' => '3']));
        self::assertStringContainsString('index 3 is out of range', $tester->getDisplay());
    }

    #[Test]
    public function aRefusedHintFails(): void
    {
        $tester = $this->tester('not json', $this->local());

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('No hint: The model did not answer in the expected form.', $tester->getDisplay());
    }
}
