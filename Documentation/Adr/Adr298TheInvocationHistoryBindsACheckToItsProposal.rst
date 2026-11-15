.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-298:

=============================================================
ADR-298: The invocation history binds a check to its proposal
=============================================================

:Status: Accepted
:Date: 2026-11-15
:Amends: :ref:`ADR-216 <adr-216>` (a history entry may carry a keyed digest
   of the call's arguments and of the record state the call observed)
:Authors: Netresearch DTT GmbH

Context
=======

A tool invocation rule sees the earlier calls of its run through
``ToolInvocationHistory`` (:ref:`ADR-216 <adr-216>`): tool, outcome and target
per entry. That is enough for "a read ran before the write". It is not enough
for "the check ran on exactly this proposal, and the record is still what the
check saw": the history does not say with which arguments a call ran, nor what
it observed. A model can check one variant and write another, or check, wait
while an editor changes the record, and write; a rule cannot tell either from
a correct sequence.

Time does not close the second gap. A TYPO3 record's ``tstamp`` is the
``EXEC_TIME`` of the request or process that wrote it, its start and not the
moment of the write, so a writer that started before the check and wrote
after it leaves an older stamp than the check.

The history is persisted inside the sealed suspended state of a run
(:ref:`ADR-114 <adr-114>`), next to the pending calls, and
``ToolInvocationHistory`` is ``@api``.

Decision
========

**An entry may carry** ``arguments`` **and** ``state``. Both are digests:
``v1:`` followed by the hex HMAC-SHA256 of a canonical form, keyed with the
installation's encryption key (``SYS/encryptionKey``) joined with a purpose
string of nr-llm, the construction TYPO3's ``HashService`` uses. PHP's
``hash_hmac()`` computes it, because TYPO3 13.4's ``HashService`` offers
SHA-1 only. An empty encryption key gives no digest.

- ``arguments`` is the digest of the call's arguments. The runtime fills it
  for every entry of a call it decided or executed.
- ``state`` is the digest of the record state a tool reports it observed,
  through ``ToolResult::withObservedState()``. Only a tool that reads or
  checks a record reports one; the runtime copies it into the entry.

A rule compares a stored digest with one it computes now, for the call in
front of it and for the record as it is now. Equal means the same arguments
and the same observed state.

**Canonical form, version 1.** The JSON encoding of the value, where an
array that is not a list has its keys sorted as strings
(``ksort(SORT_STRING)``) and stays an object, and a list keeps its order.
Slashes and Unicode are not escaped, integers and numeric strings stay apart,
floats are written with ``serialize_precision -1``, and a float without a
fraction is written as an integer: a suspended state does not keep the
fraction, so ``1.0`` and ``1`` must not differ across a resume. A value that
cannot be encoded (``JSON_THROW_ON_ERROR``) gets no digest. A new canonical
form is a new version prefix.

**What a digest is for.** The history already lies in a sealed store next to
the arguments themselves, so keying adds no secrecy there. It keeps a digest
meaningless outside its installation and useless for guessing short values
should it ever leave that store, at the cost of one HMAC per entry.

**The stored format stays compatible.** Both keys are optional. An entry
stored before this change has neither; a rule that needs a digest treats a
missing one as not matching and refuses. A present key must match
``^v1:[0-9a-f]{64}$``; any other value, an unknown version included, makes
the whole history unreadable and incomplete, as a malformed entry does today.
A runtime before this change drops both keys when it rebuilds the entries,
which again fails closed.

**The public surface grows additively.** ``ToolInvocationHistory::append()``
gains two optional trailing ``?string`` parameters, an entry's array shape
gains the two optional keys, ``ToolResult`` gains
``withObservedState(string)``, and one class computes digests so that a tool
and a rule compute them the same way.

**The first users** are a check tool and the contract rule of the A2A page
metadata skill: a write of the skill must be preceded by a successful check
of exactly its arguments, and the record must still be in the state that
check observed. A check whose verdict is negative reports ``failed``, so only
a passed check counts.

Consequences
============

- ●● A rule can require that a check ran on the very proposal being written
  and that the record has not changed since; checking one variant and writing
  another, and writing over a changed record, are refused before the
  approval. No clock is compared.
- ◑ Old suspended states keep resuming; their entries have no digest, so a
  binding rule refuses until the model checks again.
- ◑ Rotating the encryption key, or changing the canonical form, invalidates
  the digests of waiting runs; a binding rule asks for a new check, the
  conservative direction.
- ◑ A tool decides what "state" covers; a rule that compares it has to
  compute the state the same way, through the shared class.

Net Score: +1

Alternatives considered
=======================

**Store the arguments.** Rejected: ADR-216 item 7 keeps policy metadata from
copying arguments, and a second copy in the history serves no rule better
than a digest.

**Compare clocks.** Rejected: a record's ``tstamp`` is the writer's start
time, so "changed after the check" cannot be told from it.

**A plain SHA-256.** Rejected for the reasons above; it would also make
digests comparable across installations.

**Bind through the target only.** Rejected: a target names the record, not
the values; two variants for the same record share it.
