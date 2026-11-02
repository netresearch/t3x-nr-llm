.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-241:

====================================================================
ADR-241: A reasoning model gets room to think
====================================================================

:Status: Accepted
:Date: 2026-11-02
:Authors: Netresearch DTT GmbH

Context
=======

A reasoning model counts its thinking against the output limit of the call. The
limit in nr-llm was written for answers: 1000 on a new configuration, 100 in the
alternative text preset, 60 for an inline suggestion in the cowriter, 10 for a
language detection.

Measured on 2026-11-02 with three real calls per case: at a limit of 10,
``gpt-6-luna`` returned no text, finish reason ``length``, in two calls and an
error in the third. At 100 with an image, ``gpt-6-luna`` returned no text in
three of three calls and ``gpt-6-sol`` in one of three, having spent all 100 on
thinking. A text job of nr-repurpose failed the same way at 1000 on ``gpt-6-sol``,
needing about 1050.

The failure was far from its cause. The language detection fell back to English
without a word. A structured call reported a JSON syntax error on an empty string.
Two consumers had already worked around it, each with a number of its own: the
cowriter's field overview with 2048 and the repurpose text pipeline with 16000.

Decision
========

**The output limit sent to a reasoning model of OpenAI is at least 16,000.**
:php:`OpenAiProvider` sends the larger of the requested limit and
``OpenAiModelProfiles::REASONING_OUTPUT_FLOOR`` on every path: completion, tool round on either
transport, vision and stream. A call that switches thinking off with an effort of
``none`` keeps its limit. So does a model that does not think. It is the
provider's correction, because the provider is what knows that this model
counts thinking against this parameter; a caller asks for the length of an answer.

The price is that the limit no longer bounds such a model's answer. A
configuration with 1000 can produce a longer text on these models, and an inline
suggestion is bounded by its prompt and its stop sequence. What is not used is not
paid, and a budget in tokens or cost still applies to what is.

**An empty answer that hit the limit is an error.** :php:`EmptyAnswerMiddleware`
throws :php:`OutputBudgetExhaustedException` for a response with no text, no tool
call and finish reason ``length``, naming the configuration and the limit. This
covers what the floor does not: a limit above the floor that thinking still used
up, and the other providers, whose thinking was not measured here. It runs inside
the cache layers, so nothing empty is stored, and above the fallback chain,
because another configuration is no answer to a limit a person has to raise.
Telemetry counts the call as a success, so the health score of a provider that
answered does not fall. A truncated text is still an answer and passes as before.

The exception is a :php:`ProviderException`, so a caller that handles a provider
failure handles this one. Three places keep what an empty answer did: a language
detection falls back to English, a translation revision keeps the first
translation, and the closing text of a run at its round limit stays empty, so the
run ends at the limit rather than failed. The task module shows the message.

The upper end of a cost estimate uses the same floor, through
:php:`OutputCeiling`: what the provider may spend is what it is quoted.

Consequences
============

- ●● A language detection, an alternative text and a short suggestion return text
  on a reasoning model.
- ● A consumer reads one typed reason instead of a JSON syntax error.
- ◑ ``Max Tokens`` no longer bounds the answer of a reasoning model below 16,000,
  and the upper end of a cost estimate rises with it.
- ◑ The default of 1000 on a new configuration stays. It is a sensible limit for
  a model that does not think, and changing it would change stored expectations
  for no gain on the others.
- ◑ Vision reports no finish reason, so an empty description on another provider
  stays empty.
- ◑ Gemini, OpenRouter and Ollama get the typed error and no floor: their
  behaviour was not measured.

Net Score: +3

Alternatives considered
=======================

**Adding a fixed headroom to each limit.** Rejected: a limit near the model's
maximum would be refused by the API, and the provider does not know that maximum.

**Raising each small limit at its call site.** Rejected: that is what the two
consumers did, and the next preset repeats it.

**Lowering the effort for a small limit.** Rejected: an effort is a choice of the
configuration, and a call cannot tell whether the model needs the thinking.
