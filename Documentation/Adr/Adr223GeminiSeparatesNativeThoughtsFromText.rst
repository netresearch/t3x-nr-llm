.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-223:

===================================================
ADR-223: Gemini separates native thoughts from text
===================================================

:Status: Accepted
:Date: 2026-10-10
:Authors: Netresearch DTT GmbH
:Amends: :ref:`ADR-016 <adr-016>` (Gemini candidate text and native thinking)

Context
=======

Controlled HTTP responses through both Gemini chat methods demonstrate that
plain chat drops later visible parts and tool chat includes thought-marked
parts in its visible answer. Native thoughts never reach the existing thinking
field. Google's `Generate Content Part contract
<https://ai.google.dev/api/generate-content?hl=en#Part>`_ provides an explicit
boolean marker. The counterexamples do not claim live provider acceptance.

ADR016 already separates native thinking from visible text and supports inline
thinking tags. Its Gemini integration describes only the first text part. The
repair needs one interpretation shared by plain chat and tool chat while
keeping the generic response and opaque replay contracts separate.

Decision
========

Parse the first candidate's text parts in order through one internal helper.
String text with a strictly boolean ``thought: true`` marker belongs to native
thinking; all other string text belongs to visible output. Non-text values
retain the existing defensive handling. Concatenate each sequence without
inserting separators. Visible text then passes once through the existing
``<think>`` extractor, including tags split between visible parts.

Native thinking is retained verbatim, including literal tags and whitespace.
An empty native sequence contributes nothing. When native and extracted inline
thinking both exist, the existing thinking field receives native text, one LF,
then normalized inline thinking. Either contribution alone stays as it is.
With neither contribution, thinking is ``null``. Only the cleaned visible
sequence becomes content.

Keep usage, model, provider, finish reason, metadata and tool calls unchanged.
Do not mutate native parts or opaque replay signatures. This decision does not
change the public response or ToolCall surface, stream parsing, vision parsing,
the Interactions API or response retention and logging.

Consequences
============

Both synchronous chat methods retain all visible text and expose native
thought summaries through the field already used for thinking. The explicit
combination rule keeps inline extraction compatible and testable. Consumers
that previously saw leaked native thought text now receive the visible answer.

Actual adapter-response tests cover ordered parts, native and inline mixtures,
empty and malformed controls, preserved response fields and unchanged replay
parts. Selected faults must fail assertions. Decision and implementation are
separate PRs with the exact signed decision commit as the implementation base.
