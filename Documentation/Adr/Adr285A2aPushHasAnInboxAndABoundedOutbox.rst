.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-285:

=====================================================================
ADR-285: A2A push has an inbox and a bounded outbox
=====================================================================

:Status: Accepted
:Date: 2026-11-10
:Authors: Netresearch DTT GmbH

Context
=======

A2A push notifications are HTTP POSTs of a ``StreamResponse`` to a webhook the
client registered, by ``CreateTaskPushNotificationConfig`` or embedded in
``SendMessage`` (sections 3.1.7 and 4.3.3 of the specification). The sender must
try at least once, may retry with backoff, should time out within 10 to 30
seconds; the receiver must answer 2xx and should tolerate duplicates.

nr-llm is a sender in the server role and a receiver in the client role. As a
receiver it needs a public, authenticated endpoint it does not have
(:ref:`ADR-280 <adr-280>` covers the server endpoint only). As a sender it must
not call a webhook from a request (:ref:`ADR-283 <adr-283>`), and every target
is an outside URL (:ref:`ADR-279 <adr-279>`).

``TaskPushNotificationConfig`` has an optional ``token`` and an
``authentication``. An example in the specification used to send the token as
``X-A2A-Notification-Token``; a2aproject/A2A#1594 asked about that header, a
maintainer answered that credentials belong in ``Authorization``, and
a2aproject/A2A PR #1793, merged on 2026-05-23, aligned the examples. The
v1.0.1 prose read for this decision contains no such header.

Decision
========

**Sender: an outbox written with the event.** A task event
(:ref:`ADR-282 <adr-282>`) and one delivery job per active config are written in
the same transaction. A worker delivers. Before each attempt it reads the config
again, checks the target through the address guard, loads the credential and
checks the delivery deadline; a deleted config starts no attempt.

**Sender: bounded and classified attempts.** POST of a bare ``StreamResponse``
with ``Content-Type: application/a2a+json``, HTTPS only, 15 seconds timeout. A
2xx acknowledges. A timeout, 429 or 502/503/504 retries with exponential
backoff and jitter, at most eight attempts. A 401 or 403 marks the config
"authentication failed", visible in the backend, and pauses it. A target the
guard refuses, or 404/410, stops the config. A redirect is a failed attempt, not
a new target. A request already sent when the config is deleted may still
arrive; that is documented and tested, not prevented.

**Sender: Bearer in** ``Authorization`` **only.** The first supported scheme is
a Bearer credential from ``authentication``. ``token`` is stored and returned
but never turned into a header. Changing a config is creating a new one and
deleting the old one; there is no update method. Credentials are stored as
sealed references and masked in the backend, in audit rows and in what ``Get``
and ``List`` return; ``a2a-python`` 1.3.0 returns them in clear. Every target
passes the address guard, which ``a2a-python`` 1.3.0 without a configured
validator does not do: in stage 0 it posted to a private Docker address.

**Receiver: its own endpoint and credential per registration.** Below the
site's configured A2A base URL, an endpoint accepts callbacks for registrations
nr-llm created. Each registration gets a random callback credential, handed to
the remote in ``authentication`` and stored hashed, as inbound client tokens
are. A callback without the right ``Authorization: Bearer`` is answered 401
and stores nothing. ``X-A2A-Notification-Token`` is ignored: ``a2a-python``
1.3.0 sends it beside ``Authorization`` (measured in stage 0), and it neither
authenticates nor refuses. The body is a plain ``StreamResponse`` and arrives
without ``A2A-Version``; the receiver requires neither.

**Receiver: registration in a fixed order.** Without an embedded config, the
client sends, stores the acknowledged remote task id, creates the local
registration, creates the remote one, and calls ``GetTask`` once to close the
gap. With an embedded config, the pending local registration is written before
the message is sent.

**Receiver: a bounded inbox, and push is only a signal.** A valid callback is
stored durably, within a bound per registration and in total (over it, 429),
before the endpoint answers 2xx; 2xx means received. A callback that arrives
before the remote task id is known is kept against the pending registration. A
worker reconciles by ``GetTask`` as the owning actor; the body itself decides
nothing. A duplicate or late callback never resolves a dependency twice
(:ref:`ADR-281 <adr-281>`), and polling stays the repair path.

Consequences
============

- ●● No request makes an outbound webhook call, and no webhook can make
  nr-llm act on content it did not fetch itself.
- ● A paused or stopped config is visible instead of retrying for ever.
- ◑ A second public endpoint exists; its security review is part of stage 5b.
- ◑ Push shortens the wait but does not replace polling.
- ✕ Two more tables and a delivery worker to operate.

Net Score: +3

Alternatives considered
=======================

**Deliver webhooks from the request that changed the state.** Rejected: it
makes a request wait for a foreign server and loses the delivery when that
request dies.

**Act on the callback body.** Rejected: the body is the remote's claim; the
state the run continues with is what ``GetTask`` returns to an authenticated
call.

**Send the** ``token`` **as a header.** Rejected: the specification defines no
such header, and its maintainers said credentials go in ``Authorization``.
