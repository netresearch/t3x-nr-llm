<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Command;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Agent\Exception\RunEnqueueFailedException;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolverInterface;
use Netresearch\NrLlm\Service\Tool\EditorActionBatchEntry;
use Netresearch\NrLlm\Service\Tool\EditorActionBatchPlan;
use Netresearch\NrLlm\Service\Tool\EditorActionBatchPlanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Queue one editor action over a folder or a page tree (ADR-236).
 *
 * A writing tool acts for a person, so a batch names one: `--user` is the backend
 * user whose rights the runs have and who decides their approval cards. It is
 * never `_cli_`, whose admin flag would say nothing about who asked. Schedulable,
 * and repeatable by construction: a record with an open run or a value is
 * skipped, so a daily schedule picks up what arrived since.
 *
 * A schedule is started in no workspace, so a batch queued here reads and
 * writes live, whichever workspace the user last worked in (ADR-265).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
#[AsCommand(
    name: 'nrllm:editor-action:batch',
    description: 'Queue an editor action over a folder or a page tree. Each record becomes a run that waits for approval.',
)]
final class QueueEditorActionBatchCommand extends Command
{
    public function __construct(
        private readonly EditorActionBatchPlanner $planner,
        private readonly AgentRuntimeInterface $runtime,
        private readonly ActingBackendUserResolverInterface $users,
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED, 'The tool name of the editor action, for example set_file_alternative_text.');
        $this->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'The backend user the runs act as, by username.');
        $this->addOption('folder', null, InputOption::VALUE_REQUIRED, 'A combined folder identifier, for example 1:/products/. Subfolders are included.', '');
        $this->addOption('page-tree', null, InputOption::VALUE_REQUIRED, 'The uid of the page at the top of the tree.', '');
        $this->addOption('depth', null, InputOption::VALUE_REQUIRED, 'How many levels below that page. 0 is the page alone.', '99');
        $this->addOption('only-empty', null, InputOption::VALUE_REQUIRED, 'Leave out records whose named field holds a value. A vision action does this by itself.', '');
        $this->addOption('instruction', null, InputOption::VALUE_REQUIRED, 'A note for every run. It describes the result, and never changes what is touched.', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $folder = $this->text($input->getOption('folder'));
        $tree   = $this->text($input->getOption('page-tree'));
        if (($folder === '') === ($tree === '')) {
            $io->error('Name either --folder or --page-tree.');

            return Command::INVALID;
        }

        $user = $this->backendUser($this->text($input->getOption('user')));
        if (!$user instanceof BackendUserAuthentication) {
            $io->error('--user must name an active backend user who may work in the live workspace.');

            return Command::INVALID;
        }

        $depth = $input->getOption('depth');
        if (!is_string($depth) || !ctype_digit($depth)) {
            $io->error('--depth must be a whole number of levels.');

            return Command::INVALID;
        }

        $plan = $this->planner->planBackground(
            $this->text($input->getArgument('action')),
            $folder !== '' ? 'sys_file' : 'pages',
            $folder !== '' ? $folder : $tree,
            (int) $depth,
            $this->text($input->getOption('only-empty')),
            $this->text($input->getOption('instruction')),
            AiActorContext::fromBackendUser($user),
            $user,
        );

        if (!$plan instanceof EditorActionBatchPlan) {
            $io->error('That action, folder, page or field does not exist or cannot be read.');

            return Command::INVALID;
        }

        return $this->queue($plan, $io);
    }

    private function queue(EditorActionBatchPlan $plan, SymfonyStyle $io): int
    {
        $queued = 0;
        $failed = 0;
        foreach ($plan->entries as $entry) {
            if (!$entry->request instanceof AgentRunRequest) {
                continue;
            }

            try {
                $this->runtime->defer($entry->request);
                ++$queued;
            } catch (RunEnqueueFailedException) {
                ++$failed;
            }
        }

        $io->writeln(sprintf(
            'Queued %d, already filled %d, skipped %d, beyond the cap of %d this run %d.',
            $queued,
            $plan->alreadyFilled,
            count($plan->getSkipped()),
            EditorActionBatchPlanner::MAX_BACKGROUND_RECORDS,
            $plan->beyondCap,
        ));

        if ($failed > 0) {
            $io->error(sprintf('%d runs could not be stored.', $failed));

            return Command::FAILURE;
        }

        if ($queued === 0 && $plan->getSkipped() !== [] && $plan->getSkipped() === $this->refused($plan)) {
            $io->warning('Every record was refused. The tool may be off, the model may take no images, or the user may lack the rights.');
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<EditorActionBatchEntry>
     */
    private function refused(EditorActionBatchPlan $plan): array
    {
        return array_values(array_filter(
            $plan->getSkipped(),
            static fn (EditorActionBatchEntry $entry): bool => $entry->skipReasonKey === EditorActionBatchPlanner::REASON_NOT_OFFERED,
        ));
    }

    private function backendUser(string $username): ?BackendUserAuthentication
    {
        if ($username === '' || str_starts_with($username, '_cli_')) {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $uid          = $queryBuilder
            ->select('uid')
            ->from('be_users')
            ->where($queryBuilder->expr()->eq('username', $queryBuilder->createNamedParameter($username)))
            ->executeQuery()
            ->fetchOne();

        // Set up in live, not in the workspace the user's record names: the
        // actor recorded from this user is what the runs write in.
        return is_numeric($uid) ? $this->users->resolve(AiActorContext::backendUser((int) $uid, workspaceId: 0)) : null;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
