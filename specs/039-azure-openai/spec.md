<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->

# Azure OpenAI sends the key header, the deployment path and the API version

An `azure_openai` provider record reaches Azure the way Azure documents it (ADR-247).

## Required behavior

1. Every request of an `azure_openai` provider carries the key in the `api-key` header, placed by the nr-vault HTTP client; no `Authorization` and no `OpenAI-Organization` header.
2. With a resource URL, chat, completion, tool and embedding requests, streamed or not, go to `<resource>/openai/deployments/<deployment>/<operation>?api-version=<version>`; the deployment is the call's model id, URL-encoded as one path segment; a trailing `/openai` on the URL is dropped.
3. With a URL ending in `/openai/v1`, requests go to the OpenAI paths below it, without `api-version`.
4. `models` goes to `/openai/models?api-version=…`, `responses` to `/openai/v1/responses`.
5. The provider option `apiVersion` sets the version; a value not shaped like `YYYY-MM-DD` or `YYYY-MM-DD-preview` falls back to `2024-10-21`.
6. The setup wizard's connection test and configuration call use the same URLs and header, and it detects `*.openai.azure.com`, `*.services.ai.azure.com` and `*.cognitiveservices.azure.com`.

## Not done

No Microsoft Entra ID tokens. No listing of deployments (the data plane lists models, not deployments); the deployment name is entered as the model id. No live call against Azure: no Azure OpenAI key was available.

## Boundaries

The key is placed by nr-vault and never read by nr-llm code. The `apiVersion` value is validated because it lands in a URL. `OpenAiProvider` is no longer `final`; the `@api` surface grows by `AzureOpenAiProvider` and `AbstractProvider::endpointUrl()`; additive.

## Tests

- Unit `AzureOpenAiProviderTest`, requirements 1 to 5, on the request the adapter hands to the vault client.
- Unit `ModelDiscoveryTest::testConnectionUsesTheApiKeyHeaderAndTheAzureModelsPath` and `ConfigurationGeneratorTest::generateSendsAnAzureCallToTheDeploymentWithTheKeyHeader`, requirement 6.
