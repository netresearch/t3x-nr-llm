<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail;

use Netresearch\NrLlm\Domain\Enum\GovernanceDecision;
use Netresearch\NrLlm\Domain\Enum\GuardrailVerdict;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\ValueObject\GovernanceEvent;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;
use Netresearch\NrLlm\Exception\GuardrailApprovalRequiredException;
use Netresearch\NrLlm\Exception\GuardrailViolationException;
use Netresearch\NrLlm\Provider\Middleware\BudgetMiddleware;
use Netresearch\NrLlm\Provider\Middleware\GuardrailMiddleware;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Service\Governance\GovernanceEventRepositoryInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

use function is_array;
use function is_int;
use function is_numeric;

/**
 * Records a blocking guardrail verdict and throws the matching exception, for
 * the output path and for a held stream (ADR-238): one block looks the same in
 * the governance log wherever it happened.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class GuardrailBlockRecorder
{
    /**
     * @param ?GovernanceEventRepositoryInterface $governanceEvents absent it the verdict is still enforced, it just is not persisted
     */
    public function __construct(
        private ?GovernanceEventRepositoryInterface $governanceEvents = null,
    ) {}

    /**
     * Persist the governance event for a blocking verdict, then throw the
     * matching exception. Recorded before throwing so a blocked response is
     * captured even though the pipeline unwinds. Only class names and policy
     * facts are stored — never the response content (ADR-064).
     *
     * A DENY on a provider-flagged response (finishReason = content_filter) is
     * tagged CONTENT_FILTER so a provider-side safety stop is separately
     * measurable; REQUIRE_APPROVAL is always APPROVAL_REQUIRED.
     */
    public function recordAndThrow(
        GuardrailVerdict $verdict,
        GuardrailInterface $guardrail,
        GuardrailResult $result,
        CompletionResponse $response,
        ProviderCallContext $context,
    ): never {
        $decision = match (true) {
            $verdict === GuardrailVerdict::REQUIRE_APPROVAL => GovernanceDecision::APPROVAL_REQUIRED,
            $response->wasFiltered()                        => GovernanceDecision::CONTENT_FILTER,
            default                                         => GovernanceDecision::RESPONSE_BLOCKED,
        };
        $reason = match ($decision) {
            GovernanceDecision::APPROVAL_REQUIRED => GuardrailVerdict::REQUIRE_APPROVAL->value,
            GovernanceDecision::CONTENT_FILTER    => GovernanceDecision::CONTENT_FILTER->value,
            default                               => GuardrailVerdict::DENY->value,
        };

        $this->governanceEvents?->record(new GovernanceEvent(
            correlationId: $context->correlationId,
            decision: $decision->value,
            reason: $reason,
            provider: $response->provider !== '' ? $response->provider : $context->telemetryProvider(),
            model: $response->model !== '' ? $response->model : $context->telemetryModel(),
            configurationIdentifier: $context->telemetryConfigurationIdentifier(),
            beUser: $this->resolveBeUser($context),
            toolName: '',
            // The run driving this call, when one does (ADR-153): the runtime
            // threads its uid through the pipeline metadata. 0 means there is no
            // run — a plain provider call — not that the identity was lost.
            agentrunUid: $this->resolveAgentRunUid($context),
            guardrail: $guardrail::class,
            // The guardrail's policy reason — a policy fact, never response content.
            // A sentence that quotes the output for the editor names its stored
            // form separately (ADR-288).
            detail: $result->auditReason ?? $result->reason,
        ));

        if ($verdict === GuardrailVerdict::REQUIRE_APPROVAL) {
            throw new GuardrailApprovalRequiredException(
                $guardrail::class,
                $result->reason !== '' ? $result->reason : 'A guardrail flagged the response for human approval.',
            );
        }

        throw new GuardrailViolationException(
            $guardrail::class,
            $result->reason !== '' ? $result->reason : 'A guardrail denied the response.',
        );
    }

    /**
     * The uid of the agent run this call belongs to, from the metadata the
     * runtime threads through (ADR-153); 0 when the call is not part of a run.
     */
    private function resolveAgentRunUid(ProviderCallContext $context): int
    {
        $fromMetadata = $context->metadata[GuardrailMiddleware::METADATA_AGENT_RUN_UID] ?? null;

        return is_int($fromMetadata) ? $fromMetadata : 0;
    }

    /**
     * The acting backend user uid, mirroring TelemetryMiddleware's resolution:
     * the explicit metadata uid the runtime threads through (ADR-083), else the
     * ambient backend user, else 0 (CLI / scheduler / unauthenticated).
     */
    private function resolveBeUser(ProviderCallContext $context): int
    {
        $fromMetadata = $context->metadata[BudgetMiddleware::METADATA_BE_USER_UID] ?? null;
        if (is_int($fromMetadata)) {
            return $fromMetadata;
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if ($backendUser instanceof BackendUserAuthentication && is_array($backendUser->user)) {
            $uid = $backendUser->user['uid'] ?? null;

            return is_numeric($uid) ? (int) $uid : 0;
        }

        return 0;
    }
}
