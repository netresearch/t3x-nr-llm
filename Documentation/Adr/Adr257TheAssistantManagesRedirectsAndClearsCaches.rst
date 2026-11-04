.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-257:

==========================================================
ADR-257: The assistant manages redirects and clears caches
==========================================================

:Status: Accepted
:Date: 2026-11-04
:Authors: Netresearch DTT GmbH

Context
=======

The eighteen writing tools of :ref:`ADR-135 <adr-135>` to
:ref:`ADR-230 <adr-230>` change content: pages, content elements, files and
records of extension tables. Two chores an administrator asks the assistant
for are not content. A page moved or renamed needs a redirect from its old
address, and a change the frontend does not show yet needs a cache cleared.
Both were possible only by leaving the chat for the redirects module or the
clear-cache menu.

Both are site configuration with an immediate effect on every visitor. A
redirect applies as soon as it is stored, because EXT:redirects rebuilds the
redirect cache of the host in a DataHandler hook. A cache flush slows the next
requests down, and a full flush empties the system caches too.

Decision
========

Five builtins, four of them writers:

- ``list_redirects`` (read-only, ``configuration`` group) lists the redirects
  with uid, source, target, status code and a disabled marker, filtered by host
  or by text.
- ``create_redirect``, ``update_redirect`` and ``disable_redirect`` write one
  ``sys_redirect`` record each through the DataHandler as the acting user.
- ``clear_cache`` clears one cache group, or the page caches by tag.

What they share with the other writers, unchanged: they declare a write
effect, so every call pauses for approval (:ref:`ADR-134 <adr-134>`); they
ship disabled and sit in the ``editing`` group (:ref:`ADR-135 <adr-135>`);
the approval card is the reservation compared on resume
(:ref:`ADR-184 <adr-184>`) and is written in the acting user's language
(:ref:`ADR-213 <adr-213>`). The redirect writers reuse the writers' shared
plan, refusal and DataHandler errands, and read every record back after the
write.

**Administrators only.** The redirects module lets a non-administrator manage
redirects for the hosts of their own sites and for targets they may see.
EXT:redirects checks that rule in ``RedirectPermissionGuard`` against
``$GLOBALS['BE_USER']``, the backend user of the request. On a resumed run
that is the approver, not the user the run acts for, and this runtime does not
set an ambient user (:ref:`ADR-083 <adr-083>`). Reimplementing the guard
against the explicit user would make the tool and the hook answer the same
question twice with two users. An administrator passes the guard whoever is
ambient, so the four writers and the list are administrator-only. Clearing
caches is an administrator's task in the backend too.

**The module's rules, from the live TCA.** The source host goes through
EXT:redirects' own ``SourceHost`` evaluation, the path length through the
column's ``max``, the status code through the column's items. The rules
therefore follow TYPO3 13.4 and 14.3 alike. Four rules are the tools' own,
because the module accepts the value and the redirect then never works: a
plain path without a leading slash, a plain path with a query string on a
redirect that ignores query strings (core matches only the path then), a
regular expression that does not compile, and a target that is its own source
on a host this TYPO3 serves. A second redirect that core files under the same
key as an existing one — same host, same kind of match, and for a plain path
the same path with or without its trailing slash — is refused and the existing
uid named, because core serves only the older of the two.

**Default redirects only.** TYPO3 14 stores QR codes and short URLs in the
same table, with a ``redirect_type``. Their fields mean something else, so the
writers refuse them and the list leaves them out.

**Disable, not delete.** ``disable_redirect`` sets the flag the module's
toggle sets, and ``update_redirect`` with ``disabled: false`` takes it back.
No tool deletes a redirect: a disabled one keeps its hit counter and history,
and a deleted one is gone from the module.

**Caches through core's own command.** ``pages``, ``all`` and tags go through
``DataHandler::clear_cacheCmd()``, which the clear-cache menu calls. That logs
the flush under the acting user, runs the hooks extensions register for it,
and applies the user TSconfig ``options.clearCache.all``. Any other configured
group is flushed through the cache manager, as the install tool does.

**The card says what changes.** A redirect card shows each field, or each
named field before and after. It leaves out the hit counter, the last hit,
the modification time and the integrity status: they change with traffic
between the pause and the approval, and every approval would then go stale
on resume. A cache card names the group and the caches it empties, from the
configuration, never entry counts. ``all`` gets its own heading,
"Flush ALL caches", and a line saying that every cache is emptied, the system
caches included, and the PHP opcode cache is reset.

Consequences
============

- An administrator sets up, changes or switches off a redirect and clears a
  cache from the chat, after reading on the card exactly what changes.
- Editors cannot use these tools, even where the redirects module would let
  them manage redirects for their own sites.
- ``clear_cache`` names no record, so it carries no write target
  (:ref:`ADR-182 <adr-182>`); ``ToolEffectCoverageTest`` lists it as the one
  writer without one. Its flushes are in core's system log.
- EXT:redirects is optional. Without it the redirect tools refuse with a
  sentence that says so, and ``composer.json`` suggests the package.
- A ``t3://page`` target is checked to exist, not to differ from the source:
  a page link that resolves to the source path loops, and core's integrity
  check (``redirects:checkintegrity``) reports it.

Alternatives considered
=======================

- **Redirect tools for non-administrators.** Rejected for now: it needs the
  module's permission rule evaluated against the explicit acting user, while
  core's own hook evaluates it against the ambient one.
- **A delete tool.** Not built. Disabling is reversible and keeps the record's
  history; a deletion is a decision for the module.
- **One redirect tool with an action argument.** Rejected: one tool per act
  keeps each effect and each card simple, as the other writers do.
