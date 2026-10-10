.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-226:

===================================================
ADR-226: Gemini correlates native function-call IDs
===================================================

:Status: Accepted
:Date: 2026-10-10
:Authors: Netresearch DTT GmbH
:Amends: :ref:`ADR-222 <adr-222>` (private native-call correlation metadata)

Context
=======

Google's `Generate Content contract
<https://ai.google.dev/api/generate-content?hl=en>`_ defines an optional
``FunctionCall.id`` and requires its matching ``FunctionResponse.id`` when
populated. Controlled actual response-to-request probes retain the native call
ID on replay but omit it from the result: three assertion failures, two ID-less
positive controls, zero framework errors. This is no live API acceptance claim.

The public adapter synthesizes conversation-unique ToolCall IDs. A positional
join to native parts cannot preserve association when two identical parallel
calls have different native IDs and the generic list is reordered. Rejecting
those fresh parallel calls would reject a supported response.

Decision
========

Keep public synthesized ToolCall IDs and capture their association with
populated native IDs at the actual parser. Extend only the existing owned
opaque capsule with optional ``tool_call_bindings`` entries containing
``tool_call_id`` and ``native_id``. Preserve native parts. Validate the owning
turn, one-to-one IDs, complete coverage and matching decoded call
names/arguments before consuming the association. The existing private
transcript prescan can carry name and native ID together; an additional public
scanner is unnecessary.

Echo only that native ID in the matching Gemini result. An absent native ID
stays absent; a present ID on an accepted named call must be a nonempty string.
Malformed or duplicate native IDs in the tool parser or a correlated replay
turn are refused with an existing typed provider exception. IDs may recur in
later turns without replacing the conversation-unique public IDs. A public ID
involved in native association must not identify another call in the
transcript.

Older capsules without bindings may recover only an unambiguous name/decoded
argument association. Never infer by position; identical parallel signatures
with populated native IDs must fail before contact. Entirely ID-less and
unambiguous older owned turns remain compatible. Native-parts-only and wholly
ID-less replay adds no generic call-list requirement; plain signed capture does
not manufacture generic calls. Always structurally validate a present binding
field: those compatible turns permit only a valid empty list. A nonempty or
malformed field is refused with a typed exception before contact. Extra
association checks concern owned generic turns requiring native ID correlation.
Foreign/generic turns provide no native binding, and other provider payloads
contain no capsule or binding.

Use the same association on closing and streaming requests carrying existing
state. Real encrypted SQL suspension/resume must preserve captured bindings,
signatures, known Struct objects and approval identity. No public field,
signature or schema change is introduced. Correlation does not establish trust,
authentication or authorization, and validation must not expose native content.

Consequences
============

Fresh same-name/same-argument parallel calls can be answered unambiguously even
when their generic list is reordered. Ambiguous older state cannot be repaired
by guessing and is refused. Current decoded equality does not restore nested
JSON kinds lost earlier; that finding remains open.

Actual HTTP wire and durable SQLite/MariaDB oracles, selected assertion faults,
independent whole-diff review and complete gates are required. The separate
decision depends on the published Struct implementation; its implementation
must start from the published decision. Native text extraction, new streamed
capture and live availability remain separate.
