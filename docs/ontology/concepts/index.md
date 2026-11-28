<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->

# Concepts

* [Provider](provider.md) - A configured connection to one LLM vendor API: endpoint, credentials and adapter type.
* [Provider adapter](provider-adapter.md) - The PHP class that speaks one vendor API behind the common provider contract.
* [Model](model.md) - One LLM model of one provider, with its capabilities, limits and prices.
* [Configuration](configuration.md) - A named use-case bundle: a model or model criteria, prompt, parameters, limits, access and agent settings.
* [Task](task.md) - A predefined one-shot prompt that runs on one configuration.
* [Agent run](agent-run.md) - One execution of a tool-using agent under one configuration, on behalf of one backend user.
* [Tool](tool.md) - An action that a model can call during an agent run: built-in or imported from an MCP server.
* [Tool group](tool-group.md) - A named group of tools that a configuration can allow as a whole.
* [MCP server](mcp-server.md) - A registered remote Model Context Protocol server whose tools nr-llm imports.
* [Skill source](skill-source.md) - A place skills come from: one SKILL.md file, a repository or a marketplace index on GitHub, or the TYPO3 backend for skills written there.
* [Skill](skill.md) - Instructions for a model in one version, imported from a SKILL.md file of a skill source or written in the backend.
* [Skill approval](skill-approval.md) - The record that a person approved one version of one skill from one source.
* [LLM service](llm-service.md) - The public call surface: the LLM service manager and the feature services for completion, conversation, embedding, tools, translation and vision.
* [Middleware pipeline](middleware-pipeline.md) - The chain of steps a non-streamed call through the LLM service passes: budget, cache, circuit breaker, empty answer, fallback, tracing, guardrail, idempotency, telemetry and usage.
* [Guardrail](guardrail.md) - A check on the input or the output of a call that can redact, annotate or block it.
* [Term list](term-list.md) - A list of terms that must not appear, or must appear, in the output of the configurations it names, optionally for one site and one language.
* [Glossary](glossary.md) - The term pairs one site uses when it translates from one language into another.
* [Prompt snippet](prompt-snippet.md) - A small named prompt fragment, for example a persona or a tone of voice, that code selects by tag.
* [User budget](user-budget.md) - Daily and monthly ceilings for the requests, tokens and cost of one backend user.
* [Usage record](usage-record.md) - One row of usage: requests, tokens and cost per service, configuration, model, task, user and day.
* [Backend user](backend-user.md) - A TYPO3 backend account; nr-llm budgets, permits and acts on behalf of it.
* [Backend user group](backend-user-group.md) - A TYPO3 backend group; a configuration names the groups that may use it.
* [Public API](public-api.md) - The classes and methods marked @api, frozen in a snapshot that a test compares against.
* [Streaming dispatcher](streaming-dispatcher.md) - The path of a streamed call: it runs the budget check before the stream and records usage and telemetry around it, in place of the middleware pipeline.
* [Tool enable state](tool-enable-state.md) - The global switch per tool and per tool group with which an administrator turns tools off for every run.
* [Site LLM settings](site-llm-settings.md) - The site settings with which a TYPO3 site names the configuration its calls use and the vault identifier of its DeepL key.
* [Keyword search](keyword-search.md) - The public keyword search over the site search: a facade over a cascade of search backends that third parties can add.
* [Reranker](reranker.md) - The public interface that scores retrieval candidates against a query with a cross-encoder.
* [Configuration preset](configuration-preset.md) - A configuration that a consuming extension declares by identifier and model requirements, for an administrator to import.
* [AI-written record](ai-written-record.md) - A page or content element that an agent run created or changed, marked on the row.
* [Autotranslate integration](autotranslate-integration.md) - The translator class that lets EXT:autotranslate translate through the LLM service instead of DeepL.
