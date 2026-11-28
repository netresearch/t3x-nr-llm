<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->

# Update Log

## 2026-11-28

* **Diagrams**: Every concept with relations shows a generated "# Neighbours" Mermaid diagram of its relations in both directions.
* **Concepts**: Added Tool enable state, Site LLM settings, Keyword search, Reranker, Configuration preset, AI-written record and Autotranslate integration.
* **Relations**: An agent run is constrained by the tool enable state and by its skills; a configuration is derived from a configuration preset and uses usage records; the LLM service uses the site LLM settings.
* **Notes**: Corrected which gates decide the tools of an agent run, the user-less call in the user budget, and how a call without a configuration gets one; listed backend modules and console commands as not modelled.

## 2026-11-27

* **Init**: Created the bundle with 24 concepts, the relation vocabulary, five competency questions and the coverage statement.
