.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

============================
Attaching and loading skills
============================

.. _administration-skills-support-status:

The ``partial`` support badge
=============================

Each skill carries a support badge:

``full``
   The skill is plain front-matter and prose.

``partial``
   The body or front-matter references scripts, ``references/``,
   ``assets/`` or an ``allowed-tools`` declaration.

.. warning::

   ``partial`` is **not** a "safer content" badge. It only signals that
   the referenced scripts and assets are **not executed** by nr-llm
   (which is true for every skill in this release). The prose itself is
   fully untrusted regardless of the badge. Asset references are stripped
   from injected prose purely to avoid dangling instructions, not as a
   security control.

See :ref:`ADR-035 <adr-035>` for the full design and security rationale.

.. _administration-skills-page:

Whether a skill works on a page
===============================

The frontmatter key ``page`` tells a consumer of the skill, such as the
backend chat of nr_mcp_agent, whether a conversation with the skill needs a
page:

``page: required``
   The skill works on one page and needs it. The chat asks for a page and
   keeps it.

``page: optional``
   The skill may work on a page: the page the editor shows is passed along
   when there is one.

``page: none``
   The skill does not work on a page. The chat neither asks for one nor
   passes one along.

.. code-block:: yaml

   ---
   name: Write a news article
   description: Drafts a news article from a few key points.
   page: none
   ---

A skill without the key, or with any other value, declares nothing and keeps
the behaviour it had before the key existed: a process skill behaves as
``required``, every other skill as ``optional``. The value is read without
regard to case.

The key is not part of the skill's version (:ref:`ADR-229 <adr-229>`). It
decides no trust and no tool, so a change of it alone neither disables an
enabled skill nor invalidates its approvals, and it is not shown in the
approval form's diff. A synced skill shows it read-only as
:guilabel:`Works on a page`; a skill written in the backend sets it there.

.. _administration-skills-attach:

Attaching skills and injecting them into prompts
================================================

Enabled, non-orphaned skills can be attached to a **Task** and/or an
**LLM configuration** via the :guilabel:`Skills` field on those records
(only enabled skills are offered). At execution time, for text-generation
operations only — completion, translation and task execution; **never**
embeddings, vision or speech — nr-llm composes unapproved attached skills into a
delimited block and prepends it to the *user* prompt. Approved instruction
versions use the system-message channel described below; the stored
configuration ``system_prompt`` is never modified.

A configuration has two skill fields:

- :guilabel:`Skills loaded on demand`. An agent run lists the attached skills
  whose current version is approved as an instruction (and which are not
  process skills) by uid, name and description in the description of the
  builtin tool ``load_skill``. When the task matches, the model loads one: its
  section joins the run's system message before the next call, the tool
  result confirms the load without the body, and loading the same version
  again appends nothing. The load re-checks the attachment, the record and
  the approval at that moment, and refuses a section that would leave less
  than 10 % of the model's context budget. A skill attached here that is not
  approved is neither listed nor composed. Tasks, translation and the other
  one-shot paths never load on demand. A process skill is attached here and
  is started by invocation only, for example from the chat's slash-command
  menu; the model never loads it.
- :guilabel:`Skills loaded always`. Composed into every run, as described
  below. Every attachment that existed before the load modes is one of these.
  Process skills cannot be attached here.

Both fields grant the skills' declared tools (``allowed-tools``) from the
start of a run; loading a skill grants nothing.

.. note::

   An attachment loaded always is **eager and complete**. The whole skill
   **body** — the entire ``SKILL.md`` prose after the front-matter, not just
   the ``name``/``description`` — is written into the prompt *before* the model
   runs. Nothing loads a skill's ``references/`` / ``scripts/`` / ``assets/``
   (those lines are stripped from ``partial`` skills, and the files are never
   executed). Such a skill therefore always costs its full body in tokens on
   every run (subject to the budget below).

Composition rules:

- **Precedence.** Configuration skills are the baseline, task skills are
  additive; the set is the union deduped by source + identifier (the
  configuration wins on a duplicate). The configuration block renders
  first.
- **Budget.** The untrusted user-message block is bounded by a byte budget;
  when it is exceeded, task-additive skills are dropped before
  configuration-baseline skills and each drop is logged. Approved instruction
  sections are kept in full and count toward the context-window estimate. A
  request that does not fit even then is still sent, with a logged warning:
  the chat path first drops older turns, the completion path sends the prompt
  unchanged. No instruction section is dropped to make it fit.
- **Integrity.** Each skill's version digest — over the body and the
  frontmatter fields the model reads — is recomputed from the stored fields at
  injection time; a mismatch (tampering or a stale row) drops that skill — it
  is never injected. A row synced before the digest existed is checked
  against its body checksum until the upgrade wizard or a sync writes its
  digest. Name, description and body are therefore read-only in FormEngine.
- **Process skills.** A skill whose frontmatter sets ``process: true`` is
  never composed from an attachment; it is skipped with a warning. A run that
  invokes it composes its approved version as a system-message section of its
  own, :guilabel:`Active process`, ahead of the approved skills. The process
  governs the order of steps and the structure of answers; the approved
  skills keep applying to wording, terminology and safety
  (:ref:`ADR-214 <adr-214-amendment-process-precedence>`).
- **Untrusted output.** Skill prose is third-party text; output produced
  under its influence is treated as untrusted and escaped/sanitized where
  it is stored or rendered. Message role is defense-in-depth, not a trust
  boundary.

See :ref:`ADR-036 <adr-036>` for the injection design.
