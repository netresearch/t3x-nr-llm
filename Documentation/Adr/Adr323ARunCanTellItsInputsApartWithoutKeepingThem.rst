.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-323:

=========================================================================
ADR-323: A run can tell its inputs apart without keeping them
=========================================================================

:Status: Accepted
:Date: 2026-11-18
:Authors: Netresearch DTT GmbH

Context
=======

A run records which skill versions each model call carried
(:ref:`ADR-321 <adr-321>`), the evidence that applied to them
(:ref:`ADR-322 <adr-322>`) and which model answered
(:ref:`ADR-320 <adr-320>`). The third cause of a changed answer, a changed
input, was not recorded at the default privacy level. ``metadata`` keeps
counts, lengths and names of a step and drops its content
(:php:`RunStepPrivacyFilter`), so two runs whose prompts, tool arguments or
tool results differ only in their text, at equal length, looked the same.

The completion request identity of :ref:`ADR-271 <adr-271>` did not fill the
gap:

- :php:`CompletionRequestIdentity::fingerprint()` returned no identity for a
  message list holding a tool call or provider-native items, which is every
  request of an agent run after its first tool round.
- The tool-call terminal attached no request identity at all.
- The identity covers the whole request, so it cannot say which part
  changed, and it is an unkeyed SHA-256: stored with a run, it would let a
  reader confirm a guessed request without any key.

Decision
========

1. **Keyed digests of the dropped parts, opt-in.** With the new setting
   ``privacy.inputDigests`` (off by default), a step filtered at the
   ``metadata`` level keeps a digest of these parts of the content it drops:
   ``messagesSentDigests`` (one per message, with its role, in order),
   ``contentDigest`` (the answer), ``toolArgumentsDigest``,
   ``toolResultDigest`` and ``requestDigest`` (item 3). A digest is
   ``v1:`` and the HMAC-SHA-256 of the canonical form of
   :ref:`ADR-298 <adr-298>`, keyed with the installation's encryption key
   joined with the purpose ``nr_llm.run.content``
   (:php:`RunContentDigests`, :php:`KeyedDigest`). The key is never stored
   with the digests; an installation without an encryption key stores none.
2. **The same input gives the same digest.** A message is digested without
   the ids a provider generates (tool-call ids and the ``tool_call_id`` of a
   tool result) and without provider-owned items, and tool arguments are
   digested decoded, so the order of their keys does not count.
3. **The request identity is widened and reaches a run only keyed.**
   :php:`CompletionRequestIdentity::fingerprint()` now covers requests with
   tool calls or provider items, the requests that had no identity before.
   For them it follows item 2: a tool-call id and the ``tool_call_id`` of
   the result answering it become the call's ordinal in the request, which
   keeps which result answers which call; tool arguments are taken decoded;
   provider items are left out. Every request that had an identity before
   keeps exactly its bytes, so ADR-271's benchmark identity is unchanged.
   The renumbering applies to :php:`ChatMessage` objects, which is how the
   extension builds every request; a caller passing raw message arrays with
   tool calls keeps their ids, and so gets an identity per run. The
   tool-call terminal attaches the identity of the request it sent, tool
   schemas included and the extension's own ``_``-prefixed directives
   (``_capture_raw``) left out, because they are never sent
   (:php:`LlmServiceManager::chatWithToolsForConfiguration()`). The answer
   step carries it as ``requestIdentity``; the privacy filter never stores it
   as it is, at any level, ``full`` included, and at ``metadata`` with the
   setting on stores its keyed digest (``requestDigest``).
4. **Only at the ``metadata`` level, and only of unmasked text.** ``none``
   keeps nothing. ``redacted`` keeps no digest: a digest of the unredacted
   text would confirm a credential the redactor masked. ``full`` stores the
   step's text as before and no digest. A step written
   at ``redacted`` and filtered again at ``metadata`` (a provider's stricter
   ceiling, ADR-270) gets no digest either: its text is masked and
   truncated, and digests of it would make different inputs equal. The
   filter marks such a step (``contentMasked``). A step filtered again at a
   stricter level loses its digests; switching the setting off removes no
   digest already stored, which goes with retention or a later tightening.
5. **Readable.** The run timeline shows each digest by its first twelve hex
   digits, messages as ``role@digest``.

Two departures from the draft that preceded this record: the draft also
digested at ``redacted`` (item 4 explains why not), and it recommended
against widening the request identity; it is widened, without what a
provider generates per run, and only the keyed digest of it is stored with
a run (item 3).

Consequences
============

●● With the setting on, two runs whose inputs differ show different digests
for the part that differs, at the default level, without keeping the text
(acceptance case 5 of the run-lineage analysis).

● The request identity is defined for tool rounds, so a later evaluation of
tool-using requests can be identified by the same function.

◑ Off by default: an installation that does not opt in keeps the record it
had, except that a step stored at ``redacted`` carries the ``contentMasked``
mark, which the timeline does not show. The step of a model call carries
``requestIdentity`` in memory and in the live playground, which shows the
full content anyway.

✕ With the setting on, anyone who can read a run and guesses a short or
predictable input (a yes or no, a record uid, a templated prompt) can confirm
the guess, because the key is the installation's. The setting's label and the
data-retention page say so.

◑ Provider items are left out of the identity and the digests, so two
requests that differ only in them (a provider's replay of its own earlier
reasoning) look the same.

◑ A tool whose result carries a fresh random value differs on every run:
``fetch_external_url`` fences the page with a per-call nonce, so its
``toolResultDigest`` and the digest of the message that carries it differ
for the same page.

◑ A step first stored at ``full`` and held to ``metadata`` later (a
provider's ceiling, a tightened run) has message, answer and tool digests
but no ``requestDigest``: ``full`` does not store the identity it would be
computed from. Thinking, requested tool calls, tool artifacts and raw
provider bodies get no digest.

◑ A step stored at ``redacted`` before this change has no ``contentMasked``
mark. If the setting is on and the run's level is later tightened to
``metadata``, it gets digests of its masked text.

◑ The unkeyed identity is on the response object of a tool round as it is on
a chat response (ADR-271), so a caller's idempotency cache
(:php:`IdempotencyMiddleware`) holds it with the response, as it holds the
answer.

◑ Digests compare within one installation only.
