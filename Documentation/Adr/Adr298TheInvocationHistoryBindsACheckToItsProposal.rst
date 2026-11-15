.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-298:

==========================================================
ADR-298: The invocation history binds a check to its proposal
==========================================================

:Status: Accepted
:Date: 2026-11-15
:Amends: :ref:`ADR-216 <adr-216>` (a history entry may carry a keyed digest
   of the call's arguments and the time of its outcome)
:Authors: Netresearch DTT GmbH

Context
=======

A tool invocation rule sees the earlier calls of its run through
``ToolInvocationHistory`` (:ref:`ADR-216 <adr-216>`): tool, outcome and target
per entry. That is enough for "a read ran before the write". It is not enough
for "the check ran on exactly this proposal and the data has not changed
since": the history does not say with which arguments a call ran, nor when.
A model can check one variant and write another, or check, wait while an
editor changes the record, and write; a rule cannot tell either from a
correct sequence.

The history is persisted with a suspended run and read back on resume, and
``ToolInvocationHistory`` is ``@api``. ADR-216 item 7 forbids copying
arguments into another persistent store.

Decision
========

**An entry may carry** ``arguments`` **and** ``at``. ``arguments`` is
``hmac-sha256:`` followed by the hex HMAC-SHA256 of the call's canonical
arguments, keyed with the installation's encryption key
(``SYS/encryptionKey``) joined with a purpose string of nr-llm and computed
with PHP's ``hash_hmac()``; TYPO3 13.4's ``HashService`` offers SHA-1 only.
``at`` is the Unix time at
which the runtime recorded the outcome. The runtime fills both for every
entry it appends for a call it decided or executed.

**Canonical arguments** are the JSON encoding of the argument array with the
keys of every object sorted, lists kept in order, slashes and Unicode not
escaped, and a float keeping its fraction (``1.0`` is not ``1``). JSON keeps
an integer and a numeric string apart, so ``{"uid": 42}`` and
``{"uid": "42"}`` have different digests. An argument array that cannot be
encoded gets no digest.

**The digest is keyed, not a plain hash**, so a short value (a page uid, a
title) cannot be recovered from a stored state by hashing guesses, and a
digest from one installation means nothing in another. It is not a copy of
the arguments in the sense of ADR-216 item 7.

**The stored format stays compatible.** Both keys are optional. An entry
stored before this change, or by a runtime that does not set them, is read as
before and has neither; a rule that needs a digest treats a missing one as
not matching and refuses. An entry with a malformed digest or time makes the
history unreadable, as a malformed entry does today (it is read as
incomplete). A runtime before this change ignores both keys.

**The public surface grows additively.** ``ToolInvocationHistory::append()``
gains two optional trailing parameters, and a class computes the digest so
that a rule computes it for the call in front of it the same way.

**The first rule that uses it** belongs to the A2A page metadata skill: a
write of the skill must be preceded by a successful check of exactly its
arguments, and the record must not have changed since that check.

Consequences
============

- ●● A rule can require that a check ran on the very proposal being written
  and that the data is no newer than the check. Checking one variant and
  writing another, and writing after the record changed, are refused before
  the approval.
- ● Old suspended states keep resuming; their entries have no digest, so such
  a binding rule refuses until the model checks again.
- ◑ ``at`` is the runtime's clock in seconds; a change in the same second as
  the check is not seen as later.
- ◑ Rotating the encryption key invalidates the digests of runs that wait, so
  a binding rule asks for a new check. That is the conservative direction.
- ✕ Every appended entry computes one HMAC over its arguments.

Net Score: +4

Alternatives considered
=======================

**Store the arguments.** Rejected by ADR-216 item 7, and a suspended state is
not the place for page content.

**A plain SHA-256.** Rejected: the values of a metadata call are short and
guessable, so a plain hash of them is close to a copy.

**Bind through the target only.** Rejected: a target names the record, not
the values; two variants for the same record share it.
