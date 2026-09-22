.. include:: /Includes.rst.txt

.. _adr-197:

============================================================================
ADR-197: A generic record creator, only where no narrow writer exists
============================================================================

:Status: Accepted
:Date: 2026-09-21
:Amends: :ref:`ADR-135 <adr-135>` (its refusal of a generic writer, for the CREATE case under the conditions below), :ref:`ADR-180 <adr-180>` (the rejected alternative, re-evaluated as a builtin rather than a remote tool)
:Authors: Netresearch DTT GmbH

.. _adr-197-context:

Context
=======

:ref:`ADR-135 <adr-135>` refused a generic ``update_record(table, uid,
fields)``: its blast radius is the whole TCA, the arguments are model-chosen,
and a model is steerable by injected prose, so "the model would not do that"
is not a control. Every writer since then is narrow, and each one is a review
of one allow-list. :ref:`ADR-180 <adr-180>` re-evaluated a generic
``WriteTable`` tool of a third-party MCP server and rejected it as well: one
tool over the whole TCA, writing into a workspace of its own choosing,
admin-only as a remote tool.

A live editorial run on the Netresearch demo (NEXT-158) hit the limit of that
model from the other side. The assistant recognised EXT:news and its table
and could not create a news record, because no writer exists for it and none
will exist for every extension an installation carries. NEXT-160 asked what
it would take to serve every content type and extension of the demo.

The position this record works from: an extension that wants its records
written by the assistant brings its own writer. The tool contract is public
(:php:`ToolInterface` carries ``#[AutoconfigureTag('nr_llm.tool')]``), and a
writer shipped by EXT:news, or by a bridge extension on its behalf, is reviewed
where that extension's TCA is known. This record does not replace that. It
covers the gap while such a writer does not exist.

Two facts changed since ADR-135 that its argument rested on:

- The controls it wanted are in place and shared: the approval pause before
  every declared write (ADR-134), the acting-user authorisation every writer
  performs itself (ADR-083), the read-back that names what the DataHandler
  dropped, the hidden draft, and the preview that doubles as the reservation
  compared on resume (ADR-184).
- The DataHandler enforces the acting user's rights for every table:
  ``tables_modify``, page permissions on the ``pid``, ``non_exclude_fields``,
  ``authMode`` on selects, language access. A generic tool does not widen
  what the user may write; it widens what the model may ask for.

.. _adr-197-decision:

Decision
========

``create_record_draft`` is a builtin writer that creates ONE hidden record in a
TCA table for which no narrow writer exists. It is a fallback, not a
replacement, and every one of the following conditions is part of the
decision. Removing one reopens ADR-135's argument.

1. **Table allow-list by exclusion, reviewed once.** The table must be in the
   loaded TCA, must not be ``pages`` or ``tt_content`` (they have their
   writers), must not be one of the tables no tool may read
   (:php:`TableReadAccessService::SENSITIVE_TABLES` and the prefixes
   ``tx_nrllm``, ``tx_nrvault``), must not be ``adminOnly`` or ``hideTable`` or
   ``readOnly`` in its ``ctrl``, must not be a ``sys_*`` table, and must not be
   the target of another registered writer. A writer declares its tables
   through :php:`EditorAction::$recordTypes`; the fallback iterates the tools
   registered through the ``nr_llm.tool`` tag at call time — the set
   :php:`ToolRegistry` indexes, an extension's own writer included — and
   steps back wherever a declaration names the table. ``pages`` and
   ``tt_content`` are named in the fallback itself, because their writers
   declare the SUBJECT an editor selects, the page, rather than the table
   they write (:ref:`ADR-152 <adr-152>`). A tool an MCP provider supplies
   never declares an editor action and is not consulted. An installation
   narrows further through the extension configuration
   ``tools.createRecordDraft.deniedTables``; it cannot widen, and a
   configuration that cannot be read refuses every table.

2. **The acting user's rights, checked before the write and reported after
   it.** ``tables_modify`` for the table; the content-edit permission
   (:php:`Permission::CONTENT_EDIT`) on the page or folder the ``pid`` names,
   which is what :php:`DataHandler::hasPermissionToInsert()` asks for every
   table but ``pages`` — a record at the root level (``pid`` 0) is not
   created; ``non_exclude_fields`` for every column the call sets, the hidden
   column included, because the DataHandler drops such a column in silence;
   ``checkLanguageAccess`` for the default language; the live workspace only,
   through :php:`WritesThroughDataHandlerTrait`. What the DataHandler still
   rewrites or drops in silence — a ``min`` it resets, a hook, a grant the
   pre-check does not model — is read back column by column; on a mismatch
   the record is deleted again and the columns are named, as the creating
   sibling writers do with a record they cannot vouch for. A rich-text
   column, whose stored form the RTE rewrites, is checked for presence only.

3. **Scalar columns only.** A column can be set when its TCA type is
   ``input``, ``text``, ``number``, ``email``, ``color``, ``datetime``,
   ``check``, ``radio`` or ``select`` — a select or radio only with static
   ``items``, single-valued, without ``foreign_table``, ``itemsProcFunc`` or
   ``MM``; a datetime only in the ``datetime`` or ``datetimesec`` format
   stored as a timestamp, because the DataHandler normalises ``date``,
   ``time`` and ``timesec`` on the way in and stores a native ``dbType``
   column in a form the read-back cannot compare. Everything else —
   ``inline``, ``file``, ``group``, ``flex``, ``category``, ``folder``,
   ``imageManipulation``, ``slug``, ``link``, ``password``, ``uuid``,
   ``json``, ``passthrough``, ``user``, ``none`` — is not an argument. A
   ``slug`` the TCA generates from other fields is left to the DataHandler. A
   column not in the record type's ``showitem``, palettes expanded, is
   refused; the record type is the value the call gives for the
   ``ctrl.type`` column, else that column's default, else core's own fallback
   (``0``, then ``1``), and a table whose record type lives in a related
   record (a ``ctrl.type`` of the form ``field:field``) is refused. Values
   are checked by type the way :ref:`ADR-194 <adr-194>` checks a select:
   against the items; within the TCA ``max``, or 255 characters for an
   ``input`` or ``email`` and 20000 for a ``text`` where none is declared; a
   number as integer or decimal within ``range``; a check as 0/1; a datetime
   as a UNIX timestamp or an ISO 8601 date-time within ``range``, handed on
   as the timestamp; an email as a valid address; a color as a hexadecimal
   value. The DataHandler would clamp a value outside ``range`` in
   silence; the tool refuses it, so the approver never reads a value the
   record will not carry.

4. **Column deny-list regardless of type.** ``uid``, ``pid`` (an argument of
   its own, never a field), the ``ctrl`` columns for delete, versioning,
   sorting, timestamps and cruser, the enable columns (``hidden`` is forced to
   1 and cannot be set; ``starttime``, ``endtime``, ``fe_group`` refused), the
   language columns (the record is created in the default language, see
   condition 7; the language field, the parent and the source pointers are
   refused), ``editlock``, and every column whose name starts with
   ``perms_``, ``TSconfig`` or ``t3ver_``.

5. **Hidden, once, behind the approval, with a readable preview.** The record
   is created with its ``enablecolumns.disabled`` column set; a table without
   one is refused, because nothing this extension writes is visible before a
   human unhides it (:ref:`ADR-135 <adr-135>`). The tool declares
   ``NON_IDEMPOTENT_WRITE`` so the pause applies. The preview is built from
   the TCA labels of the table and its columns in the viewer's language, one
   line per field, so the approver reads "Datum: 2026-09-21" rather than a
   column name and a raw value. It is a function of the arguments, the
   current TCA and the title of the page the ``pid`` names — the inputs the
   sibling writers' previews read — which is what ADR-184's comparison needs.

6. **Required columns are required here.** A column the TCA marks
   ``required`` for the chosen record type must be present in the call and
   non-empty — the DataHandler drops an empty required value in silence, as
   :ref:`ADR-135 <adr-135>` records — and the refusal names it. The tool
   does not invent values.

7. **Default language only.** The record is created with its language field
   at 0 where the table has one; there is no language argument. A record in
   another language is a translation of an existing record, which needs a
   parent and a tool of its own — ``create_translation_draft`` exists for
   pages and content elements and for nothing else — and a standalone record
   in a non-default language is what :ref:`ADR-193 <adr-193>` found to mix a
   page. The condition that record reads is ``tt_content``'s; no equivalent
   exists for an arbitrary table, and the fallback does not invent one.
   ``checkLanguageAccess(0)`` is still asserted against the acting user, as
   :ref:`ADR-135 <adr-135>` does for the file writers.

What the fallback does not do, and why: it does not update or delete (the
safety line of ADR-135 and ADR-180 stands for those; a wrong CREATE leaves a
hidden record to delete, a wrong UPDATE overwrites work); it does not create
child records or file references (one call, one record, ADR-180's multi-record
review is still open); it does not publish.

.. _adr-197-relation:

Relation to the narrow writers and to extension-shipped writers
===============================================================

The narrow writers stay first. Where one exists for a table, the fallback
refuses that table and names the writer. Where an extension registers a
writer for its table, the same rule applies from the day it is installed:
the catalogue lookup is at call time, so the fallback withdraws without a
release of this extension.

The fallback declares no editor action at all: a declaration must name at
least one record type (:php:`EditorAction` refuses an empty list), and a
record type is a table a writer truly owns. So it is not offered from a
record's context menu, only through the assistant, and
:php:`EditorAction::$recordTypes` stays a list of owned tables.

.. _adr-197-consequences:

Consequences
============

✓ Every extension table with scalar fields can be written by the assistant on
the day the extension is installed, under the user's own rights, hidden and
behind an approval.

✓ The exclusions are one list in one class and are reviewed once, which is
the review model of ADR-135 applied to the boundary rather than to each
field.

✕ The preview shows arguments the tool cannot interpret beyond the TCA
label. An approver of a news record sees the fields, not what the record
means to the site.

✕ A table whose meaning lies in relations (categories, media, references)
comes out incomplete from this tool. That is intended: the incomplete draft
is hidden, and the relations are the case for a narrow writer.

✕ ``tables_modify`` and the page permission decide who may use the fallback;
an editor allowed to create records in a table through the backend form may
now do so through the assistant too. That is the rights model, not an
extension of it.

✕ Default language only. A translation of a record in a table the fallback
serves is a backend job.

.. _adr-197-revisit:

Revisit when
============

An extension ships a writer for a table the fallback served, and the
withdrawal does not happen as described. Or a table with scalar fields only
turns out to carry meaning the DataHandler cannot guard — then it goes on the
deny-list, and this record gets the reason. Or a translation is wanted for a
table the fallback serves — the language then needs a rule of its own, as
:ref:`ADR-193 <adr-193>` gave ``tt_content``, and this record gets it.
