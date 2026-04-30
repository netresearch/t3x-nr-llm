<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend\Response;

use JsonSerializable;
use Netresearch\NrLlm\Domain\Model\Provider;

/**
 * Response DTO for the Setup Wizard `saveAction` AJAX action.
 *
 * Reports the outcome of persisting a wizard run (provider + models +
 * configurations). The wizard frontend reads `result.modelsCount`,
 * `result.configurationsCount`, and `result.provider.name` to render
 * the success message at step 5.
 *
 * @internal
 */
final readonly class WizardSaveResponse implements JsonSerializable
{
    public function __construct(
        public string $message,
        public ?int $providerUid,
        public string $providerName,
        public int $modelsCount,
        public int $configurationsCount,
        public bool $success = true,
    ) {}

    public static function fromProvider(
        Provider $provider,
        int $modelsCount,
        int $configurationsCount,
        string $message = 'Configuration saved successfully',
    ): self {
        // Pass `null` through verbatim — the pre-DTO controller code
        // returned `$provider->getUid()` directly, which can be null
        // for an entity whose persistAll() hasn't run yet. Substituting
        // `0` here would change the wire shape and confuse the
        // frontend's "did the entity get a uid?" check.
        return new self(
            message: $message,
            providerUid: $provider->getUid(),
            providerName: $provider->getName(),
            modelsCount: $modelsCount,
            configurationsCount: $configurationsCount,
        );
    }

    /**
     * @return array{
     *   success: bool,
     *   message: string,
     *   provider: array{uid: int|null, name: string},
     *   modelsCount: int,
     *   configurationsCount: int
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'success'              => $this->success,
            'message'              => $this->message,
            'provider'             => [
                'uid'  => $this->providerUid,
                'name' => $this->providerName,
            ],
            'modelsCount'          => $this->modelsCount,
            'configurationsCount'  => $this->configurationsCount,
        ];
    }
}
