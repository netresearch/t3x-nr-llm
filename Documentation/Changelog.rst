.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _changelog:

=========
Changelog
=========

This page summarizes the latest release. The development branch also includes
unreleased changes: inspect the `pending changelog fragments
<https://github.com/netresearch/t3x-nr-llm/tree/main/changelog.d>`__ before
assuming a behavior is available in a tagged package.

The `complete changelog
<https://github.com/netresearch/t3x-nr-llm/blob/main/CHANGELOG.md>`__ is the
canonical release ledger. The manual's older highlights are selective and
may omit intermediate versions.

During 0.x, a minor release may introduce breaking changes; patch updates
remain within the minor series. Review migration notes before upgrading.

.. toctree::
   :maxdepth: 1

   Changelog/Index

.. _version-0-44-0:

Version 0.44.0 (2026-11-12)
===========================

A configuration can have its texts checked against a rule set by a second
model, a table task can send its rows as TOON, EXT:autotranslate can translate
through nr-llm, and an agent run stores what its model calls cost. nr-llm
now requires nr-vault 1.5. Run the TYPO3 database schema update after
updating.

- **Rule-set judge (ADR-288):** a configuration can have its texts checked
  against a rule set by a second, small model. The rule set is the snippets
  carrying the selected tags; mode ``report`` delivers the text with a hint
  that quotes the broken rule and the passage, mode ``block`` stops it. Off by
  default. ``tx_nrllm_configuration`` gains ``rule_judge_mode``,
  ``rule_judge_tags`` and ``rule_judge_configuration``.
- **Guardrail configurations (ADR-290):** a caller can name further
  configurations with ``withGuardrailConfigurations()``; their term lists
  screen the call as well, and the rule-set judge of each one applies, the
  strictest mode deciding. The backend AI chat uses it so that a site with its
  own configuration keeps the chat's bans.
- **TOON table input (ADR-286):** with ``"format":"toon"`` in a table task's
  ``input_source`` the rows go to the model in TOON instead of JSON; on the
  first 50 rows of five demo tables this needed 45 to 71 % fewer tokens.
- **EXT:autotranslate (ADR-289):** its ``translatorClass`` can name
  ``Netresearch\NrLlm\Integration\Autotranslate\AutotranslateTranslator``,
  which translates through the nr-llm translator in the setting
  ``autotranslate.translator`` and refuses a blank, cut-off or markup-only
  answer instead of storing it.
- **A2A client (spec 051, stage 1):** an administrator can register a remote
  agent that speaks Agent2Agent 1.0, import its card with
  ``nrllm:a2a:import``, and send it a message with ``nrllm:a2a:send``. Nothing
  is sent unless an agent is registered. Requests go through the address
  guard and are pinned to the checked addresses, which needs
  ``netresearch/nr-vault`` 1.5.
- **Run cost:** an agent run stores what its model calls cost, also across an
  approval or input pause. Before, every run stored a cost of 0; a run whose
  cost is unknown now shows "Unknown".
- **Failed approved writes:** a background batch offers a record again when its
  card was approved but the write failed, and the Agent Runs module marks a
  finished run whose tool call failed.
- **Head editors (ADR-277):** the new grant ``usage_view_groups`` and the group
  field *AI usage visible for groups* open the module *AI usage of my groups*.
  ``be_groups`` gains ``tx_nrllm_usage_visible_groups``.
- An approval or input in the Agent Runs inbox streams a line per step, so a
  reverse proxy's read timeout counts one step instead of the whole
  continuation (ADR-276).
- Workspace writers ask an inline owner as the run's workspace shows it, and
  approval cards name a content element without a header by its type and UID.
