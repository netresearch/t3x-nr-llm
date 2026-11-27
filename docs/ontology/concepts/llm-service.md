---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Concept
title: "LLM service"
description: "The public call surface: the LLM service manager and the feature services for completion, conversation, embedding, tools, translation and vision."
status: draft
generated: {by: claude-code/2.1.332, at: 2026-11-27T21:06:57Z}
---

# Definition

The LLM service is the surface that consuming code calls. Its entry point is the LLM service manager. Behind it, one feature service exists for each kind of call: completion, conversation, embedding, tool calling, translation and vision. Every call runs through the middleware pipeline.

# Relations

| Relation | Target | Evidence file | Evidence text | Status |
|---|---|---|---|---|
| `uses` | [Middleware pipeline](middleware-pipeline.md) | `Classes/Service/LlmServiceManager.php` | `private MiddlewarePipeline $pipeline` | asserted |
| `uses` | [Configuration](configuration.md) | `Classes/Service/LlmServiceManager.php` | `public function chatWithConfiguration(` | asserted |
| `uses` | [Glossary](glossary.md) | `Classes/Service/Feature/TranslationService.php` | `GlossaryResolverInterface $glossaryResolver` | asserted |
| `partOf` | [Public API](public-api.md) | `Documentation/Api/Stability.rst` | `Classes and interfaces you *call*: the feature services` | asserted |
| `implementedBy` | `Classes/Service/LlmServiceManager.php` | `Classes/Service/LlmServiceManager.php` | `class LlmServiceManager` | asserted |
| `verifiedBy` | `Tests/Unit/Service/LlmServiceManagerTest.php` | `Tests/Unit/Service/LlmServiceManagerTest.php` | `class LlmServiceManagerTest` | asserted |

# Notes

- The glossary row holds for translation calls only.
