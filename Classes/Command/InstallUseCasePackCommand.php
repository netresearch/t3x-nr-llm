<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Command;

use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\UseCase\UseCasePack;
use Netresearch\NrLlm\Service\UseCase\UseCasePackInstaller;
use Netresearch\NrLlm\Service\UseCase\UseCasePackRegistry;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Installs one use-case pack from an unattended context (ADR-186).
 *
 * The Use Case Packs backend module is the interactive way: it shows the plan,
 * the operator confirms it, the installer writes. A container entrypoint, a
 * `make update` step or a CI provisioning run cannot confirm a screen, and
 * without this command an instance that is rebuilt from a database seed loses
 * every pack record the moment the seed predates the pack — which is exactly
 * how a fresh install arrives with an empty snippet library.
 *
 * It writes through {@see UseCasePackInstaller}, the same service the module
 * uses, so the two cannot drift: the installer creates only what is missing,
 * never updates and never overwrites. Re-running is therefore a no-op that
 * reports what was already there, which is what makes it safe in an install
 * routine that runs on every deploy.
 *
 * What it does NOT do is the half the installer refuses too (ADR-168): it
 * enables no tool group, enables no editor action, and applies no governance
 * profile. Those stay administrator decisions in their own modules, and a
 * command that made them would hand a shell script the authority of the tool
 * gate.
 */
#[AsCommand(
    name: 'nrllm:usecasepack:install',
    description: 'Install a use-case pack: create its configuration, tasks and snippets that do not exist yet.',
)]
/**
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final class InstallUseCasePackCommand extends Command
{
    // Level 10 sees a console argument as mixed; the shared helper is the
    // repository's answer to that (Classes/AGENTS.md).
    use SafeCastTrait;

    public function __construct(
        private readonly UseCasePackRegistry $registry,
        private readonly UseCasePackInstaller $installer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'identifier',
            InputArgument::REQUIRED,
            'Pack identifier, e.g. "editorial-starter". Pass an unknown one to see the list.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $identifier = self::toStr($input->getArgument('identifier'));
        $pack       = $this->registry->findByIdentifier($identifier);

        if (!$pack instanceof UseCasePack) {
            $io->error(sprintf('No use-case pack "%s" is declared.', $identifier));
            $declared = array_map(
                static fn(UseCasePack $candidate): string => $candidate->identifier . ' — ' . $candidate->name,
                $this->registry->all(),
            );

            if ($declared === []) {
                $io->note('This installation declares no packs at all.');
            } else {
                $io->section('Declared packs');
                $io->listing($declared);
            }

            return Command::FAILURE;
        }

        try {
            $result = $this->installer->install($pack);
        } catch (InvalidArgumentException $e) {
            // The two refusals the installer states: an unsatisfiable
            // configuration and a snippet_tags overflow. Both name what the
            // operator has to change, so the message is the whole answer.
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Use-case pack "%s" installed.', $pack->identifier));

        $io->definitionList(
            ['Configuration' => $result->createdConfiguration
                ? 'created ' . $pack->configurationPreset->identifier
                : 'already present (' . $pack->configurationPreset->identifier . ')'],
            ['Tasks' => $this->summarize($result->createdTasks, $result->skippedTasks)],
            ['Snippets' => $this->summarize($result->createdSnippets, $result->skippedSnippets)],
            ['Snippet tags added' => $result->addedSnippetTags === []
                ? 'none'
                : implode(', ', $result->addedSnippetTags)],
        );

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $created
     * @param list<string> $skipped
     */
    private function summarize(array $created, array $skipped): string
    {
        return sprintf(
            '%d created%s, %d already present%s',
            count($created),
            $created === [] ? '' : ' (' . implode(', ', $created) . ')',
            count($skipped),
            $skipped === [] ? '' : ' (' . implode(', ', $skipped) . ')',
        );
    }
}
