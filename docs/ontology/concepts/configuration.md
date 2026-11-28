---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Concept
title: "Configuration"
description: "A named use-case bundle: a model or model criteria, prompt, parameters, limits, access and agent settings."
tags: [three-tier]
status: draft
timestamp: 2026-11-27
---

# Definition

A configuration is a named bundle for one use case. It names a model, or criteria by which a model is chosen at request time. It holds the system prompt, the model parameters, daily limits, the backend groups that may use it, and the skills, tool groups and guardrails an agent run under it may use. A configuration is the third tier of the three-tier configuration, and the unit that consuming code asks for by identifier.

# Not to be confused with

- The extension configuration of TYPO3 (`ext_conf_template.txt`). That configures nr-llm as a whole.
- A configuration preset: a consuming extension declares the configurations it needs as presets, and an administrator imports one. A preset states requirements (model capabilities and constraints), never a concrete provider or model; the imported configuration runs in the mode `criteria` (`Documentation/Developer/ConfigurationPresets.rst`).

# Neighbours

Generated from the relation tables by `check-ontology.py --write-diagrams`. A solid arrow is an asserted relation, a dotted arrow a hypothesis; a slanted box is a file of the repository.

```mermaid
flowchart LR
  c["Configuration"]
  n1["Backend user group"]
  n2["Guardrail"]
  n3["Term list"]
  n4[/"Classes/Domain/Model/LlmConfiguration.php"/]
  n5["Model"]
  n6["Prompt snippet"]
  n7["Skill"]
  n8["Tool group"]
  n9[/"Tests/Unit/Domain/DTO/FallbackChainTest.php"/]
  n10[/"Tests/Unit/Domain/Model/LlmConfigurationTest.php"/]
  n11[/"Tests/Unit/Service/ModelSelectionServiceTest.php"/]
  n12["Middleware pipeline"]
  n13["Streaming dispatcher"]
  n14["Agent run"]
  n15["LLM service"]
  n16["Task"]
  c -->|"constrainedBy"| n1
  c -->|"constrainedBy"| c
  c -->|"constrainedBy"| n2
  c -->|"constrainedBy"| n3
  c -->|"fallsBackTo"| c
  c -->|"implementedBy"| n4
  c -->|"uses"| n5
  c -->|"uses"| n6
  c -->|"uses"| n7
  c -->|"uses"| n8
  c -->|"verifiedBy"| n9
  c -->|"verifiedBy"| n10
  c -->|"verifiedBy"| n11
  n12 -->|"constrainedBy"| c
  n13 -->|"constrainedBy"| c
  n14 -->|"uses"| c
  n15 -->|"uses"| c
  n16 -->|"uses"| c
  classDef focus fill:#2F99A4,stroke:#15585E,color:#ffffff
  class c focus
```

# Relations

| Relation | Target | Evidence file | Evidence text | Status |
|---|---|---|---|---|
| `uses` | [Model](model.md) | `Configuration/TCA/tx_nrllm_configuration.php` | `'model_uid' =>` | asserted |
| `uses` | [Model](model.md) | `Configuration/TCA/tx_nrllm_configuration.php` | `'model_selection_criteria' =>` | asserted |
| `fallsBackTo` | [Configuration](configuration.md) | `Classes/Domain/DTO/FallbackChain.php` | `Ordered list of LlmConfiguration identifiers to try when the primary` | asserted |
| `constrainedBy` | [Guardrail](guardrail.md) | `Configuration/TCA/tx_nrllm_configuration.php` | `'allowed_guardrails' =>` | asserted |
| `constrainedBy` | [Term list](term-list.md) | `Configuration/TCA/tx_nrllm_term_list.php` | `'foreign_table' => 'tx_nrllm_configuration'` | asserted |
| `constrainedBy` | [Configuration](configuration.md) | `Configuration/TCA/tx_nrllm_configuration.php` | `'rule_judge_configuration' =>` | asserted |
| `constrainedBy` | [Backend user group](backend-user-group.md) | `ext_tables.sql` | `CREATE TABLE tx_nrllm_configuration_begroups_mm` | asserted |
| `uses` | [Skill](skill.md) | `ext_tables.sql` | `CREATE TABLE tx_nrllm_configuration_skill_mm` | asserted |
| `uses` | [Tool group](tool-group.md) | `Configuration/TCA/tx_nrllm_configuration.php` | `'allowed_tool_groups' =>` | asserted |
| `uses` | [Prompt snippet](prompt-snippet.md) | `Configuration/TCA/tx_nrllm_configuration.php` | `'snippet_tags' =>` | asserted |
| `implementedBy` | `Classes/Domain/Model/LlmConfiguration.php` | `Classes/Domain/Model/LlmConfiguration.php` | `class LlmConfiguration extends AbstractEntity` | asserted |
| `verifiedBy` | `Tests/Unit/Domain/Model/LlmConfigurationTest.php` | `Tests/Unit/Domain/Model/LlmConfigurationTest.php` | `class LlmConfigurationTest` | asserted |
| `verifiedBy` | `Tests/Unit/Domain/DTO/FallbackChainTest.php` | `Tests/Unit/Domain/DTO/FallbackChainTest.php` | `class FallbackChainTest` | asserted |
| `verifiedBy` | `Tests/Unit/Service/ModelSelectionServiceTest.php` | `Tests/Unit/Service/ModelSelectionServiceTest.php` | `class ModelSelectionServiceTest` | asserted |

# Notes

- The two `uses` rows to Model are two modes. In the mode `fixed`, the configuration names one model in `model_uid`. In the mode `criteria`, the model selection service picks a model at request time, so every model that meets the criteria is a candidate. A change to a model therefore affects a criteria configuration only if that model is or becomes a candidate.
- The `constrainedBy` row to Configuration is the rule judge: a second configuration that judges the output of this one.
- The fallback chain stores configuration identifiers, not record IDs. A renamed identifier breaks the chain.
- The budget middleware checks the daily limits of the primary configuration only; a configuration the fallback chain switches to is not checked again (comment in `Classes/Provider/Middleware/BudgetMiddleware.php`).
