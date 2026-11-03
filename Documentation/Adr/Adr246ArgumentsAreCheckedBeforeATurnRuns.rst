.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-246:

=============================================================
ADR-246: Arguments are checked before a turn runs
=============================================================

:Status: Accepted
:Date: 2026-11-03
:Authors: Netresearch DTT GmbH

Context
=======

A model's tool call reached :php:`ToolInterface::execute()` with whatever
arguments the model sent. Each tool checks its own: most writing tools answer
a bad value with a targeted ``Refused: "uid" must be …``. Where a tool did
not, the call threw and the model was told only ``Error: tool "x" failed.``,
which says nothing it can correct. Three paths ran before the tool and did
not look at the arguments at all: the approval card of an approval-bound tool
(an editor could be asked to approve a call that then failed), the question
of an input-bound tool, and the budget of external (MCP) tool calls, which a
remote server's refusal still consumed.

What the demo installation recorded up to 2026-11-03 (Remote Diagnostics,
all agent runs): 2,386 tool calls, 97 of them failed (4.1 %), all in writing
tools (``update_page_metadata`` 58, ``create_content_element_draft`` 13,
``update_content_element`` 11, the rest below 6). The installation stores run
steps at the metadata-only privacy level, so the failure texts are not kept
and how many of the 97 were argument errors cannot be read from the data.
That is a bound, not a count.

:php:`JsonSchemaValidator` exists (ADR-082, ADR-126), but it answers yes or no
and its strict mode fails closed on any keyword outside its subset, which
server-supplied schemas use freely.

Decision
========

**The loop checks every call of a turn against the schema its tool was
offered with, before any call of the turn runs.**
:php:`ToolArgumentChecker` names each mismatch in one sentence, at most ten:

- a missing required argument, or null for one whose schema declares types
  without ``null``;
- a value of the wrong kind: a list or an object where text, a number or a
  boolean is declared, an object where a list is declared, text where an
  object is declared;
- a value outside an ``enum``;
- an argument the schema excludes with ``additionalProperties: false``.

It recurses into ``properties`` and ``items``.

**What a tool commonly reads after a cast passes.** The text ``"5"`` and the
float ``5.0`` for an integer, a number for text, ``"true"``, ``1`` and ``0``
for a boolean, null for an optional argument and for a required one whose
schema admits it (a nullable MCP parameter), ``2`` for an enum of ``"2"``, and
an enum value in another letter case. A list passes where an object is
declared, because a JSON object keyed ``0``, ``1``, ``2`` (a map keyed by
language uid) decodes to a PHP list and cannot be told apart from one. Each of these may run today; refusing it would cost a round instead
of saving one.

**What the check cannot judge it does not refuse.** A schema node with
``$ref``, ``oneOf``, ``anyOf``, ``allOf``, ``not`` or ``if`` is skipped, as is
an unknown ``type`` name. MCP servers send such schemas.

**A turn with a mismatch runs nothing.** The mismatched call is answered
``Refused: the arguments do not match the schema of tool "x", so nothing was
run.`` with the list; every other call of the turn is answered ``Not run:``
and asked to be sent again with the corrected one. No tool runs, no approval
card or question is raised, no external call budget is charged, and the
invocation history the invocation rules read is not appended to. The pauses
for approval and input already hold a whole turn, and answering part of one
would leave a pending call beside a refused sibling.

**The refusal is recorded as a code.** The tool step carries ``toolRefusal``
(``arguments`` or ``turn``), which the metadata-only privacy level keeps and
the run timeline lists, so the effect can be counted where the text is not
stored.

Alternatives
============

**Run the fitting calls of the turn and refuse only the mismatched one.**
Saves the model re-sending a read. Rejected because an approval-bound or
input-bound call beside a refused one would pause the turn with a call
pending that the refused sibling may depend on; the consistency rule the
pauses follow is one turn, one decision.

**Use** :php:`JsonSchemaValidator::validateStrict()`. Rejected: it answers
only yes or no, and it fails every schema outside its subset, which would
refuse every call of an MCP tool whose schema uses ``$ref``.

**Coerce the arguments to the declared types before execution.** Rejected:
changing what a tool receives is a change to every tool's contract, and the
form-input coercion (:php:`SchemaInputCoercer`) exists for posted strings, not
for a model's JSON.

Consequences
============

- ●● One bad argument costs one corrective round with a message the model can
  act on, instead of a failed call or a generic "failed".
- ● An editor is never asked to approve a call whose arguments do not fit.
- ● A refused external call does not spend the run's external call budget.
- ◐ A fitting read beside a refused call is sent again in the next round.
- ◑ A tool whose declared schema is stricter than its code (a ``required``
  argument it actually defaults) now refuses calls it used to accept. The 42
  built-in tools that declare ``required`` were read for this; each refuses a
  missing required argument itself.

Net Score: +5
