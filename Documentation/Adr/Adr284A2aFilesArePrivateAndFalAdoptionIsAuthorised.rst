.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-284:

=======================================================================
ADR-284: A2A files are private, and adopting one into FAL is a write
=======================================================================

:Status: Accepted
:Date: 2026-11-10
:Authors: Netresearch DTT GmbH

Context
=======

An A2A ``Part`` carries a file either inline (``raw``, base64 in JSON) or as a
``url``. Stage 5c of ``specs/051-a2a-agent-interface`` lets both roles send and
receive files. A file from a remote party is untrusted content of an untrusted
type at an untrusted size; a ``url`` is a server-side request to an address the
remote chose (:ref:`ADR-202 <adr-202>`, :ref:`ADR-279 <adr-279>`).

TYPO3 keeps files in FAL. :php:`FalStorageGate` decides what a backend user
may reach: the configured storages intersected with the user's file storages,
and per file the user's file mounts, checked against a storage the gate builds
for that user (:php:`FalStorageGate::isFileAccessible()`). It caches those
storages per user and storage in an instance property with no invalidation;
a gate shared by a long-running ``messenger:consume`` process would answer
with a user's mounts as they were when it first built them. That effect on a
running worker has not been measured; the cache is in the code.

Decision
========

**Five media types, each validated.** ``text/plain``, ``application/json``,
``application/pdf``, ``image/png`` and ``image/jpeg`` are accepted and emitted,
each only after a validator for that type passed (encoding, JSON parse, PDF
structure, image decode). Archives, HTML and SVG are refused: they carry code or
nest content no validator here can bound.

**Inbound files land in a private store, not in FAL.** Request size, base64
length and decoded bytes are each limited. A ``url`` part is fetched by the
worker through the address guard, under an allow-list of file hosts kept apart
from the agent endpoints, and never with the credentials of the remote agent or
client. Each file is recorded with its owner, task, origin, real size, verified
media type, SHA-256, check and approval state and expiry, in a storage location
that is not public. The executor checks the task's approved input manifest
before the first model or tool call; an approved ``url`` input is the stored
copy and is never fetched again as "the same" input.

**Outbound files are inline when small and protected when not.** A file up to
the inline limit goes out as ``raw``. A larger one goes out as a ``url`` to a
download that requires the client's bearer token, or as a short-lived
capability URL scoped to one file and one client. A capability URL is a
transferable bearer right and the documentation says so. Re-issuing a download
right never changes the task's status, the artifact id, the digest or the
content. No artifact names a URL of the installation's own storage.

**FAL export is checked against the concrete file mount.** A FAL file goes into
an artifact only if the client's technical backend user reaches it through
:php:`FalStorageGate::isFileAccessible()` and the profile approves the
recipient.

**Adopting a file into FAL is an authorised write of its own.** Moving an
inbound file into FAL names the target storage, folder, file name and collision
rule, and is performed by an actor allowed to write there, as any other write
tool (:ref:`ADR-260 <adr-260>`). Cleanup of expired A2A files deletes only the
private store's objects, never a file that was adopted.

**Permissions are read fresh per run.** The FAL checks of A2A use a gate whose
per-user cache is reset for every run execution, so a long-running worker sees
a changed file mount on the next run.

Consequences
============

- ●● A remote file reaches a model or a tool only after its type, size and
  approval were checked, and never through a second fetch.
- ● Nothing a remote party sends becomes a FAL file unless someone allowed to
  write there adopts it.
- ◑ Partners that exchange archives, HTML or SVG need a further decision.
- ◑ A capability URL can be passed on by its holder until it expires.
- ✕ Files live in a second store with its own expiry and cleanup.

Net Score: +3

Alternatives considered
=======================

**Write inbound files straight into a FAL storage.** Rejected: an unchecked
upload would become a managed asset, visible to editors and possibly public.

**Emit FAL public URLs for large files.** Rejected: they bypass the client's
authorization and expose the installation's storage layout.

**Accept any type the profile declares.** Rejected: without a validator per
type the declared ``mediaType`` is the remote's claim, not a fact.
