<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\SetupWizard\Discovery;

use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DiscoveredModel;
use Throwable;

/**
 * TypeSafe's decision models (ADR-211).
 *
 * `GET /v1/models` answers `{"models": [{"name", "description",
 * "release_date"}]}` and lists the aliases an account may send; the versioned
 * id is accepted whether or not it is listed, so it is always offered — and
 * recommended, because an alias moves without notice. Every model gets the
 * `decision` capability, the one thing the API does.
 *
 * The price is the one TypeSafe publishes for jev-1.13.0, 0.042 USD per
 * million input tokens with output free, in the record's unit (cents per
 * million tokens). The aliases point at that version today; the operator
 * updates their price when TypeSafe moves them.
 */
final class TypeSafeModelDiscoverer extends AbstractModelDiscoverer
{
    private const ADAPTER = 'typesafe';

    /**
     * The pinned version the adapter defaults to
     * ({@see \Netresearch\NrLlm\Provider\TypeSafeProvider::DEFAULT_MODEL});
     * repeated here because a service may not depend on a concrete adapter.
     */
    private const PINNED_MODEL = 'jev-1.13.0';

    private const PRICE_INPUT_CENTS_PER_MILLION = 4.2;

    /** The model's documented context: 64k tokens per request. */
    private const CONTEXT_LENGTH = 64000;

    public function discover(string $endpoint, string $apiKey): DiscoveryResult
    {
        $listed = $this->listedModels($endpoint, $apiKey);

        // Without a listing the pinned version is still offered: the API
        // accepts it whether or not it is listed.
        return $listed === null
            ? DiscoveryResult::fallback($this->models([]))
            : DiscoveryResult::live($this->models($listed));
    }

    /**
     * The models `GET /v1/models` lists, or null when it could not be read.
     *
     * @return array<string, string>|null name => description
     */
    private function listedModels(string $endpoint, string $apiKey): ?array
    {
        try {
            $request = $this->requestFactory->createRequest('GET', $endpoint . self::MODELS_PATH)
                ->withHeader('Authorization', self::AUTH_BEARER_PREFIX . $apiKey);
            $response = $this->dispatch($request, self::VAULT_DISPATCH_REASON);

            if ($response->getStatusCode() !== 200) {
                $this->logDiscoveryHttpError(self::ADAPTER, $response->getStatusCode());

                return null;
            }

            $decoded = $this->decodeModelListBody(self::ADAPTER, $response->getBody()->getContents());
        } catch (Throwable $e) {
            $this->logDiscoveryFailure(self::ADAPTER, $e);

            return null;
        }

        return $decoded === null ? null : $this->namesAndDescriptions($decoded);
    }

    /**
     * @param array<array-key, mixed> $decoded `{"models": [{"name", "description", "release_date"}]}`
     *
     * @return array<string, string> name => description
     */
    private function namesAndDescriptions(array $decoded): array
    {
        $listed = [];
        foreach (is_array($decoded['models'] ?? null) ? $decoded['models'] : [] as $entry) {
            if (is_array($entry) && is_string($entry['name'] ?? null) && $entry['name'] !== '') {
                $listed[$entry['name']] = is_string($entry['description'] ?? null) ? $entry['description'] : '';
            }
        }

        return $listed;
    }

    /**
     * @param array<string, string> $listed name => description, as the API listed them
     *
     * @return list<DiscoveredModel>
     */
    private function models(array $listed): array
    {
        $models = [$this->model(self::PINNED_MODEL, 'Jev 1.13, pinned version', true)];
        foreach ($listed as $name => $description) {
            if ($name !== self::PINNED_MODEL) {
                $models[] = $this->model($name, $description !== '' ? $description : 'TypeSafe alias; moves without notice', false);
            }
        }

        return $models;
    }

    private function model(string $id, string $description, bool $recommended): DiscoveredModel
    {
        return new DiscoveredModel(
            modelId: $id,
            name: $id,
            description: $description,
            capabilities: [ModelCapability::DECISION->value],
            contextLength: self::CONTEXT_LENGTH,
            maxOutputTokens: 0,
            costInput: self::PRICE_INPUT_CENTS_PER_MILLION,
            costOutput: 0.0,
            recommended: $recommended,
        );
    }
}
