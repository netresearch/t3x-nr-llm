.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-296:

==============================================================
ADR-296: An A2A skill binds its run to the scope of its request
==============================================================

:Status: Accepted
:Date: 2026-11-15
:Amends: :ref:`ADR-295 <adr-295>` (the A2A server is the first starter that
   sets a scope)
:Authors: Netresearch DTT GmbH

Context
=======

The A2A server publishes LLM configurations as skills
(:ref:`ADR-278 <adr-278>`). Its one domain handler, ``nr_llm.text``, passes
the client's text to the run as the user message. A writing profile writes
drafts only, into the client's pinned workspace (:ref:`ADR-283 <adr-283>`),
but inside that workspace the run may change anything its technical backend
user may change. Whatever the client's text asks for is only an instruction
to the model.

The scope of an assignment (:ref:`ADR-295 <adr-295>`) can hold a run to the
records it was asked to work on, and it survives the queue, a pause and a
resume. ``AiActorContext::withAssignmentScope()`` existed, but no starter
called it: the backend chat and the A2A server started every run without a
scope.

A skill that is to be offered as a defined task, "write the metadata of this
page in this language as a draft", needs three things the text handler does
not have: a request whose values the server can check, a scope derived from
those values, and a refusal when the values are wrong.

The A2A 1.0 ``AgentSkill`` message (the pinned proto, spec 051) has no field
for an input schema: id, name, description, tags, examples, input and output
modes, security requirements. The server accepts ``text/plain`` parts only
until stage 5c.

Decision
========

**A domain handler may derive a scope from the request and bind the run to
it.** The handler ``nr_llm.page_metadata.v1`` is the first. Its request,
version 1, is one JSON object in the text part:
``{"page": 42, "language": 1, "fields": ["description"]}``. Exactly these
keys; ``page`` and ``language`` are JSON integers, ``fields`` a non-empty list
of distinct names from the twelve text fields ``update_page_metadata`` writes.
The handler finds the row to write: the page for language 0, its translation
otherwise, as the client's workspace shows them. It binds the run to the page
(depth 0), the table ``pages`` and the language. The run's user message is
built from the checked values only, so no text of the client reaches the
model.

**A request that does not fit is refused before a task exists**, with JSON-RPC
``-32602`` InvalidParams on ``message.parts`` and a description that names the
rule broken, never a value the client sent. This covers an unknown key, a
missing key, a wrong type, a page that is not a default-language page and a
page with no translation in the language. Version 1 does not create a
translation.

**The run may use** ``get_page_content`` **and** ``update_page_metadata``,
**and** ``ask_choice`` **when the profile grants it.** Any other tool the
profile lists is not offered to this run.

**The skill runs only where the installation is set up for it.** The profile
must be writing and grant both tools, and the client must be pinned to a draft
workspace. The LLM configuration must require a draft workspace
(:ref:`ADR-293 <adr-293>`) and the scope of an assignment
(:ref:`ADR-295 <adr-295>`), so that the same configuration reached through
another profile cannot write without them. Otherwise the request is refused
with ``-32004`` UnsupportedOperation, "This skill is not available.", and the
reason is logged.

**The contract version is in the handler identifier.** A changed contract is
a new handler (``…v2``); a profile keeps the one it names. The agent card
states the contract only through the profile's description and examples, which
the administrator writes, because the proto has no schema field.

**No second approver for this skill in version 1.** A write of the run is
approved in the backend like any A2A write; a configuration that requires a
second approver applies as it does to every A2A run.

Consequences
============

- ●● A client cannot widen what the run writes, through its text or through
  the model: the scope is fixed from checked values before the task exists,
  and every later write, also after a question and the client's answer, is
  checked against it by the rule and the DataHandler hook of ADR-295.
- ● A wrong request costs no task, no run and no capacity, and the client
  learns which rule it broke.
- ◑ The contract is documented, not machine-readable on the agent card. A
  client reads the profile's description and examples.
- ◑ The refusal is a JSON-RPC error, not a REJECTED task: spec 051 reserves
  REJECTED for a guardrail refusal, and the REJECTED status text is fixed.
- ✕ A page without a translation in the language cannot be served by
  version 1.

Net Score: +4

Alternatives considered
=======================

**A JSON data part.** Rejected for version 1: the server accepts text parts
only, and changing that is stage 5c's work in the same method. A later
contract version can accept a data part once 5c is merged.

**A REJECTED task with a reason.** Rejected: the reason would need a new
status text path in the task store, and a task that never ran costs a dedup
row and a slot of the client's quota.

**Scope from the profile record.** Rejected: a profile serves many pages, and
the scope of one task is what the client asked for, checked by the server.

**Create a missing translation.** Rejected for version 1: it widens the run to
a second write tool and a second kind of approval; a later version may add it.
