---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Concept
title: "Provider adapter"
description: "The PHP class that speaks one vendor API behind the common provider contract."
status: draft
timestamp: 2026-11-27
---

# Definition

A provider adapter is the PHP class that sends requests to one vendor API and returns typed responses. Every adapter implements the common provider contract and optional capability interfaces (streaming, tools, vision, documents). The adapter type of a provider selects the adapter.

# Not to be confused with

- A provider record. The adapter is code; the provider is configuration in the database.

# Relations

| Relation | Target | Evidence file | Evidence text | Status |
|---|---|---|---|---|
| `implementedBy` | `Classes/Provider/AbstractProvider.php` | `Classes/Provider/AbstractProvider.php` | `abstract class AbstractProvider implements ProviderInterface` | asserted |
| `implementedBy` | `Classes/Provider/Contract/ProviderInterface.php` | `Classes/Provider/Contract/ProviderInterface.php` | `interface ProviderInterface` | asserted |
| `verifiedBy` | `Tests/Unit/Provider/AbstractProviderTest.php` | `Tests/Unit/Provider/AbstractProviderTest.php` | `class AbstractProviderTest` | asserted |

# Notes

- The adapters for OpenAI, Claude, Gemini, Groq, Mistral, Ollama and OpenRouter are in `Classes/Provider/`.
