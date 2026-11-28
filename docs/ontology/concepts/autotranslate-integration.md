---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Concept
title: "Autotranslate integration"
description: "The translator class that lets EXT:autotranslate translate through the LLM service instead of DeepL."
status: draft
timestamp: 2026-11-28
---

# Definition

The autotranslate integration is a translator class for the extension EXT:autotranslate. autotranslate uses it when its setting `translatorClass` names it. The class extends the DeepL translator class that autotranslate expects, but never reaches DeepL: it passes the texts to the translation service of nr-llm, which uses the translator named in `autotranslate.translator` or, when that is empty, the language model.

# Neighbours

Generated from the relation tables by `check-ontology.py --write-diagrams`. A solid arrow is an asserted relation, a dotted arrow a hypothesis; a slanted box is a file of the repository.

```mermaid
flowchart LR
  c["Autotranslate integration"]
  n1[/"Classes/Integration/Autotranslate/AutotranslateTranslator.php"/]
  n2["Public API"]
  n3["LLM service"]
  n4[/"Tests/Unit/Integration/Autotranslate/AutotranslateTranslatorTest.php"/]
  c -->|"implementedBy"| n1
  c -->|"partOf"| n2
  c -->|"uses"| n3
  c -->|"verifiedBy"| n4
  classDef focus fill:#2F99A4,stroke:#15585E,color:#ffffff
  class c focus
```

# Relations

| Relation | Target | Evidence file | Evidence text | Status |
|---|---|---|---|---|
| `uses` | [LLM service](llm-service.md) | `Classes/Integration/Autotranslate/AutotranslateTranslator.php` | `private readonly TranslationServiceInterface $translationService,` | asserted |
| `partOf` | [Public API](public-api.md) | `Tests/Unit/Api/api-surface.txt` | `Netresearch\NrLlm\Integration\Autotranslate\AutotranslateTranslator (class)` | asserted |
| `implementedBy` | `Classes/Integration/Autotranslate/AutotranslateTranslator.php` | `Classes/Integration/Autotranslate/AutotranslateTranslator.php` | `final class AutotranslateTranslator extends Translator` | asserted |
| `verifiedBy` | `Tests/Unit/Integration/Autotranslate/AutotranslateTranslatorTest.php` | `Tests/Unit/Integration/Autotranslate/AutotranslateTranslatorTest.php` | `class AutotranslateTranslatorTest` | asserted |

# Notes

- The class name is part of the public API because it is a configuration value of autotranslate.
