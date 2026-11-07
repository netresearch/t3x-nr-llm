.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _administration-skills:

===============
Managing skills
===============

A skill supplies instructions and metadata for an AI task or run. Import
``SKILL.md`` files from a single GitHub file, repository or marketplace, or
write a local skill in the backend. Review the version before approving and
attaching it to a configuration or task. Enabled and approved are separate
states: enabling a skill does not approve arbitrary future edits.

The YAML metadata starts with a line containing only ``---`` and ends with
another standalone ``---`` line, including at the end of the file.
The closing line may have trailing spaces or tabs.
A metadata key that starts with dashes does not close the block.
The remaining Markdown, including later separator lines, forms the skill body.

The :guilabel:`AI > Authoring > Skills` module, source synchronization and
approval controls are admin-only. Local record authoring can be delegated
through TYPO3 table and field permissions; that does not grant synchronization,
approval or administrative module access. See the local-authoring section
below before delegating those records.

.. note::

   Ingest — adding sources, syncing and reviewing — is described by
   :ref:`ADR-035 <adr-035>`. Attaching enabled skills to tasks and
   configurations and injecting them into text-generation prompts is
   described by :ref:`ADR-036 <adr-036>` and the
   :ref:`Attaching skills <administration-skills-attach>` section below.

Choose the operation you need. Source synchronization, local authoring,
version approval and prompt attachment have different permissions and effects.
A source fingerprint is not a publisher signature, and approval does not
remove tool authorization or write approvals.


.. toctree::
   :maxdepth: 1

   SkillSources
   SkillAuthoring
   SkillApproval
   SkillUse
   SkillTrust
