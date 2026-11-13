.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-280:

====================================================================
ADR-280: An inbound A2A client is a record with a hashed token
====================================================================

:Status: Accepted (the rejection of "Map a client to an editor's account" is amended)
:Date: 2026-11-10
:Authors: Netresearch DTT GmbH
:Amended: 2026-11-13 by :ref:`ADR-294 <adr-294>` (an editor may issue tokens that act as themselves, within group limits)

Context
=======

The server role of :ref:`ADR-278 <adr-278>` accepts requests from other
agents. nr-llm has no inbound API to build on: no frontend middleware, no token
authentication, no mapping from a caller to an actor. Everything a run does is
authorised against its actor, and :php:`ActingBackendUserResolver::resolve()`
returns null for an actor without a backend user (``backendUserUid <= 0``),
for a disabled or deleted user and for a workspace mismatch
(:ref:`ADR-260 <adr-260>`); every user-scoped tool then fails closed. A remote
caller that runs as "nobody" can therefore do nothing useful, and one that runs
as an editor can do everything that editor can.

The A2A specification requires authorization checks on every request and that
they happen before any lookup that could reveal a resource outside the
caller's scope (its section 13.1), and that "not found" and "not yours" are
indistinguishable (section 3.3.2). It leaves the authorization model to the
agent.

Decision
========

**A remote client is a record.** ``tx_nrllm_a2a_client`` holds a title, a
bearer token, a technical backend user, a pinned workspace, the publication
profiles the client may call, a task limit, ``enabled`` and an expiry. Only an
administrator can create or change it.

**The token is stored as a hash.** A token is ``nra2a_<prefix>_<secret>``: a
public prefix that is unique per record and 32 random bytes. The record keeps
the prefix and the SHA-256 of the whole token; the token is shown once, when it
is created. A request is authenticated by looking the record up by prefix and
comparing hashes with ``hash_equals()``. A high-entropy random secret needs no
slow hash; a stolen table still yields no usable token. Rotation replaces the
prefix and hash on the same record.

**The owner of everything a client creates is the client record's uid.** Tasks,
contexts and push configurations carry it. It survives token rotation and is
never derived from the token, the request or the technical backend user (two
clients may share one technical user).

**Scoping comes before lookup.** Every query that reads a task, a context or a
push configuration has the owner in its ``WHERE`` clause. A task id of another
client and an unknown task id give the same ``TaskNotFoundError``.

**The work runs as a technical backend user in a pinned workspace.** The
client record names a backend user that is enabled, has uid above 0 and is not
an administrator; a record that names anything else cannot be saved and is
refused at request time if it changed since. The run's actor is that user; its
workspace is the record's pinned workspace, and only the record's publication
profiles may be called. What the run may touch is therefore what that backend
user may touch, through the existing permission checks, not a second permission
system.

**The base URL is configured, never taken from the request.** Each site that
offers A2A names its base URL in its site settings. The agent card and every
URL in a response are built from it; a request whose host does not match a
configured base is not an A2A request. The ``Host`` header is not trusted.

Consequences
============

- ●● A remote caller can do exactly what one backend user may do, in one
  workspace, and only through the published profiles.
- ● Owner scoping makes cross-client reads impossible by construction rather
  than by a check after loading.
- ● Rotation keeps every task reachable for its client.
- ◑ Each client needs a backend user and a workspace prepared by an
  administrator.
- ✕ Bearer tokens only; a partner that needs OAuth 2.0 or mTLS needs a further
  decision.

Net Score: +4

Alternatives considered
=======================

**Run inbound work as a service account with uid 0.** Rejected: every
user-scoped tool fails closed for it, so it could do nothing a profile needs.

**Map a client to an editor's account.** Rejected: the client would inherit
everything the editor may do, and the editor's own changes would change it.

**Owner = the token or the backend user.** Rejected: rotation would orphan
every task, and two clients sharing a technical user could read each other's
tasks.

**Hash tokens with** ``password_hash()``. Rejected: it cannot be looked up and
needs a scan of every record per request; a prefix plus SHA-256 of a 256-bit
secret is not brute-forceable.
