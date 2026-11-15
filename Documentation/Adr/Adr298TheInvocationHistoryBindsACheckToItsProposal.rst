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
string of nr-llm, the construction TYPO3's ``HashService`` uses. Each field
has its own purpose string, so an ``arguments`` digest never equals a
``state`` digest of the same value. PHP's ``hash_hmac()`` computes it,
because TYPO3 13.4's ``HashService`` offers SHA-1 only. An empty encryption
key gives no digest.

- ``arguments`` is the digest of the call's final arguments, after any input
  a human typed (:ref:`ADR-216 <adr-216>` item 5). The runtime fills it for
  every entry of a call it decided or executed.
- ``state`` is the digest of a record state a tool observed. One class owns
  both the read and the digest: given a table, a uid, a list of fields and
  the run's execution context, it reads the record as the workspace of the
  run shows it (:ref:`ADR-260 <adr-260>`, the workspace the writing tools
  write into), takes exactly the listed fields, writes each value as a
  string (``null`` stays ``null``), and returns an ``ObservedState`` object.
  Only that class creates one. A tool reports it through
  ``ToolResult::withObservedState(ObservedState)``; the runtime copies its
  digest into the entry. A tool therefore cannot hand over a raw value or a
  digest in a wrong format.

A rule asks the same class for the state of the same record and fields now,
and compares the digests, and it compares the call in front of it with the
stored ``arguments``. Equal means the same final arguments and the same
fields as the run's workspace shows them. Which fields count is the tool's
and rule's shared declaration, in the sense of :ref:`ADR-184 <adr-184>`:
the fields the check judged, not ``tstamp``.

**Canonical form, version 1.** The JSON encoding of the value, where an
array that is not a list has its keys sorted as strings
(``ksort(SORT_STRING)``) and stays an object, and a list keeps its order.
Slashes and Unicode are not escaped, integers and numeric strings stay apart,
floats are written with ``serialize_precision -1``, and a float without a
fraction whose magnitude is below 2^53 is written as an integer (``-0.0`` as
``0``): a suspended state does not keep the fraction, so ``1.0`` and ``1``
must not differ across a resume. Every other float keeps its JSON float
form. Whether an array is a list is decided before its keys are sorted. A
value that cannot be encoded (``JSON_THROW_ON_ERROR``) gets no digest. A new
canonical form is a new version prefix.

**What a digest is for.** The history already lies in a sealed store next to
the arguments themselves, so keying adds no secrecy there. It keeps a digest
meaningless outside its installation and useless for guessing short values
should it ever leave that store, at the cost of one HMAC per entry.

**The stored format stays compatible.** Both keys are optional, and an
entry without a digest omits the key; it is never stored as ``null``. An
entry stored before this change has neither; a rule that needs a digest
treats a missing one as not matching and refuses. A present key must match
``^v1:[0-9a-f]{64}$``; any other value, an unknown version included, makes
the whole history unreadable and incomplete, as a malformed entry does today.
A runtime before this change drops both keys when it rebuilds the entries,
which again fails closed.

**The public surface grows additively.** ``ToolInvocationHistory::append()``
gains two optional trailing ``?string`` parameters (``null`` omits the
key), an entry's array shape gains the two optional keys, ``ToolResult``
gains ``withObservedState(ObservedState)``, and the class that reads and
digests a record state is public, so that a tool and a rule compute the
same digest.

**The first users** are a check tool and the contract rule of the A2A page
metadata skill: a write of the skill must be preceded by a successful check
of exactly its arguments, and the record must still be in the state that
check observed. The check tool takes exactly the write tool's arguments, so
the same map can be checked and written. A check whose verdict is negative
reports ``failed``, so only a passed check counts. A write whose arguments a
human changed by typed input no longer matches its check, and the model
checks again.

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
- ◑ The tool and the rule must declare the same fields; the shared class
  makes the read and the digest identical, not the choice of fields. A
  field outside the list can change without being noticed, as with
  :ref:`ADR-184 <adr-184>`.

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
