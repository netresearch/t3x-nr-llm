<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolInvocationDecision;
use Netresearch\NrLlm\Domain\ValueObject\ToolInvocationTarget;
use Netresearch\NrLlm\Exception\LogicException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;

/**
 * A conjunction of installation rules, evaluated with each invocation.
 *
 * @api
 */
final readonly class ToolInvocationPolicy implements ToolInvocationPolicyInterface, PreApprovalInvocationPolicyInterface
{
    /** @var array<string, ToolInvocationRuleInterface> */
    private array $rules;

    /** @var list<ToolTargetResolverInterface> */
    private array $resolvers;

    /**
     * @param iterable<ToolInvocationRuleInterface> $rules
     * @param iterable<ToolTargetResolverInterface> $resolvers
     */
    public function __construct(
        #[AutowireIterator(ToolInvocationRuleInterface::TAG_NAME)]
        iterable $rules = [],
        #[AutowireIterator(ToolTargetResolverInterface::TAG_NAME)]
        iterable $resolvers = [],
    ) {
        $indexed = [];
        foreach ($rules as $rule) {
            $id = $rule->identifier();
            if ($id === '' || preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/', $id) !== 1 || isset($indexed[$id])) {
                throw new LogicException(
                    'Invocation rule identifiers must be unique stable codes.',
                    1791530403,
                );
            }

            $indexed[$id] = $rule;
        }

        $this->rules     = $indexed;
        $this->resolvers = is_array($resolvers) ? array_values($resolvers) : iterator_to_array($resolvers, false);
    }

    public function resolveTarget(
        ToolCall $call,
        ToolExecutionContext $context,
    ): ?ToolInvocationTarget {
        $target = null;
        foreach ($this->resolvers as $resolver) {
            $resolved = $resolver->resolve($call, $context);
            if ($resolved !== null) {
                if ($target !== null) {
                    throw new LogicException(
                        'More than one resolver claimed an invocation target.',
                        1791530404,
                    );
                }

                $target = $resolved;
            }
        }

        return $target;
    }

    public function decide(
        ToolInvocationContext $context,
    ): ToolInvocationDecision {
        return $this->decideWith($this->rules, $context);
    }

    /**
     * The rules that opted in through {@see PreApprovalInvocationRuleInterface}
     * and nothing else. The first denial wins, as in {@see self::decide()}; a
     * rule that throws denies, so a broken rule refuses the call at the card
     * and is not retried when the call runs.
     */
    public function decideBeforeApproval(
        ToolInvocationContext $context,
    ): ToolInvocationDecision {
        return $this->decideWith(
            array_filter($this->rules, static fn (ToolInvocationRuleInterface $rule): bool => $rule instanceof PreApprovalInvocationRuleInterface),
            $context,
        );
    }

    /**
     * @param array<string, ToolInvocationRuleInterface> $rules
     */
    private function decideWith(array $rules, ToolInvocationContext $context): ToolInvocationDecision
    {
        foreach ($rules as $identifier => $rule) {
            try {
                $decision = $rule->requiresCompleteHistory() && !$context->history->complete ? ToolInvocationDecision::deny('history_incomplete') : $rule->decide($context);
            } catch (Throwable) {
                $decision = ToolInvocationDecision::deny('rule_failed');
            }

            if (!$decision->allowed) {
                return $decision->forRule($identifier);
            }
        }

        return ToolInvocationDecision::allow();
    }
}
