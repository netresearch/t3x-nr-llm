<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Runtime;

use JsonException;
use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aRemoteAgent;
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aRemoteAgentRepository;
use Netresearch\NrLlm\Service\Agent\A2a\Client\A2aClient;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aInvalidAgentResponseException;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendJournal;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aResponseParser;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * One delegation tool per enabled remote agent and card skill (spec 051
 * A2A-R8), made from the records alone: it reads the stored card and opens
 * no connection, which the provider contract requires (ADR-116).
 *
 * Nothing is offered:
 *
 * - while nr_mcp_agent is loaded in a version that does not understand a run
 *   waiting for a remote agent ({@see A2aDelegationGate}, A2A-R2);
 * - for a record that is disabled, has no data class, has no imported card,
 *   or may not be used for any other reason the client refuses it for;
 * - for a stored card that does not parse as a valid card;
 * - for a skill whose tool name another skill already has (logged).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aDelegationToolProvider implements ToolProviderInterface
{
    public function __construct(
        private A2aRemoteAgentRepository $agents,
        private A2aClient $client,
        private A2aSendJournal $journal,
        private A2aResponseParser $parser,
        private A2aDelegationGate $gate,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * @return iterable<ToolInterface>
     */
    public function tools(): iterable
    {
        $refusal = $this->gate->refusal();
        if ($refusal !== null) {
            $this->logger?->info('No A2A delegation tool is offered: ' . $refusal);

            return;
        }

        /** @var array<string, true> $offered */
        $offered = [];
        foreach ($this->agents->findAll() as $agent) {
            if (!$agent instanceof A2aRemoteAgent || $agent->useRefusal() !== null) {
                continue;
            }

            if (!$agent->getDataClass() instanceof ToolDataClass) {
                continue;
            }

            try {
                $card = json_decode($agent->getCardJson(), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($card)) {
                    continue;
                }

                /** @var array<string, mixed> $card */
                $skills = $this->parser->parseAgentCard($card)->skills;
            } catch (JsonException|A2aInvalidAgentResponseException) {
                continue;
            }

            foreach ($skills as $skill) {
                $tool = new A2aDelegationTool($agent, $skill, $this->client, $this->journal, $this->agents);
                $name = $tool->getSpec()->name;
                if (isset($offered[$name])) {
                    // A card that lists one skill id twice; a name never
                    // stands for two skills.
                    $this->logger?->warning('An A2A skill is not offered: its tool name is taken', ['agent' => $agent->getIdentifier(), 'skill' => $skill->id, 'tool' => $name]);

                    continue;
                }

                $offered[$name] = true;
                yield $tool;
            }
        }
    }
}
