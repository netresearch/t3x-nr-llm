.. include:: /Includes.rst.txt

.. _adr-048:

==========================
ADR-048: Diagnostics tools
==========================

:Status: Accepted
:Date: 2026-07-09
:Deciders: nr_llm maintainers

Context
=======

The tool family answers content, schema, configuration, code and file
questions — but "establish the system context first" still needed a human:
which extensions and versions run here, which sites exist, did the nightly
task fire, is this a composer-mode v14 on PostgreSQL, what does the
deprecation log say, which middleware could intercept this request. Every
diagnosis conversation starts with a subset of these.

Decision
========

Six read-only, **admin-only** built-in tools — they enumerate the
instance's configuration and attack surface, so none is offered to
non-admins:

``list_extensions`` (group ``system``)
   Active packages with key, version, composer name and title via
   :php:`PackageManager`. Package paths never egress.

``get_site_config`` (group ``configuration``)
   Site listing, or one site's configuration flattened to dotted
   ``key: value`` lines. Keys matching the credential pattern of
   :php:`TableReadAccessService` redact their value — with camelCase
   normalization (``apiKey`` → ``api_Key``) because site settings are
   commonly camelCase while the pattern is snake_case-segment based.
   Output is line- and value-capped, never raw YAML.

``list_scheduler_tasks`` (group ``system``)
   Plain columns of ``tx_scheduler_task`` only. The
   ``serialized_task_object`` blob is **never unserialized** — feeding a
   DB-supplied object graph to :php:`unserialize()` is an object-injection
   primitive, and the plain columns answer the diagnostic question. The
   column set differs between 13.4 (SQL-defined) and 14 (TCA-defined,
   adds ``tasktype``), so available columns are introspected per instance.
   An absent table degrades to "Scheduler is not installed."

``get_system_status`` (group ``system``)
   TYPO3/PHP/database versions, application context, composer mode, OS
   family, timezone — versions and flags only, no paths, no hostnames.
   The database version comes from Doctrine's
   :php:`Connection::getServerVersion()` (dbal ~4.4 on both 13.4 and 14).

``list_deprecations`` (group ``system``)
   Tail of the newest ``var/log/typo3_deprecations_*.log``: distinct
   messages deduplicated with a ×count suffix, absolute project paths
   rewritten to relative, width- and count-capped. A missing file or
   disabled channel degrades to a plain message.

``list_middlewares`` (group ``system``)
   One PSR-15 stack in execution order via
   :php:`MiddlewareStackResolver` — ``@internal`` core API (same caveat as
   the include-tree classes used by ``check_typoscript``, ADR-046); its
   return type differs across versions (``array`` on 13.4,
   ``ArrayObject`` on 14) and is consumed as an iterable. Resolution
   failures collapse into one neutral message.

Considered and rejected
=======================

``list_event_listeners`` was part of the original idea list: the core
:php:`ListenerProvider` exposes no stable public enumeration API across
13.4 and 14, so a listener inventory would depend on container internals
that change per version. Dropped until the core offers a supported way.

Consequences
============

- A model can establish the full system context (versions, extensions,
  sites, automation, upgrade debt, request pipeline) without a human
  relaying installer or reports-module screenshots.
- All six are admin-only; the ``system``/``configuration`` group toggles
  (ADR-043) disable them centrally, per configuration and per run.
- Two ``@internal`` core APIs are consumed (``MiddlewareStackResolver``
  here, the include-tree scanner in ADR-046) — accepted as the only
  practical access path, guarded by neutral failure modes and the CI
  matrix across both supported majors.
