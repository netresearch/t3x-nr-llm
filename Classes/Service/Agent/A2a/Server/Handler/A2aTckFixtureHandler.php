<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server\Handler;

use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aMessage;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aPart;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aRole;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSkillProfile;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Symfony\Component\Uid\Uuid;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;

/**
 * The fixture agent of the A2A TCK (spec 051 A2A-SV11), in the Testing
 * context only: it reacts to the TCK's messageId prefixes and calls no model.
 *
 * Every prefix that needs a task goes through the real acceptance: the task,
 * its run and the dedup record commit together, and the run is QUEUED. Once
 * the acceptance committed, the fixture plays the worker for that one run: it
 * claims it and settles it through the persister, so the task view, the
 * mirror and the journal are the ones a real run writes. Only the model loop
 * is left out.
 *
 * - `tck-complete-task` (and every other prefix): COMPLETED, "Hello from TCK".
 * - `tck-artifact-text|data|file|file-url`: COMPLETED with that artifact.
 * - `tck-input-required`: the run waits for input, the task is INPUT_REQUIRED.
 * - `tck-reject-task`: refused before the run starts, REJECTED.
 * - `tck-message-response`: a message, no task.
 *
 * The context is read when the handler is asked, not when the container is
 * compiled: the container cache does not depend on the context.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aTckFixtureHandler implements A2aDomainHandlerInterface
{
    public const IDENTIFIER = 'nr_llm.tck_fixture';

    private const WORKER = 'a2a-tck-fixture';

    private const MARKER = 'nr-llm-tck-artifact:';

    public function __construct(
        private AgentRunPersister $persister,
        private ?ApplicationContext $context = null,
    ) {}

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function isAvailable(): bool
    {
        return ($this->context ?? Environment::getContext())->isTesting();
    }

    public function decide(A2aMessage $message, A2aSkillProfile $profile, A2aRunContext $context): A2aHandlerDecision
    {
        $id      = $message->messageId;
        $request = new AgentRunRequest($context->configuration, [ChatMessage::user($message->text())], $context->actor, allowedToolNames: [], origin: $context->origin);

        return match (true) {
            str_starts_with($id, 'tck-message-response')  => A2aHandlerDecision::reply(new A2aMessage(Uuid::v7()->toRfc4122(), A2aRole::AGENT, [A2aPart::text('Direct message response')], $message->contextId)),
            str_starts_with($id, 'tck-reject-task')       => A2aHandlerDecision::reject($request),
            str_starts_with($id, 'tck-input-required')    => A2aHandlerDecision::run($request, fn (string $runUuid) => $this->settle($runUuid, null)),
            str_starts_with($id, 'tck-artifact-file-url') => A2aHandlerDecision::run($request, fn (string $runUuid) => $this->settle($runUuid, self::MARKER . 'file-url')),
            str_starts_with($id, 'tck-artifact-file')     => A2aHandlerDecision::run($request, fn (string $runUuid) => $this->settle($runUuid, self::MARKER . 'file')),
            str_starts_with($id, 'tck-artifact-data')     => A2aHandlerDecision::run($request, fn (string $runUuid) => $this->settle($runUuid, self::MARKER . 'data')),
            str_starts_with($id, 'tck-artifact-text')     => A2aHandlerDecision::run($request, fn (string $runUuid) => $this->settle($runUuid, 'Generated text content')),
            default                                       => A2aHandlerDecision::run($request, fn (string $runUuid) => $this->settle($runUuid, 'Hello from TCK')),
        };
    }

    public function artifacts(string $answer): array
    {
        $id = Uuid::v7()->toRfc4122();

        return match ($answer) {
            self::MARKER . 'data'     => [['artifactId' => $id, 'parts' => [['data' => ['key' => 'value', 'count' => 42]]]]],
            self::MARKER . 'file'     => [['artifactId' => $id, 'parts' => [['raw' => base64_encode('Generated file content'), 'filename' => 'output.txt', 'mediaType' => 'text/plain']]]],
            self::MARKER . 'file-url' => [['artifactId' => $id, 'parts' => [['url' => 'https://example.com/output.txt', 'filename' => 'output.txt', 'mediaType' => 'text/plain']]]],
            default                   => [['artifactId' => $id, 'parts' => [['text' => $answer]]]],
        };
    }

    /**
     * Claim the run and settle it, as a worker would; null waits for input.
     */
    private function settle(string $runUuid, ?string $answer): void
    {
        $run = $this->persister->findRun($runUuid);
        if (!$run instanceof AgentRun || !$this->persister->claimQueued($run, self::WORKER, time() + 300)) {
            return;
        }

        $handle = new AgentRunHandle($run->uid, $run->uuid);
        if ($answer === null) {
            $this->persister->suspendForInput($handle, new SuspendedRunState([['role' => 'user', 'content' => '']], [], 1, 0, 0, [], [], 'tck_input', ['type' => 'object']));

            return;
        }

        $this->persister->settleCompleted($handle, new ToolLoopResult($answer, [], 1, false, UsageStatistics::fromTokens(0, 0)), self::WORKER);
    }
}
