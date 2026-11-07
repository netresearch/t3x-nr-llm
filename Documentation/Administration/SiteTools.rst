.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

=========================
Redirects and cache tools
=========================

.. _administration-tools-site:

The site tools: redirects and caches
====================================

Four writers change the site rather than its content (:ref:`ADR-257 <adr-257>`).
They are **administrator-only**: the redirects module's rule for everybody else
— the hosts of the user's own sites and access to the target page or file — is
checked by EXT:redirects against the backend user of the request, which on an
approved run is the approver, not the user the run acts for. Like every writer
they ship disabled, sit in the ``editing`` group and pause for approval. In
a draft workspace the three redirect tools are refused before the approval:
a redirect cannot be kept as a draft there (:ref:`ADR-262 <adr-262>`).
``clear_cache`` writes no record; it works in a draft workspace as in live,
and empties the same caches.

``create_redirect``
   Creates one redirect of EXT:redirects (``sys_redirect``) through the
   DataHandler: source host (``*`` for every host) and path, target, status
   code and the module's options. It applies to visitors as soon as it is
   approved, because core's own hook rebuilds the redirect cache of the host;
   the card says so. The module's rules hold: the host goes through
   EXT:redirects' own check, the path length through the field's maximum, the
   status code must be one the field offers. Six more are refused because
   the redirect would never work: a plain path without a leading slash, a
   percent-encoded plain path (write ``/über-uns``, not ``/%C3%BCber-uns``), a
   plain path with a query string unless the redirect respects query
   parameters, a path without one when it does, a regular expression that
   does not compile, and a plain ``http`` or relative target that is exactly
   the source on a host this TYPO3 serves. A target is a ``t3://`` link core resolves (a page that exists, a
   file, a record), an absolute ``http(s)`` URL or a path. A redirect for a host
   and path another redirect already covers — ``/old`` and ``/old/`` are the
   same path to core — is refused, naming that redirect's uid.

``update_redirect``
   Changes the fields of one redirect the call names; every other field stays,
   and the result passes the same checks. ``disabled: false`` enables a
   disabled redirect again. The card shows each named field with its current
   and its proposed value, and says when visitors get the change: at once,
   only between the redirect's start and stop times, or not while it stays
   disabled. A QR code or a short URL (TYPO3 14) is refused:
   change those in the module.

``disable_redirect``
   Sets the ``disabled`` flag of one redirect, as the module's toggle does.
   The record, its hit counter and its history stay; nothing is deleted.

``clear_cache``
   Clears one cache group — ``pages`` for the frontend page caches,
   ``system``, an extension's own group such as ``nrllm``, or ``all`` — or the
   page caches by tag (``pageId_12``). ``pages``, ``all`` and tags go through
   core's own clear-cache command, as the backend's clear-cache menu does: the
   flush is logged under the acting user and extensions' hooks run. Other
   groups go through the cache manager, without a log entry. An administrator
   whose user TSconfig sets ``options.clearCache.all = 0`` is kept to the page
   caches, as the backend menu keeps them. ``all`` empties **every** cache, the system caches
   included, and resets the PHP opcode cache; the card's heading says
   "Flush ALL caches" and a line of its own says what a full flush does. For
   a group the card names the caches it empties, from the configuration.

The cards show nothing that changes by itself between the pause and the
approval — no hit counter, no last-hit time, no cache entry counts — because
the card is compared when the run resumes (:ref:`ADR-184 <adr-184>`).
