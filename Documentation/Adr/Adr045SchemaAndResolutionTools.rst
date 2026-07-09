.. include:: /Includes.rst.txt

.. _adr-045:

==============================================
ADR-045: Schema and resolution tools
==============================================

:Status: Accepted
:Date: 2026-07-09
:Deciders: nr_llm maintainers

Context
=======

The content and introspection tools (:ref:`ADR-042 <adr-042>`) let an agent
read records, TypoScript and TSconfig. Debugging schema and templating
questions ("what does this table relate to?", "what fields does this FlexForm
have?", "which Fluid file actually wins?") still required guesswork.

Two shapes were considered for schema access:

#. **Send the whole TCA and let the model reason.** Rejected: the resolved
   TCA is several megabytes — it neither fits an LLM context window nor is
   affordable per call.
#. **Navigate: an index plus per-item detail.** Chosen. The model lists table
   names cheaply, then pulls the full definition only for the tables it cares
   about. Validation-style "find the error" tools remain a separate, later
   concern; these tools are retrieval, chunked to stay within budget.

The tools are **inspired by** ``konradmichalik/typo3-ai-mate`` (the Fluid
resolve and TCA introspection ideas) and ``hn/typo3-mcp-server``
(``GetTableSchema`` / ``GetFlexFormSchema``) — both GPL-2.0-or-later, like this
extension. They are independent re-implementations; no code is copied and
neither extension is a dependency.

Decision
========

Four read-only built-in tools:

``get_full_tca`` (group ``structure``)
   The TCA **index**: names + titles of every accessible table, each pointing
   at ``get_table_schema``. This is the "navigate, don't dump" answer — the
   whole TCA is never serialised at once. Optional ``filter`` / ``extension``.

``get_table_schema`` (group ``structure``)
   One table's readable schema: control highlights plus, per field, the type
   and — for relations — the **foreign table and relation kind**
   (group/select/inline/category). This relation view is the value over the
   raw ``get_tca``.

``get_flexform_schema`` (group ``structure``)
   A FlexForm field's data structure rendered as sheets → fields, via
   :php:`FlexFormTools`. When several structures are selectable by a pointer,
   the keys are listed for a precise follow-up call with ``ds_pointer``.

``fluid_resolve`` (group ``configuration``)
   The candidate template/partial/layout file paths in override order with an
   exists flag and the winning path, to debug "wrong template wins" or "not
   found".

Access model
============

``get_full_tca``, ``get_table_schema`` and ``get_flexform_schema`` reuse
:php:`TableReadAccessService` (ADR-042): the sensitive-table denylist holds for
every user including admins; non-admins additionally pass ``tables_select`` and
the TCA ``adminOnly`` flag. Credential-like columns are shown by name and type
only. ``fluid_resolve`` exposes file paths (never contents), rejects path
traversal, and requires a valid extension key.

Consequences
============

- The agent can traverse the schema (index → detail) within token budget and
  reason about relations and FlexForm structures it previously could not see.
- ``fluid_resolve`` resolves an extension's own ``Resources/Private`` paths.
  TypoScript-configured override root paths
  (``plugin.tx_*.view.*RootPaths``) require a live rendering context and are
  **not** reflected — documented as a known limitation, not a bug.
- Write access remains out of scope; ``hn/typo3-mcp-server``'s
  DataHandler-plus-workspace staging is the reference design if it is added
  later (see ADR-042).
