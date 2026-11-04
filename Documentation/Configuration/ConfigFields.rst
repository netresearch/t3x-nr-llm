.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _configuration-llm:

==========================
Configuration field reference
==========================

Configurations define use-case presets with model
selection and parameters.

.. figure:: /Images/backend-configurations.png
   :alt: Configuration list with model assignments
   :class: with-border with-shadow
   :zoom: lightbox

   Configuration list showing linked model,
   use-case type, and parameters.

.. _configuration-llm-required:

Required
========

.. confval:: identifier (config)
   :name: confval-config-identifier
   :type: string
   :required: true

   Unique slug (e.g., ``blog-summarizer``).

.. confval:: name (config)
   :name: confval-config-name
   :type: string
   :required: true

   Display name (e.g., ``Blog Post Summarizer``).

.. confval:: model
   :name: confval-config-model
   :type: reference
   :required: true

   Reference to the model to use.

.. _confval-config-system-prompt:

.. confval:: system_prompt
   :name: confval-config-system-prompt
   :type: text
   :required: true

   System message that sets the AI's behavior.

.. _configuration-llm-optional:

Optional
========

.. confval:: temperature
   :name: confval-config-temperature
   :type: float
   :Default: 0.7

   Creativity (0.0 = deterministic, 2.0 = creative).

.. _confval-config-max-tokens:

.. confval:: max_tokens (config)
   :name: confval-config-max-tokens
   :type: integer
   :Default: 1000

   Maximum response length in tokens. Set ``0``
   to inherit the model's
   :ref:`max_output_tokens <confval-model-max-output-tokens>`.
   Precedence: per-call option > configuration
   value (> 0) > model ``max_output_tokens``
   (> 0) > provider default.

   A reasoning model of OpenAI counts its thinking against this limit, so
   nr-llm sends at least 16,000 tokens for such a model unless the call
   switches thinking off. The value bounds the length of an answer only on a
   model that does not think. See :ref:`ADR-241 <adr-241>`.

.. confval:: top_p
   :name: confval-config-top-p
   :type: float
   :Default: 1.0

   Nucleus sampling (0.0-1.0).

.. confval:: frequency_penalty
   :name: confval-config-frequency-penalty
   :type: float
   :Default: 0.0

   Reduces word repetition (-2.0 to 2.0).

.. confval:: presence_penalty
   :name: confval-config-presence-penalty
   :type: float
   :Default: 0.0

   Encourages topic diversity (-2.0 to 2.0).

.. confval:: use_case_type
   :name: confval-config-use-case-type
   :type: string
   :Default: chat

   Task type: ``chat``, ``completion``,
   ``embedding``, ``translation``.

.. confval:: fallback_chain
   :name: confval-config-fallback-chain
   :type: JSON (text column)
   :Default: (empty)

   JSON object with a single key,
   ``configurationIdentifiers``, whose value is the
   ordered list of other configuration identifiers
   to retry against when the primary fails with a
   retryable error (connection error, HTTP 5xx, or
   HTTP 429 rate-limit). Non-retryable errors bubble
   up unchanged. Streaming requests do not trigger
   fallback — chunks cannot be replayed against a
   different provider.

   Example payload::

       {"configurationIdentifiers": ["claude-sonnet", "ollama-local"]}

   Identifiers are matched case-insensitively;
   leave empty to disable fallback. See
   :ref:`developer-fallback-chain`.

.. confval:: answer_cache_ttl
   :name: confval-config-answer-cache-ttl
   :type: int (seconds)
   :Default: 0 (off)

   How long the same request to this configuration gets the same answer again
   without a provider call, at most 86400 (one day). It applies to a plain chat
   or completion. A call of an agent run, a tool call, a call bound to a
   response schema and a streamed answer are never answered from the cache. Only
   a complete text answer is stored, in JSON mode only one that parses.
   Evaluation runs always ask the model, and a caller can decline the cache for
   one call with ``ChatOptions::withoutAnswerCache()``.

   The entry is the answer verbatim, so it is stored and served only where the
   content privacy level is ``full``, globally and for the provider that served
   the answer (:ref:`ADR-242 <adr-242>`). Below that the field has no effect.

   The key covers the configuration and its last edit, the messages after skill
   injection, the per-call options, the site and the language. Saving the configuration
   therefore starts afresh, and flushing all caches empties it (cache
   ``nrllm_responses``, tag ``nrllm_answers``). A served answer consumes no tokens, is
   not charged against a budget and counts as a cache hit in telemetry. See
   :ref:`ADR-249 <adr-249>`.

.. confval:: system_prompt_data_class
   :name: confval-config-system-prompt-data-class
   :type: select
   :Default: (empty — undeclared)

   The sensitivity ceiling for :ref:`confval-config-system-prompt
   <confval-config-system-prompt>`. Declaring one refuses any send whose serving
   model sits in a weaker trust zone than the class allows, the same axis
   snippets and skills carry (:ref:`ADR-144 <adr-144>`, :ref:`ADR-155
   <adr-155>`).

   Empty means no statement was made and constrains nothing, so existing
   configurations behave exactly as before. The class describes the *text*: a
   configuration whose system prompt is blank declares nothing whatever this
   field says.

   Enforcement follows the instance-wide ``tools.dataClassEnforcement`` switch —
   see :ref:`administration-governance-keys`.
