---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Concept
title: "Glossary"
description: "The term pairs one site uses when it translates from one language into another."
status: draft
timestamp: 2026-11-27
---

# Definition

A glossary holds the term pairs that one site uses when it translates from one language into another. The translation service reads the glossary of the site and the language pair of a translation. ADR-208 records the decision.

# Not to be confused with

- This ontology. The ontology defines the concepts of nr-llm; a glossary is content of one site.
- A term list.

# Relations

| Relation | Target | Evidence file | Evidence text | Status |
|---|---|---|---|---|
| `implementedBy` | `Classes/Service/Glossary/GlossaryResolver.php` | `Classes/Domain/Model/Glossary.php` | `One glossary holds the term pairs one site uses` | asserted |
| `verifiedBy` | `Tests/Functional/Domain/GlossaryStorageTest.php` | `Tests/Functional/Domain/GlossaryStorageTest.php` | `class GlossaryStorageTest` | asserted |
| `verifiedBy` | `Tests/Unit/Service/Glossary/GlossaryResolverTest.php` | `Tests/Unit/Service/Glossary/GlossaryResolverTest.php` | `final class GlossaryResolverTest extends TestCase` | asserted |
