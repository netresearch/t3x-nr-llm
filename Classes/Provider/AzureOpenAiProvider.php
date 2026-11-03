<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider;

use Netresearch\NrVault\Http\SecretPlacement;
use Psr\Http\Message\RequestInterface;

use function is_string;
use function sprintf;

/**
 * Azure OpenAI (ADR-247): the OpenAI wire format, with Azure's key header and
 * URLs.
 *
 * Everything the OpenAI adapter does -- payloads, tool calls, streaming,
 * embeddings, response parsing -- is inherited. What differs is where a request
 * goes and how it authenticates, both decided by {@see AzureOpenAiEndpoint}:
 * the key travels in the `api-key` header, and an endpoint that is not the v1
 * API gets the deployment path and the `api-version` query.
 *
 * The model id of an Azure model record is the DEPLOYMENT name, which is what
 * Azure routes by; the provider record's options JSON may set `apiVersion`.
 */
final class AzureOpenAiProvider extends OpenAiProvider
{
    private string $apiVersion = AzureOpenAiEndpoint::DEFAULT_API_VERSION;

    public function getName(): string
    {
        return 'Azure OpenAI';
    }

    public function getIdentifier(): string
    {
        return 'azure_openai';
    }

    /**
     * There is no Azure endpoint without a resource name; a provider record
     * without an endpoint URL fails at the request instead of reaching
     * api.openai.com with an Azure key.
     */
    protected function getDefaultBaseUrl(): string
    {
        return '';
    }

    /**
     * @param array<string, mixed> $config
     */
    public function configure(array $config): void
    {
        parent::configure($config);

        $version          = $config[AzureOpenAiEndpoint::OPTION_API_VERSION] ?? null;
        $this->apiVersion = AzureOpenAiEndpoint::apiVersion(is_string($version) ? $version : '');
    }

    protected function getSecretPlacement(): SecretPlacement
    {
        return SecretPlacement::Header;
    }

    /**
     * @return array{headerName?: string, queryParam?: string, reason?: string}
     */
    protected function getSecretPlacementOptions(): array
    {
        return [
            'headerName' => AzureOpenAiEndpoint::KEY_HEADER,
            'reason'     => sprintf('LLM API call to %s', $this->getName()),
        ];
    }

    /**
     * Azure does not read `OpenAI-Organization`; sending it would only leak
     * an OpenAI organization id configured on the wrong record.
     */
    protected function addProviderSpecificHeaders(RequestInterface $request): RequestInterface
    {
        return $request;
    }

    protected function endpointUrl(string $endpoint, array $payload = []): string
    {
        $model = $payload['model'] ?? null;

        return AzureOpenAiEndpoint::url(
            $this->baseUrl,
            $endpoint,
            is_string($model) && $model !== '' ? $model : $this->getDefaultModel(),
            $this->apiVersion,
        );
    }

    /**
     * Deployments are named by the operator, so there is no fixed list to
     * offer; the connection test lists what the resource reports.
     *
     * @return array<string, string>
     */
    public function getAvailableModels(): array
    {
        return [];
    }
}
