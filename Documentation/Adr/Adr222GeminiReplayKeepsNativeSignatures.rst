.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-222:

======================================================
ADR-222: Gemini replay keeps native thought signatures
======================================================

:Status: Accepted (Struct roots and private correlation amended)
:Amended: 2026-10-10 by :ref:`ADR-224 <adr-224>` and :ref:`ADR-226 <adr-226>`
:Date: 2026-10-10
:Authors: Netresearch DTT GmbH
:Amends: :ref:`ADR-203 <adr-203>` (provider ownership of opaque replay items)

Context
=======

Two real adapter request-serialization counterexamples lose Gemini signatures
between a tool response and its result submission. Google's
`Generate Content signature contract
<https://ai.google.dev/gemini-api/docs/generate-content/thought-signatures>`_
requires signatures on their original function-call parts. This evidence is a
controlled HTTP contract check, not a live Gemini acceptance test.

ADR203 already carries opaque provider state through response metadata,
ChatMessage and stored transcripts. OpenAI Responses currently replays that
state unconditionally, so adding Gemini parts without identifying their owner
would send incompatible native content during a provider fallback.

Decision
========

Use the existing replay carrier with an explicit Gemini-owned item whose type
is ``nrllm_gemini_generate_content`` and whose ``parts`` contain the original
native array-valued parts in their original order. Preserve signatures and
part boundaries without decoding or rebuilding them. Signed ordinary chat
responses use the same carrier; unsigned ordinary chat keeps its metadata
behavior. Public signatures and the generic ToolCall representation stay.

Gemini unwraps its own carrier when converting an assistant turn. OpenAI
Responses rebuilds a Gemini-owned turn from its ordinary tool calls/content;
Gemini likewise rebuilds foreign OpenAI turns. Untagged historical OpenAI
items retain their existing exact replay behavior. The internal wrapper
reaches neither provider, and ordinary wire arrays keep omitting replay state.

Exactly one Gemini capsule may occupy a replay turn. A malformed, duplicate
or mixed recognized capsule fails with an existing typed provider exception
before contact in either consuming adapter rather than losing signatures
silently. Foreign reconstruction retains both visible text and tool calls.
The stored transcript and real tool-loop/resume path keep carrying the state
without changing approval, budget, actor or retention contracts.

Consequences
============

Gemini native context survives successive tool steps and stored resumes. The
explicit ownership boundary prevents foreign replay during provider changes;
it cannot make another provider or an incompatible model consume Gemini state.
Replay contains native parts rather than an optional full transport snapshot.
No new public field, database schema or external dependency is required.

Single, parallel and sequential serialized requests, actual persisted resume,
cross-provider payloads and malformed-carrier refusal are required oracles.
Selected mutations must fail assertions rather than framework setup. Specs and
implementation are separate PRs with an actual dependency branch.

New streamed-response replay state, the Interactions API and other Gemini
parsing/capability changes remain separate work.

Clarification on 2026-10-10
===========================

:ref:`ADR-224 <adr-224>` qualifies final transport encoding of the API-known
``FunctionCall.args`` and ``FunctionResponse.response`` JSON-object roots.
Native order, signatures and stored carrier arrays retain this decision's
contract. Neither record promises restoration of arbitrary nested object/list
kinds after associative decoding has lost that information; that finding
remains open.

:ref:`ADR-226 <adr-226>` adds optional private ``tool_call_bindings`` to the
owned capsule so a populated native function-call ID reaches its result without
replacing public synthesized IDs. Native parts remain unchanged. Older state
without bindings is accepted only where populated ID correlation is
unambiguous; position alone cannot establish an association for identical
parallel calls. This amendment changes no public signature or database schema.
