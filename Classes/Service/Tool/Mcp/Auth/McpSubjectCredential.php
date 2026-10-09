<?php

/* Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later */
declare (strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\Auth;

/**
 * An explicit actor's subject-token reference and bounded grant (ADR-217).
 * No plaintext access or refresh token is carried by this value.
 *
 * @api
 */
final readonly class McpSubjectCredential
{
    /** @var list<string> */
    public array $allowedAudiences;

    /** @var list<string> */
    public array $allowedScopes;

    /**
     * @param list<string> $allowedAudiences
     * @param list<string> $allowedScopes
     */
    public function __construct(public string $credentialIdentifier, array $allowedAudiences, array $allowedScopes)
    {
        McpAuthValidation::credentialIdentifier($credentialIdentifier);
        $this->allowedAudiences = McpAuthValidation::audiences($allowedAudiences);
        $this->allowedScopes = McpAuthValidation::scopes($allowedScopes);
    }
}
