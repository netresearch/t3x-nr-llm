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
   ``metadata`` level keeps a digest of each part of its content it drops:
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
   :php:`CompletionRequestIdentity::fingerprint()` now covers messages with
   tool calls (id, type, name and decoded arguments) and provider items (as
   the provider sent them). Every input that had an identity before keeps
   exactly its bytes, so ADR-271's benchmark identity is unchanged. The
   tool-call terminal attaches the identity of the request it sent, tool
   schemas included (:php:`LlmServiceManager::chatWithToolsForConfiguration()`).
   The answer step carries it as ``requestIdentity``; the privacy filter
   treats it as content and stores only its keyed digest
   (``requestDigest``).
4. **Only at the ``metadata`` level.** ``none`` keeps nothing. ``redacted``
   keeps no digest: a digest of the unredacted text would confirm a
   credential the redactor masked. ``full`` stores the step as it is, the
   text and the request identity with it. A step filtered again at a stricter
   level, or with the setting off, loses its digests (ADR-270's refiltering).
5. **Readable.** The run timeline shows each digest by its first twelve hex
   digits, messages as ``role@digest``.

Two departures from the draft that preceded this record: the draft also
digested at ``redacted`` (item 4 explains why not), and it recommended
against widening the request identity; it is widened, but only the keyed
digest of it is stored with a run (item 3).

Consequences
============

●● With the setting on, two runs whose inputs differ show different digests
for the part that differs, at the default level, without keeping the text
(acceptance case 5 of the run-lineage analysis).

● The request identity is defined for tool rounds, so a later evaluation of
tool-using requests can be identified by the same function.

◑ Off by default: an installation that does not opt in keeps the record it
had. The step of a model call carries ``requestIdentity`` in memory and in
the live playground, which shows the full content anyway.

✕ With the setting on, anyone who can read a run and guesses a short or
predictable input (a yes or no, a record uid, a templated prompt) can confirm
the guess, because the key is the installation's. The setting's label and the
data-retention page say so.

◑ A provider that changes the encoding of its own items changes the request
identity, and with it ``requestDigest``, though the input did not change;
message digests leave those items out and are not affected.

◑ Digests compare within one installation only.
