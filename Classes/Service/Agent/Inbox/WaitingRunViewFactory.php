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
use Netresearch\NrLlm\Service\Agent\PendingTurnDigest;
use Netresearch\NrLlm\Service\Tool\SchemaPropertyClassifier;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;

/**
 * Turns persisted {@see AgentRun}s into the logic-free view models the approvals
 * inbox renders (ADR-109). All defensive decoding of the suspended-state blob
 * and the schema-to-field flattening live here, so the Fluid template contains
 * no logic and every branch is unit-testable without an HTTP request. The turn
 * digest a card carries is NOT computed here: it comes from
 * {@see PendingTurnDigest}, the single definition the resume path verifies
 * against (ADR-132).
 */
final readonly class WaitingRunViewFactory
{
    public function __construct(
        private ToolRegistry $registry,
        private SchemaPropertyClassifier $classifier,
        // The ONE digest definition (ADR-132), shared with ResumeCoordinator so
        // the value rendered here and the value verified there cannot drift.
        private PendingTurnDigest $digest,
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
        if (!$state instanceof SuspendedRunState || $state->inputToolName === null || !$this->isRenderableObjectSchema($state->inputSchema)) {
            return null;
        }

        return $state->inputSchema;
    }

    private function buildWaitingOne(AgentRun $run): WaitingRunView
    {
        $state = $this->decodeState($run);
        if (!$state instanceof SuspendedRunState) {
            return $this->unreadable($run, 'state-unreadable');
        }

        return $state->inputToolName === null
            ? $this->buildApproval($run, $state)
            : $this->buildInput($run, $state);
    }

    private function buildApproval(AgentRun $run, SuspendedRunState $state): WaitingRunView
    {
        // Keyed by the position the loop recorded, not by the position in this
        // (filtered) list: a corrupt call is skipped below, and matching by
        // order after a skip would attach a preview to the wrong call.
        $previews = [];
        foreach ($state->callPreviews as $preview) {
            $previews[$preview['index']] = $preview;
        }

        $calls = [];
        foreach ($state->pendingCalls as $index => $raw) {
            // tryFromArray skips a corrupt entry rather than throwing (unlike
            // SuspendedRunState::toolCalls()), so one bad call never blanks the
            // whole card.
            $call = ToolCall::tryFromArray($raw);
            if (!$call instanceof ToolCall) {
                continue;
            }

            $preview = $previews[$index] ?? null;
            $calls[] = new PendingCallView(
                name: $call->name,
                argumentsJson: $this->encodeArguments($call->arguments),
                toolStillRegistered: $this->registry->get($call->name) instanceof ToolInterface,
                // A preview stored under a different tool name than the call at
                // that position is a state nobody should be able to produce;
                // dropping it is cheaper than rendering a claim about the wrong
                // call.
                previewLines: $preview !== null && $preview['tool'] === $call->name ? $preview['lines'] : [],
                previewFailed: $preview !== null && $preview['tool'] === $call->name && $preview['failed'],
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
            turnDigest: $this->digest->forState($state),
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

            /** @var array<string, mixed> $propSchema */
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
