<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend\Response;

use JsonSerializable;

/**
 * Response DTO for the Setup Wizard `testAction` AJAX action.
 *
 * Distinct from the broader `TestConnectionResponse` used by
 * `ConfigurationController::testProviderConnectionAction()`, which
 * additionally exposes a discovered model list. The wizard's
 * `testAction` only confirms reachability — model discovery happens
 * in the dedicated `discoverAction` step — so this DTO mirrors the
 * exact wire shape (`{success, message}`) the wizard frontend
 * (`Backend/SetupWizard.js`) reads at step 2.
 *
 * Keeping the DTO narrow avoids adding an unused `models: []` field
 * to the JSON body and preserves byte-for-byte parity with the
 * pre-DTO inline literal.
 *
 * @internal
 */
final readonly class WizardTestConnectionResponse implements JsonSerializable
{
    public function __construct(
        public bool $success,
        public string $message,
    ) {}

    /**
     * Create from a model-discovery service `testConnection()` result.
     *
     * @param array{success: bool, message: string} $result
     */
    public static function fromResult(array $result): self
    {
        return new self(
            success: $result['success'],
            message: $result['message'],
        );
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
        ];
    }
}
