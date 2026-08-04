<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Domain\Repository\SkillRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Service\Agent\Exception\RunConfigurationGoneException;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Service\Tool\RunAugmentation;
use RuntimeException;

/**
 * Converts an {@see AgentRunRequest} to and from the JSON payload stored on a
 * queued run row (ADR-102).
 *
 * The queue is the only place a request has to survive outside a process, so
 * the two directions belong together: what {@see self::dehydrate()} writes is
 * exactly what {@see self::rehydrate()} has to be able to read back, including
 * the fields carried out-of-band because their own array forms drop them.
 *
 * Entities travel as uids and are re-loaded on the way back, so a run reflects
 * the state at execution time rather than at enqueue time.
 */
final readonly class AgentRunRequestCodec
{
    public function __construct(
        private LlmConfigurationRepository $configurationRepository,
        private ?SkillRepository $skillRepository = null,
        private ?PromptSnippetRepository $promptSnippetRepository = null,
    ) {}

    /**
     * Serialise a request for the queued row (ADR-102). Entities travel as
     * uids (the rehydrator re-loads them — the same identity-over-snapshot
     * choice approve() makes for the configuration); messages and options use
     * their established array forms (the SuspendedRunState precedent).
     *
     * @return array<string, mixed>
     */
    public function dehydrate(AgentRunRequest $request): array
    {
        $augmentation = $request->augmentation;

        return [
            'messages'         => array_map(
                static fn(ChatMessage|array $m): array => $m instanceof ChatMessage ? $m->toArray() : $m,
                $request->messages,
            ),
            // The FULL initiating actor (ADR-083), not just its backend-user id:
            // a worker rehydrating this run must authorise with the identity that
            // enqueued the work — admin flag, groups, service account — rather
            // than the worker's absent ambient BE user.
            'actor'            => $request->actor->toArray(),
            'allowedToolNames' => $request->allowedToolNames,
            'options'          => $request->options?->toArray(),
            // ToolOptions::toArray() deliberately excludes the budget fields and
            // the idempotency key (they are not provider API fields), but a
            // queued run's budget PRE-FLIGHT has not happened yet — unlike the
            // ADR-084 resume case that exclusion was designed for. Carry them
            // out-of-band so run() and enqueue()+runQueued() of the same
            // request hit the identical budget gate and dedup.
            'plannedCost'      => $request->options?->getPlannedCost(),
            'idempotencyKey'   => $request->options?->getIdempotencyKey(),
            'maxIterations'    => $request->maxIterations,
            'captureRaw'       => $request->captureRaw,
            // null stays null: a non-null augmentation makes the loop bake the
            // effective system prompt into the transcript (ADR-060 assembly),
            // which a null-augmentation run() would not do — losing the
            // distinction would silently change the prompt composition.
            'augmentation'     => $augmentation instanceof RunAugmentation ? [
                'forcedSkillUids'   => array_values(array_filter(array_map(
                    static fn(Skill $skill): int => $skill->getUid() ?? 0,
                    $augmentation->forcedSkills,
                ))),
                'forcedSnippetUids' => array_values(array_filter(array_map(
                    static fn(PromptSnippet $snippet): int => $snippet->getUid() ?? 0,
                    $augmentation->forcedSnippets,
                ))),
                'dryRun'            => $augmentation->dryRun,
            ] : null,
        ];
    }

    /**
     * Rebuild the request from a claimed queued row (ADR-102). The
     * configuration and the forced skills/snippets are re-loaded by uid — a
     * configuration deleted while the run was queued fails the run, and a
     * skill/snippet deleted meanwhile is simply no longer forced (the same
     * live-resolution semantics the interactive path has).
     */
    public function rehydrate(AgentRun $run): AgentRunRequest
    {
        // The same typed absence approve() reports — here it is caught by
        // runQueued()'s rehydration guard, which settles the run FAILED with a
        // meaningful error class instead of letting it escape.
        $configuration = $this->configurationRepository->findByUid($run->configurationUid);
        if ($configuration === null) {
            throw RunConfigurationGoneException::forRun($run->uuid);
        }

        $data = json_decode($run->queuedRequest ?? '', true);
        if (!is_array($data)) {
            throw new RuntimeException(sprintf('The stored request of queued run %s could not be decoded', $run->uuid), 2826462004);
        }

        $messages = [];
        foreach (is_array($data['messages'] ?? null) ? $data['messages'] : [] as $message) {
            if (is_array($message)) {
                /** @var array<string, mixed> $message */
                $messages[] = $message;
            }
        }

        $allowed = null;
        if (is_array($data['allowedToolNames'] ?? null)) {
            $allowed = array_values(array_filter($data['allowedToolNames'], is_string(...)));
        }

        $options = null;
        if (is_array($data['options'] ?? null)) {
            /** @var array<string, mixed> $optionsData */
            $optionsData = $data['options'];
            // The initiator on the run row attributes the budget pre-flight,
            // exactly as the interactive path does.
            $options = ToolOptions::fromArray($optionsData, $run->beUser !== 0 ? $run->beUser : null);
            // Re-inject the out-of-band budget/idempotency fields (see
            // dehydrateRequest()): a queued run's budget pre-flight must be as
            // strict as the direct path's, and its provider calls as
            // deduplicatable.
            $plannedCost = $data['plannedCost'] ?? null;
            if (is_float($plannedCost) || is_int($plannedCost)) {
                $options = $options->withPlannedCost((float)$plannedCost);
            }

            $idempotencyKey = $data['idempotencyKey'] ?? null;
            if (is_string($idempotencyKey) && $idempotencyKey !== '') {
                $options = $options->withIdempotencyKey($idempotencyKey);
            }
        }

        $augmentation = null;
        if (is_array($data['augmentation'] ?? null)) {
            $augmentationData = $data['augmentation'];
            $augmentation     = new RunAugmentation(
                forcedSkills: $this->skillsByUids($this->uidList($augmentationData['forcedSkillUids'] ?? null)),
                forcedSnippets: $this->snippetsByUids($this->uidList($augmentationData['forcedSnippetUids'] ?? null)),
                dryRun: ($augmentationData['dryRun'] ?? false) === true,
            );
        }

        // Restore the full initiating actor from the serialised request. A row
        // queued before actors were persisted has no 'actor' key: fall back to
        // the stored be_user id (the same single-int identity the pre-actor
        // worker had), so an in-flight upgrade never loses or invents privilege.
        $actorData = $data['actor'] ?? null;
        if (is_array($actorData)) {
            /** @var array<string, mixed> $actorData a serialised actor is a JSON object (string keys) */
            $actor = AiActorContext::fromArray($actorData);
        } else {
            $actor = AiActorContext::backendUser($run->beUser);
        }

        return new AgentRunRequest(
            configuration: $configuration,
            messages: $messages,
            actor: $actor,
            allowedToolNames: $allowed,
            options: $options,
            maxIterations: is_int($data['maxIterations'] ?? null) ? $data['maxIterations'] : null,
            augmentation: $augmentation,
            captureRaw: ($data['captureRaw'] ?? false) === true,
        );
    }

    /**
     * @return list<int>
     */
    private function uidList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $uids = [];
        foreach ($value as $uid) {
            if (is_int($uid) && $uid > 0) {
                $uids[] = $uid;
            }
        }

        return $uids;
    }

    /**
     * Forced skills by uid, preserving order. Resolved without the enabled
     * filter — forcing a skill overrides its global toggle, the same semantics
     * the playground's force-inject control has.
     *
     * @param list<int> $uids
     *
     * @return list<Skill>
     */
    private function skillsByUids(array $uids): array
    {
        if ($uids === [] || !$this->skillRepository instanceof SkillRepository) {
            return [];
        }

        $byUid = [];
        foreach ($this->skillRepository->findAll() as $skill) {
            if ($skill instanceof Skill && $skill->getUid() !== null) {
                $byUid[$skill->getUid()] = $skill;
            }
        }

        $skills = [];
        foreach ($uids as $uid) {
            if (isset($byUid[$uid])) {
                $skills[] = $byUid[$uid];
            }
        }

        return $skills;
    }

    /**
     * @param list<int> $uids
     *
     * @return list<PromptSnippet>
     */
    private function snippetsByUids(array $uids): array
    {
        if ($uids === [] || !$this->promptSnippetRepository instanceof PromptSnippetRepository) {
            return [];
        }

        return $this->promptSnippetRepository->findByUids($uids);
    }
}
