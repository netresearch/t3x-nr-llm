.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _configuration-llm:

=============================
Configuration field reference
=============================

A configuration combines model selection, prompt composition, budgets and
access rules. The operation comes from the calling service; there is no
``use_case_type`` field. Fixed selection needs a usable ``model_uid`` at
execution. Criteria selection resolves a model for each operation.

Defaults below are form defaults from TCA, not promises that a blank record
can execute. ``system_prompt`` is optional. Zero budget limits mean unlimited;
checks are preflight checks, not atomic reservations.

.. list-table:: TCA fields (0.43.20 source)
   :header-rows: 1
   :widths: 22 12 12 8 46

   * - Field
     - TCA type
     - Default
     - Required in TCA
     - Effect
   * - ``hidden``
     - check
     - ``0``
     - no
     - TYPO3 record visibility; separate from runtime is_active.
   * - ``identifier``
     - input
     - ``unset in TCA``
     - yes
     - Unique programmatic identifier.
   * - ``name``
     - input
     - ``unset in TCA``
     - yes
     - Backend display name.
   * - ``description``
     - text
     - ``unset in TCA``
     - no
     - Description for operators.
   * - ``model_selection_mode``
     - select
     - ``"fixed"``
     - no
     - fixed selects model_uid; criteria resolves an eligible model per operation.
   * - ``model_uid``
     - select
     - ``0``
     - no
     - Fixed model reference; shown only in fixed mode. TCA permits zero; execution still needs a usable model.
   * - ``model_selection_criteria``
     - select
     - ``empty``
     - no
     - Required capabilities and routing criteria; shown in criteria mode.
   * - ``translator``
     - select
     - ``empty``
     - no
     - Specialized translator selection; not the LLM operation type.
   * - ``system_prompt``
     - text
     - ``unset in TCA``
     - no
     - Optional system instruction; not required by TCA.
   * - ``system_prompt_data_class``
     - select
     - ``unset in TCA``
     - no
     - Declared sensitivity of non-empty system text; empty is undeclared.
   * - ``temperature``
     - number
     - ``0.7``
     - no
     - Requested sampling temperature; adapter/model support varies.
   * - ``max_tokens``
     - number
     - ``1000``
     - no
     - Requested output budget; zero inherits. Reasoning adapters may raise it.
   * - ``top_p``
     - number
     - ``1``
     - no
     - Requested nucleus-sampling value; adapter/model support varies.
   * - ``frequency_penalty``
     - number
     - ``0``
     - no
     - Requested repetition penalty; adapter support varies.
   * - ``presence_penalty``
     - number
     - ``0``
     - no
     - Requested topic-presence penalty; adapter support varies.
   * - ``timeout``
     - number
     - ``0``
     - no
     - Configuration timeout override in seconds; zero inherits.
   * - ``options``
     - text
     - ``unset in TCA``
     - no
     - Additional adapter options; validate against the selected adapter.
   * - ``answer_cache_ttl``
     - number
     - ``0``
     - no
     - Opt-in answer-cache seconds, zero off; privacy and call-path restrictions apply.
   * - ``max_requests_per_day``
     - number
     - ``0``
     - no
     - Configuration daily request ceiling; zero unlimited.
   * - ``max_tokens_per_day``
     - number
     - ``0``
     - no
     - Configuration daily token ceiling; zero unlimited.
   * - ``max_cost_per_day``
     - number
     - ``0``
     - no
     - Configuration daily estimated-cost ceiling; zero unlimited.
   * - ``fallback_chain``
     - text
     - ``empty``
     - no
     - Ordered alternative configuration identifiers for eligible failures.
   * - ``is_active``
     - check
     - ``1``
     - no
     - Whether the record is eligible for runtime use.
   * - ``is_default``
     - check
     - ``0``
     - no
     - Installation default configuration, or default model within its provider.
   * - ``allowed_groups``
     - select
     - ``unset in TCA``
     - no
     - Backend-group access restriction; empty leaves it unrestricted.
   * - ``allowed_tool_groups``
     - select
     - ``empty``
     - no
     - Per-configuration group policy; intersected with global and actor gates.
   * - ``excluded_tools``
     - select
     - ``empty``
     - no
     - Explicit per-configuration exclusions, including remote tools.
   * - ``allowed_guardrails``
     - select
     - ``empty``
     - no
     - Per-configuration guardrail selection; does not bypass mandatory policy.
   * - ``require_second_approver``
     - check
     - ``0``
     - no
     - Require another eligible user to approve the initiating user's write.
   * - ``snippet_tags``
     - select
     - ``empty``
     - no
     - Compose active snippets with selected tags; admin-only field.
   * - ``snippets_in_caller_system_message``
     - check
     - ``0``
     - no
     - Also compose snippets into an explicit caller system message.
   * - ``skills``
     - select
     - ``unset in TCA``
     - no
     - Always-loaded skill attachments; version approval and trust gates still apply.
   * - ``skills_on_demand``
     - select
     - ``unset in TCA``
     - no
     - On-demand attachments offered for explicit loading, not eager injection.

``preset_checksum`` and ``tstamp`` are passthrough metadata, not editable form
settings. See :ref:`configuration-parameters` for cache eligibility, prompt
composition and fallback details, and :ref:`administration-permissions` for
actor grants.

.. toctree::
   :maxdepth: 1

   ConfigParameters
