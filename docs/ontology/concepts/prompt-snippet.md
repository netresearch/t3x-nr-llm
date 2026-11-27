---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Concept
title: "Prompt snippet"
description: "A small named prompt fragment, for example a persona or a tone of voice, that code selects by tag."
status: draft
generated: {by: claude-code/2.1.332, at: 2026-11-27T21:06:57Z}
---

# Definition

A prompt snippet is a small named prompt fragment, for example a persona, a tone of voice or an image style. Editors manage snippets centrally. Consuming code selects snippets by tag, and a configuration can name tags whose snippets enter its prompts. ADR-031 records the decision.

# Not to be confused with

- A prompt template: a complete prompt with model parameters.

# Relations

| Relation | Target | Evidence file | Evidence text | Status |
|---|---|---|---|---|
| `implementedBy` | `Classes/Domain/Model/PromptSnippet.php` | `Classes/Domain/Model/PromptSnippet.php` | `Deliberately NOT a PromptTemplate` | asserted |
| `verifiedBy` | `Tests/Unit/Domain/Model/PromptSnippetTest.php` | `Tests/Unit/Domain/Model/PromptSnippetTest.php` | `class PromptSnippetTest` | asserted |
