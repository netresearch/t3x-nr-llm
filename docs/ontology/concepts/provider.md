---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Concept
title: "Provider"
description: "A configured connection to one LLM vendor API: endpoint, credentials and adapter type."
tags: [three-tier]
status: draft
generated: {by: claude-code/2.1.332, at: 2026-11-27T21:06:57Z}
---

# Definition

A provider is a configured connection to the API of one LLM vendor. It holds the endpoint, the reference to the API key and the adapter type. Several providers can share one adapter type, for example `openai-prod` and `openai-dev`. A provider is the first tier of the three-tier configuration (provider, model, configuration).

# Not to be confused with

- The vendor company (OpenAI, Anthropic). One vendor can have several providers.
- The provider adapter: the PHP class that speaks the vendor API. A provider selects an adapter through its adapter type.

# Relations

| Relation | Target | Evidence file | Evidence text | Status |
|---|---|---|---|---|
| `uses` | [Provider adapter](provider-adapter.md) | `Configuration/TCA/tx_nrllm_provider.php` | `'adapter_type' =>` | asserted |
| `uses` | [nr-vault secret](https://github.com/netresearch/t3x-nr-vault) | `Classes/Domain/Model/Provider.php` | `This method retrieves the API key from nr-vault using the stored identifier.` | asserted |
| `implementedBy` | `Classes/Domain/Model/Provider.php` | `Classes/Domain/Model/Provider.php` | `class Provider extends AbstractEntity` | asserted |
| `verifiedBy` | `Tests/Unit/Domain/Model/ProviderTest.php` | `Tests/Unit/Domain/Model/ProviderTest.php` | `class ProviderTest` | asserted |

# Notes

- The API key is stored in nr-vault (the extension `netresearch/nr-vault`). The provider holds only the vault identifier.
