---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Concept
title: "Term list"
description: "A list of terms that must not appear, or must appear, in the output of the configurations it names, optionally for one site and one language."
status: draft
timestamp: 2026-11-27
---

# Definition

A term list names terms that must not appear, or must appear, in an output. The term list guardrail checks the output of every configuration the list names. A list can be limited to one site and one language; an empty site or language means every site or every language, and required terms apply only to text outputs of a call whose site and language match. A call without site or language is still checked against the forbidden terms of a limited list. In a run that offers tools, the guardrail checks only the arguments of writing-tool calls, and only for forbidden terms. A violation blocks the output with the reason. ADR-238 records the decision.

# Not to be confused with

- A glossary. A glossary maps a term of one language to a term of another language for translation.

# Neighbours

Generated from the relation tables by `check-ontology.py --write-diagrams`. A solid arrow is an asserted relation, a dotted arrow a hypothesis; a slanted box is a file of the repository.

```mermaid
flowchart LR
  c["Term list"]
  n1[/"Classes/Service/Guardrail/Term/TermListGuardrail.php"/]
  n2["Guardrail"]
  n3[/"Tests/Unit/Service/Guardrail/Term/TermListGuardrailTest.php"/]
  n4["Configuration"]
  c -->|"implementedBy"| n1
  c -->|"partOf"| n2
  c -->|"verifiedBy"| n3
  n4 -->|"constrainedBy"| c
  classDef focus fill:#2F99A4,stroke:#15585E,color:#ffffff
  class c focus
```

# Relations

| Relation | Target | Evidence file | Evidence text | Status |
|---|---|---|---|---|
| `partOf` | [Guardrail](guardrail.md) | `Classes/Service/Guardrail/Term/TermListGuardrail.php` | `final readonly class TermListGuardrail implements ContextualGuardrailInterface` | asserted |
| `implementedBy` | `Classes/Service/Guardrail/Term/TermListGuardrail.php` | `Classes/Service/Guardrail/Term/TermListGuardrail.php` | `final readonly class TermListGuardrail` | asserted |
| `verifiedBy` | `Tests/Unit/Service/Guardrail/Term/TermListGuardrailTest.php` | `Tests/Unit/Service/Guardrail/Term/TermListGuardrailTest.php` | `final class TermListGuardrailTest extends AbstractUnitTestCase` | asserted |
