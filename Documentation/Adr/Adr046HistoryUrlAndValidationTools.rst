.. include:: /Includes.rst.txt

.. _adr-046:

==============================================
ADR-046: History, URL and validation tools
==============================================

:Status: Accepted
:Date: 2026-07-09
:Deciders: nr_llm maintainers

Context
=======

The schema tools (:ref:`ADR-045 <adr-045>`) let an agent *retrieve* structure
and reason over it. Three recurring editor/integrator questions still had no
tool: "who changed this record?", "which page serves this URL?", and "is this
TCA/TypoScript actually broken?". The last two are *deterministic* questions —
an LLM guessing at brace balance or showitem consistency over retrieved text
is unreliable where an exact scanner exists.

For TCA validation the core :php:`TcaMigration` was considered and rejected:
it only emits messages while migrating the **raw, pre-boot** TCA; at runtime
``$GLOBALS['TCA']`` is already migrated, so replaying it reports nothing.
Structural checks over the live TCA are implemented directly instead.

For TypoScript the core include-tree scanner
(:php:`IncludeTreeSyntaxScannerVisitor`, with
:php:`SysTemplateTreeBuilder`/:php:`IncludeTreeTraverser`) is reused — the
same code path the backend's TypoScript module uses to mark broken syntax.
These classes are ``@internal``; the risk is accepted because the surface
used is tiny, covered by functional tests, and verified identical on 13.4
and 14.

Decision
========

Four read-only built-in tools:

``get_record_history`` (group ``content``)
   One record's ``sys_history`` newest-first: timestamp, resolved backend
   username, action, and per modification the changed fields as
   ``old → new`` pairs. Answers "wer hat die Überschrift geändert".

``resolve_url`` (group ``structure``)
   URL → page mapping via the real :php:`SiteMatcher`/`PageRouter` — site,
   language, page uid/title/slug, route arguments. Routing only, no HTTP;
   the complement of ``probe_url`` (which fetches but does not explain).

``validate_tca`` (group ``structure``)
   Structural checks over the live TCA: ``ctrl.label``/``ctrl.type`` must
   name defined columns, ``foreign_table`` must reference TCA tables,
   ``types``/``palettes`` showitem entries must reference defined columns
   and palettes, flex ``ds_pointerField`` is flagged on v14+ (removed
   there).

``check_typoscript`` (group ``configuration``)
   Constants and setup include trees of a page's sys_template chain run
   through the core syntax scanner: invalid lines, unbalanced braces,
   ``@import`` matching no file — each with source and line number.

Access model
============

``get_record_history`` and ``validate_tca`` reuse
:php:`TableReadAccessService` (ADR-042): the sensitive-table denylist holds
for every user including admins; non-admins additionally pass
``tables_select``, and ``get_record_history`` also requires page-show on
the record's page (the per-row gate of ``read_records``, fail-closed for
unresolvable records). History values of credential-like fields are
withheld (the *fact* of the change stays visible). ``resolve_url`` needs no host
allowlist — :php:`SiteMatcher` only knows this instance's sites, so foreign
hosts cannot match by construction; non-admins must hold page-show on the
resolved page, with the same neutral denial as ``get_page_content``.
``check_typoscript`` is **admin-only** like ``get_typoscript``, and reports
source + line number + error kind only — the offending line's content is
never echoed, because a broken constants line may carry an API key.

Consequences
============

- The four remaining diagnostic use-cases named in the tool-expansion plan
  (record attribution, URL mapping, TCA and TypoScript validation) are
  covered by deterministic tools instead of model guesswork.
- ``check_typoscript`` depends on ``@internal`` core classes; a core
  refactoring may require adaptation. The functional tests pin the observable
  behaviour, so a break surfaces in CI, not at runtime.
- ``validate_tca`` intentionally implements a *small* rule set with exact
  semantics rather than replicating the Extension Scanner; new rules can be
  added as they prove useful.
