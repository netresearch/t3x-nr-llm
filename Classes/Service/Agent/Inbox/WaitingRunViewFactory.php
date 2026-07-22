<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Inbox;

use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Service\Tool\SchemaPropertyClassifier;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;

/**
 * Turns persisted {@see AgentRun}s into the logic-free view models the approvals
 * inbox renders (ADR-109). All defensive decoding of the suspended-state blob,
 * the schema-to-field flattening and the stale-review turn digest live here, so
 * the Fluid template contains no logic and every branch is unit-testable without
 * an HTTP request.
 */
final readonly class WaitingRunViewFactory
{
    public function __construct(
        private ToolRegistry $registry,
        private SchemaPropertyClassifier $classifier,
    ) {}

    /**
     * @param list<AgentRun> $runs
     *
     * @return list<WaitingRunView>
     */
    public function buildWaiting(array $runs): array
    {
        return array_map($this->buildWaitingOne(...), $runs);
    }

    /**
     * @param list<AgentRun> $runs
     *
     * @return list<TerminalRunView>
     */
    public function buildTerminal(array $runs): array
    {
        return array_map(
            fn(AgentRun $run): TerminalRunView => new TerminalRunView(
                runUuid: $run->uuid,
                status: $run->status,
                createdAt: $run->crdate,
                finishedAt: $run->finishedAt,
                configLabel: $this->configLabel($run),
                formattedCost: $run->estimatedCost > 0.0 ? number_format($run->estimatedCost, 4) : null,
            ),
            $runs,
        );
    }

    /**
     * The digest of a suspended run's pending turn — a stable hash of the pending
     * tool calls the operator reviewed. The controller recomputes this from the
     * freshly-loaded current state and refuses to approve on a mismatch, so a
     * stale tab (or a second admin) cannot authorize a turn the operator never
     * saw (ADR-109 stale-review guard). Same method on both paths ⇒ identical
     * digest for identical pending calls.
     */
    public function pendingTurnDigest(SuspendedRunState $state): string
    {
        $json = json_encode($state->pendingCalls, JSON_INVALID_UTF8_SUBSTITUTE);

        return hash('sha256', $json !== false ? $json : serialize($state->pendingCalls));
    }

    /**
     * The current pending-turn digest for a freshly-loaded run, or null when its
     * state is unreadable or it is not an approval pause. The controller compares
     * this against the digest the operator reviewed and refuses a stale approval
     * on a mismatch (ADR-109 stale-review guard).
     */
    public function turnDigestForRun(AgentRun $run): ?string
    {
        $state = $this->decodeState($run);
        if ($state === null || $state->inputToolName !== null) {
            return null;
        }

        return $this->pendingTurnDigest($state);
    }

    /**
     * The current input schema for a freshly-loaded run, or null when its state
     * is unreadable, it is not an input pause, or the schema cannot be rendered
     * as a form. The controller coerces the POST against THIS (current) schema
     * before submitting, and the runtime re-validates against it too.
     *
     * @return array<string, mixed>|null
     */
    public function inputSchemaForRun(AgentRun $run): ?array
    {
        $state = $this->decodeState($run);
        if ($state === null || $state->inputToolName === null || !$this->isRenderableObjectSchema($state->inputSchema)) {
            return null;
        }

        return $state->inputSchema;
    }

    private function buildWaitingOne(AgentRun $run): WaitingRunView
    {
        $state = $this->decodeState($run);
        if ($state === null) {
            return $this->unreadable($run, 'state-unreadable');
        }

        return $state->inputToolName === null
            ? $this->buildApproval($run, $state)
            : $this->buildInput($run, $state);
    }

    private function buildApproval(AgentRun $run, SuspendedRunState $state): WaitingRunView
    {
        $calls = [];
        foreach ($state->pendingCalls as $raw) {
            // tryFromArray skips a corrupt entry rather than throwing (unlike
            // SuspendedRunState::toolCalls()), so one bad call never blanks the
            // whole card.
            $call = ToolCall::tryFromArray($raw);
            if (!$call instanceof ToolCall) {
                continue;
            }
            $calls[] = new PendingCallView(
                name: $call->name,
                argumentsJson: $this->encodeArguments($call->arguments),
                toolStillRegistered: $this->registry->get($call->name) !== null,
            );
        }

        if ($calls === []) {
            return $this->unreadable($run, 'no-pending-calls');
        }

        return new WaitingRunView(
            runUuid: $run->uuid,
            mode: WaitingRunView::MODE_APPROVAL,
            createdAt: $run->crdate,
            configLabel: $this->configLabel($run),
            turnDigest: $this->pendingTurnDigest($state),
            pendingCalls: $calls,
        );
    }

    private function buildInput(AgentRun $run, SuspendedRunState $state): WaitingRunView
    {
        // BLOCKER fix (ADR-109): InputSchema::isUsable() returns true for a
        // scalar top-level schema like {"type":"string"}, which would yield an
        // empty, unsubmittable no-JS form. Render the field form ONLY for an
        // object schema with >= 1 property; otherwise fail closed to unreadable.
        if (!$this->isRenderableObjectSchema($state->inputSchema)) {
            return $this->unreadable($run, 'schema-not-renderable');
        }

        $fields = $this->buildFields($state->inputSchema);
        if ($fields === []) {
            return $this->unreadable($run, 'schema-no-fields');
        }

        return new WaitingRunView(
            runUuid: $run->uuid,
            mode: WaitingRunView::MODE_INPUT,
            createdAt: $run->crdate,
            configLabel: $this->configLabel($run),
            inputFields: $fields,
        );
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function isRenderableObjectSchema(array $schema): bool
    {
        $type       = $schema['type'] ?? null;
        $isObject   = $type === null || $type === 'object' || (is_array($type) && in_array('object', $type, true));
        $properties = $schema['properties'] ?? null;

        return $isObject && is_array($properties) && $properties !== [];
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<InputFieldView>
     */
    private function buildFields(array $schema): array
    {
        $properties = $schema['properties'] ?? [];
        $required   = $schema['required'] ?? [];
        $required   = is_array($required) ? array_values(array_filter($required, is_string(...))) : [];

        if (!is_array($properties)) {
            return [];
        }

        $fields = [];
        foreach ($properties as $name => $propSchema) {
            if (!is_array($propSchema)) {
                continue;
            }
            $name        = (string)$name;
            $controlType = $this->classifier->classify($propSchema);
            $fields[]    = new InputFieldView(
                name: $name,
                label: $this->fieldLabel($name, $propSchema),
                controlType: $controlType,
                required: in_array($name, $required, true),
                htmlType: $controlType === SchemaPropertyClassifier::INTEGER || $controlType === SchemaPropertyClassifier::NUMBER ? 'number' : 'text',
                step: match ($controlType) {
                    SchemaPropertyClassifier::INTEGER => '1',
                    SchemaPropertyClassifier::NUMBER  => 'any',
                    default                           => '',
                },
                inputMode: match ($controlType) {
                    SchemaPropertyClassifier::INTEGER => 'numeric',
                    SchemaPropertyClassifier::NUMBER  => 'decimal',
                    default                           => '',
                },
                options: $this->enumOptions($propSchema),
                description: is_string($propSchema['description'] ?? null) ? $propSchema['description'] : null,
            );
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $propSchema
     */
    private function fieldLabel(string $name, array $propSchema): string
    {
        $title = $propSchema['title'] ?? null;
        if (is_string($title) && $title !== '') {
            return $title;
        }

        return ucfirst(str_replace('_', ' ', $name));
    }

    /**
     * @param array<string, mixed> $propSchema
     *
     * @return list<string>
     */
    private function enumOptions(array $propSchema): array
    {
        $enum = $propSchema['enum'] ?? null;
        if (!is_array($enum)) {
            return [];
        }

        return array_values(array_map(
            static fn(mixed $v): string => is_scalar($v) ? (string)$v : '',
            $enum,
        ));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function encodeArguments(array $arguments): string
    {
        $json = json_encode($arguments, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return $json !== false ? $json : '{}';
    }

    private function decodeState(AgentRun $run): ?SuspendedRunState
    {
        $raw = $run->suspendedState;
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return SuspendedRunState::fromArray($decoded);
    }

    private function configLabel(AgentRun $run): string
    {
        return $run->configurationIdentifier !== '' ? $run->configurationIdentifier : '—';
    }

    private function unreadable(AgentRun $run, string $reason): WaitingRunView
    {
        return new WaitingRunView(
            runUuid: $run->uuid,
            mode: WaitingRunView::MODE_UNREADABLE,
            createdAt: $run->crdate,
            configLabel: $this->configLabel($run),
            unreadableReason: $reason,
        );
    }
}
