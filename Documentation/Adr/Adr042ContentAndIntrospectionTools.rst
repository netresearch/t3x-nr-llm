.. include:: /Includes.rst.txt

.. _adr-042:

===============================================================
ADR-042: Content and configuration read tools for the agent
===============================================================

:Status: Accepted
:Date: 2026-07-08
:Authors: Netresearch DTT GmbH

.. _adr-042-context:

Context
=======

The first eleven built-in tools (:ref:`ADR-038 <adr-038>`) are system
introspection: page-tree structure, TCA schema, logs, environment, backend
accounts. The agent could not read the one thing most tasks are about —
**content**: no full-text search, no "what is on this page", no generic
record read, and no view of the TypoScript/TSconfig that shapes rendering.

Two third-party extensions cover adjacent ground and informed this
decision (both GPL-2.0-or-later, licence-compatible with nr-llm):

- **EXT:typo3_ai_mate** (konradmichalik) — a dev-only, read-only debugging
  toolset (records, page composition, resolved TypoScript/TSconfig, Fluid
  resolution, logs, profiler). Its tool classes run *outside* TYPO3 in a
  ``symfony/ai-mate`` MCP process and shell into TYPO3 console commands, so
  they cannot be reused in-process — but its catalogue shows which
  introspection reads matter.
- **EXT:mcp_server** (hauptsache.net) — an in-TYPO3 MCP server for content
  editing as an authenticated backend user (Search, GetPage, ReadTable,
  WriteTable). Architecturally the closest cousin (tagged-iterator tool
  registry, JSON-Schema specs); its layered permission model
  (``tables_select`` → DataHandler → workspace-staged writes) is the
  reference for any future write path.

.. _adr-042-decision:

Decision
========

Build five **native, read-only** tools — own implementations inspired by
those catalogues, with no dependency on either extension:

``search_records``
    Full-text search across tables declaring TCA ``searchFields``
    (mcp_server's Search as the model).

``get_page_content``
    One page plus its content elements in column/sorting order (mcp_server's
    GetPage as the model).

``read_records``
    Generic equality-filtered read of one TCA table (mcp_server's ReadTable
    and ai_mate's typo3-records as the models). Never raw SQL — equality
    filters bound as named parameters only.

``get_typoscript``
    The resolved frontend TypoScript (setup/constants) for a page via the
    core v13/v14 APIs (rootline → ``SysTemplateRepository`` →
    ``FrontendTypoScriptFactory``), resolved in-process (ai_mate resolves
    the same data via a CLI subprocess).

``get_tsconfig``
    The rootline-merged Page TSconfig via
    ``BackendUtility::getPagesTSconfig()``.

A shared :php:`TableReadAccessService` centralises the read policy for the
three record tools instead of three copies.

.. _adr-042-permission-model:

Read-permission model
=====================

All five tools follow the fail-closed contract of :ref:`ADR-038 <adr-038>`
(no backend user → no data) and add:

#.  **Sensitive-table denylist — absolute.** ``be_users``, ``be_groups``,
    ``fe_users``, ``fe_groups``, ``sys_log``, ``sys_history``,
    ``sys_refindex`` and every ``tx_nrllm*`` table are unreadable for
    *every* user including admins: credentials and audit data have
    dedicated redacting tools, and the nr-llm tables carry provider
    endpoints and vault key references that must never egress to a
    provider.

#.  **Sensitive-field denylist — absolute.** Columns whose name contains a
    credential-ish segment (``password``, ``secret``, ``token``, ``salt``,
    ``hash``, ``key``, ``mfa``, …) are dropped from every select, filter
    and search-field list, for every user.

#.  **Non-admin narrowing.** Non-admins are additionally limited to tables
    granted by ``tables_select`` (TCA ``adminOnly`` tables excluded), get
    the default query restrictions (no hidden/timed rows), and every
    emitted row's page is checked against the acting user's ``PAGE_SHOW``
    permission — memoised per page uid, applied *after* the query, so a
    result page may return fewer than ``limit`` rows rather than weakening
    the check. Root-level rows (``pid 0``) of non-``pages`` tables fail
    closed for non-admins.

#.  **Admin-only TypoScript/TSconfig.** ``get_typoscript`` and
    ``get_tsconfig`` are admin-only — TypoScript constants routinely carry
    API keys and DSNs — and still redact values under credential-ish keys
    as defence in depth, and cap output (top-level keys without a ``path``,
    hard line cap with one).

.. _adr-042-writes-deferred:

Write tools deferred
====================

State-changing tools (create/update/delete records) are **explicitly out of
scope**. If nr-llm ever adds them, EXT:mcp_server's write design is the
reference: all writes through :php:`DataHandler` as the acting backend user
(page permissions and hooks apply), gated by a ``TableAccessService``, and
staged in a non-live **workspace** so every agent change requires a human
publish. Until that model is implemented here, the agent stays read-only.

.. _adr-042-consequences:

Consequences
============

- The agent can search and read content, records and the effective
  TypoScript/TSconfig on every installation, with no third-party
  dependency.
- Non-admins can safely use the three content tools: the tools enforce the
  same visibility the backend already grants them.
- The denylists are intentionally not configurable — configurability would
  invite weakening the egress guarantees.
- The tool count grows to sixteen; the Tools module and the playground pick
  the new tools up automatically via the registry (no UI change).
