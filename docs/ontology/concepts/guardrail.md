---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Concept
title: "Guardrail"
description: "A check on the input or the output of a call that can redact, annotate or block it."
status: draft
timestamp: 2026-11-27
---

# Definition

A guardrail is a check on the input or the output of a call. It can redact secrets, add a hint, or block the answer. A configuration names the guardrails it allows; a policy resolver decides which ones apply to a call.

# Neighbours

Generated from the relation tables by `check-ontology.py --write-diagrams`. A solid arrow is an asserted relation, a dotted arrow a hypothesis; a slanted box is a file of the repository.

```mermaid
flowchart LR
  c["Guardrail"]
  n1[/"Classes/Service/Guardrail/GuardrailInterface.php"/]
  n2[/"Classes/Service/Guardrail/GuardrailPolicyResolver.php"/]
  n3["Public API"]
  n4[/"Tests/Unit/Service/Guardrail/GuardrailPolicyResolverTest.php"/]
  n5["Configuration"]
  n6["Middleware pipeline"]
  n7["Term list"]
  c -->|"implementedBy"| n1
  c -->|"implementedBy"| n2
  c -->|"partOf"| n3
  c -->|"verifiedBy"| n4
  n5 -->|"constrainedBy"| c
  n6 -->|"constrainedBy"| c
  n7 -->|"partOf"| c
  classDef focus fill:#2F99A4,stroke:#15585E,color:#ffffff
  class c focus
```

# Relations

| Relation | Target | Evidence file | Evidence text | Status |
|---|---|---|---|---|
| `implementedBy` | `Classes/Service/Guardrail/GuardrailInterface.php` | `Classes/Service/Guardrail/GuardrailInterface.php` | `interface GuardrailInterface` | asserted |
| `implementedBy` | `Classes/Service/Guardrail/GuardrailPolicyResolver.php` | `Classes/Service/Guardrail/GuardrailPolicyResolver.php` | `class GuardrailPolicyResolver` | asserted |
| `partOf` | [Public API](public-api.md) | `Classes/Service/Guardrail/GuardrailInterface.php` | `@api Extension point` | asserted |
| `verifiedBy` | `Tests/Unit/Service/Guardrail/GuardrailPolicyResolverTest.php` | `Tests/Unit/Service/Guardrail/GuardrailPolicyResolverTest.php` | `final class GuardrailPolicyResolverTest extends TestCase` | asserted |
