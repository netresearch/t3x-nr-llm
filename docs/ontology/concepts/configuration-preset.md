---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Concept
title: "Configuration preset"
description: "A configuration that a consuming extension declares by identifier and model requirements, for an administrator to import."
status: draft
timestamp: 2026-11-28
---

# Definition

A configuration preset is a configuration that a consuming extension declares it needs. It names an identifier and model requirements as selection criteria, never a concrete provider, model or API key. A preset provider is an extension point: a third party implements the preset provider interface, which carries the tag `nr_llm.configuration_preset`. nr-llm lists a declared preset that is not yet imported as pending, and an administrator imports it. The import creates a configuration in the mode `criteria` and stores the checksum of the preset, so that a later change of the declaration shows as drift and can be applied after a review.

# Not to be confused with

- The configuration that the import creates. The preset is the declaration, the configuration is the record.

# Neighbours

Generated from the relation tables by `check-ontology.py --write-diagrams`. A solid arrow is an asserted relation, a dotted arrow a hypothesis; a slanted box is a file of the repository.

```mermaid
flowchart LR
  c["Configuration preset"]
  n1[/"Classes/Service/Preset/ConfigurationPresetProviderInterface.php"/]
  n2["Public API"]
  n3[/"Tests/Unit/Service/Preset/ConfigurationPresetImportServiceTest.php"/]
  n4["Configuration"]
  c -->|"implementedBy"| n1
  c -->|"partOf"| n2
  c -->|"verifiedBy"| n3
  n4 -->|"derivedFrom"| c
  classDef focus fill:#2F99A4,stroke:#15585E,color:#ffffff
  class c focus
```

# Relations

| Relation | Target | Evidence file | Evidence text | Status |
|---|---|---|---|---|
| `partOf` | [Public API](public-api.md) | `Tests/Unit/Api/api-surface.txt` | `Netresearch\NrLlm\Service\Preset\ConfigurationPresetProviderInterface (interface)` | asserted |
| `implementedBy` | `Classes/Service/Preset/ConfigurationPresetProviderInterface.php` | `Classes/Service/Preset/ConfigurationPresetProviderInterface.php` | `interface ConfigurationPresetProviderInterface` | asserted |
| `verifiedBy` | `Tests/Unit/Service/Preset/ConfigurationPresetImportServiceTest.php` | `Tests/Unit/Service/Preset/ConfigurationPresetImportServiceTest.php` | `class ConfigurationPresetImportServiceTest` | asserted |

# Notes

- An update from a changed preset never touches the fields the administrator owns: active state, default flag, backend groups and fallback chain (`Classes/Service/Preset/ConfigurationPresetImportService.php`).
