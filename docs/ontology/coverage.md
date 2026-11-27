---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Reference
title: Coverage
description: What the nr_llm ontology models, what it leaves out, and how to read its statuses.
status: draft
generated: {by: claude-code/2.1.332, at: 2026-11-27T21:00:00Z}
---

# Scope

The bundle models the concepts needed for the questions in [questions](questions/index.md): the three-tier configuration (provider, model, configuration), tasks, agent runs with their tools and skills, the middleware pipeline with budgets and guardrails, usage records, and the public API.

It describes concepts, not the records of one installation. Which providers, models and configurations exist is data in the database of each installation. The questions say which tables and fields hold that data.

# Not modelled

A missing relation means "not modelled", never "not related". These parts of nr_llm are out of scope for now:

- The Agent2Agent (A2A) protocol: agents, clients, profiles, A2A tasks, push notifications and files (tables `tx_nrllm_a2a_*`).
- AI sessions and their messages (`tx_nrllm_ai_session`, `tx_nrllm_ai_session_message`).
- Telemetry, governance events, call outcomes, evaluation results, skill audit and tool audit.
- The approval of a single tool call during a run, the workspace a run writes into, and the worker queue.
- The specialized services (DeepL, speech, image), the decision service, the editorial text check and the setup wizard.
- Properties that carry a policy value, for example trust zone, privacy level and data class. They appear in definitions only.

# Statuses

- `asserted`: a person or an agent read the evidence in the code; the check script confirms on every run that the evidence text is still present. The script does not confirm that the evidence still means the relation: review a relation when its evidence file changes.
- `hypothesis`: the relation is plausible but has no evidence in this repository. The impact output marks a step through it with "(hypothesis)".
- The frontmatter field `status: draft` marks every file as written by an agent and not yet reviewed by a person. A review adds a `verified` entry with a `human:` actor (OKF 0.3, section 5).

# Citations

[1] [Open Knowledge Format](https://okf.md/spec)
