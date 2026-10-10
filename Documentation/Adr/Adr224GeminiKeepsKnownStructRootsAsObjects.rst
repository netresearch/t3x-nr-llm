.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-224:

========================================================
ADR-224: Gemini keeps known Struct roots as JSON objects
========================================================

:Status: Accepted
:Date: 2026-10-10
:Authors: Netresearch DTT GmbH
:Amends: :ref:`ADR-222 <adr-222>` (final known-root wire encoding)

Context
=======

Google's `Generate Content contract
<https://ai.google.dev/api/generate-content?hl=en>`_ defines
``FunctionCall.args`` and ``FunctionResponse.response`` as JSON objects.
Controlled actual adapter requests instead emit lists for empty and numeric-key
maps. Six clean additional cases fail assertions with no framework errors;
object-mode JSON decoding exposes the wire distinction. These are HTTP contract
fixtures, not a live Gemini acceptance test.

The inherited associative decoder also loses arbitrary nested object/list kinds.
A root repair cannot recover that information. That distinct audit finding
remains open and must receive its own preservation design and evidence.

Decision
========

Encode these two API-known roots as JSON objects when constructing the final
Gemini request, including empty and numeric-key maps. Preserve their member
values and current scalar/invalid result fallback. A decoded list result keeps
its members at numeric object keys in the known Struct root. Do not cast nested
arrays indiscriminately: actual nested lists must remain lists.

Apply the same rule to Gemini-owned native parts on replay, including already
carried turns on closing and streaming requests. Preserve native order,
signatures and every other member. Keep the carrier array-valued for the current
metadata, public transcript and encrypted SQL state; construct the wire copy
without rewriting that state. Existing foreign reconstruction and untagged
OpenAI replay retain their own behavior.

Public ``ToolCall`` arguments remain arrays. No public signature, carrier field,
schema, actor, approval, budget or retention change is introduced. ADR-222's
native replay promise is qualified at these known JSON-object boundaries;
arbitrary nested values retain their current decoded representation.

Consequences
============

Empty and numeric-key arguments/results satisfy their known wire contract.
Tests inspect actual serialized requests with object-mode JSON decoding and
literal positive controls, rather than an associative round-trip that masks
objects versus lists. Real persisted suspension/resume and selected assertion
faults are required; specs and implementation are separate dependent PRs.

This decision does not close nested JSON-kind preservation. It makes no generic
lossless transport, live API or new streamed-response capture claim. ADR-223's
visible/native thought extraction remains independent.
