.. include:: /Includes.rst.txt

.. _adr-196:

============================================================================
ADR-196: The element draft offers the TCA's content types
============================================================================

:Status: Accepted
:Date: 2026-09-21
:Amends: :ref:`ADR-146 <adr-146>` (its fixed type list and field set)
:Authors: Netresearch DTT GmbH

.. _adr-196-context:

Context
=======

:ref:`ADR-146 <adr-146>` shipped ``create_content_element_draft`` with a
static allow-list of four content types — ``header``, ``text``, ``textmedia``,
``bullets`` — intersected with the live TCA, and a fixed field set: headline,
body, column, language, position. Both were deliberate: the tool was the first
writer that brings a record into being, and the smallest commitment was the
right first shape.

A live editorial run on the Netresearch demo showed the cost. Its site
package declares twenty-six content types; the tool could create four of
them, and an assistant asked for a table, a quote or a card element had to
answer that it could not. Eighteen of the twenty-six carry relations or a
FlexForm and are out of reach for a draft tool whatever the list says; the
remaining ones are prose elements with a few scalar options — a select, a
check, an input — that the fixed field set could not fill either.

:ref:`ADR-135 <adr-135>` argued against a generic record editor because a
narrow tool moves the decision from runtime to review time: what the tool can
do wrong is bounded by its allow-list, and the allow-list is in the diff. That
reasoning is not in question here. The question is whether the bound has to
be a list of names, or whether a rule that is reviewed once can bound the
tool just as well while following the installation's TCA.

.. _adr-196-decision:

Decision
========

The table stays fixed: ``tt_content``, one hidden element per call, through
the DataHandler as the acting user, with the approval pause, the preview and
the read-back of :ref:`ADR-146 <adr-146>` unchanged. What changes is where
the type list and the field list come from.

**The offered types are read from the live TCA at call time.** Every item of
``tt_content.CType`` is offered unless one of three things excludes it:

- it is on the **deny-list** — ``list`` (the legacy plugin element), ``html``
  (raw output), ``shortcut`` (a record reference), ``div`` (a divider) and
  every ``menu_*`` type. They reference records or pages, or run code, and
  the deny-list is asked by name before the form is read, so ``html`` stays
  out of reach on an installation where its form happens to be scalar;
- it is a **plugin**. Since v13 a plugin is a content type of its own,
  registered through ``ExtensionManagementUtility::addPlugin()``, which puts
  the item in the group its caller names and, where no FlexForm is given,
  copies ``header``'s scalar form onto the type — so the form alone would
  offer it. ``registerPlugin()`` defaults to the ``plugins`` group, but
  core's own indexed_search, felogin and form register into ``forms``, and
  indexed_search's ``indexedsearch_pi2`` has no FlexForm. A type is therefore
  a plugin when its item sits in the ``plugins`` or the ``forms`` group, or
  when Extbase registered it: ``ExtensionUtility::configurePlugin()`` records
  every plugin under
  ``$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['extbase']['extensions'][<Extension>]['plugins'][<Plugin>]``
  and derives the content type as ``strtolower(<Extension> . '_' . <Plugin>)``
  — the same keys and derivation in ``typo3/cms-extbase`` 14.3.7 and 13.4.35;
- its **form holds an excluding column**. The form is the type's ``showitem``
  with every palette expanded — after core's ``TcaPreparation`` has added the
  general, language, hidden and access palettes — and each column is
  classified by its TCA type, with the type's ``columnsOverrides`` applied.

Three kinds of column, and the kind decides:

``fillable``
   ``input``, ``text``, ``check``, ``number``, ``datetime``, ``radio``,
   ``color``, ``email``, and ``select`` with static items and no
   ``foreign_table``. A model may set these through ``fields``.

``unfilled``
   ``file``, ``category``, ``link`` and ``language``. These are the relations
   core attaches to prose — ``categories`` sits on every content type,
   ``header_link`` in the header palette every type uses, ``assets`` on
   ``textmedia`` — and they do **not** exclude the type. The draft leaves them
   empty; ``attach_file_to_content_element`` or a human fills them later.
   This is the rule that keeps ``textmedia`` offered exactly as before.

``excluding``
   Everything else: ``flex`` (a FlexForm is configuration, not prose),
   ``inline`` (child records), ``group`` and ``folder`` (record and folder
   references), a ``select`` backed by a ``foreign_table``, ``slug``,
   ``password``, ``uuid``, ``json``, ``passthrough``, ``user``, ``none``,
   ``imageManipulation`` — and every TCA type this tool does not know, so a
   new type fails closed rather than open. One excluding column excludes the
   type. A type without a form is excluded too.

**The argument set keeps its seven arguments and gains ``fields``**, an object
of column names to values. A key is accepted when it is a fillable column of
the chosen type's form and not one of the columns the tool refuses by name:
identity (``uid``, ``pid``, ``CType``, ``sorting``, ``tstamp``, ``crdate``,
``deleted``, ``t3ver_*``), position (``colPos``), visibility (``hidden``),
publication and audience (``starttime``, ``endtime``, ``fe_group``,
``editlock``) and translation topology (``sys_language_uid``,
``l18n_parent``, ``l10n_source``, ``l18n_diffsource``) — the exclusions
ADR-135 made for pages, by analogy — plus ``header`` and ``bodytext``, which
are arguments of their own. The ``bodytext`` argument follows the same rule
as a key: it is refused for a type whose form does not show the column, where
the DataHandler would still write it under the column's base config.

Three kinds of fillable column stay in the form and are still refused as keys,
because the DataHandler bends them by a rule the draft cannot vouch for: a
``check`` with several items is a bitmask, and ``1`` would set only its first
bit; a ``check`` with ``eval`` ``maximumRecordsChecked`` or
``maximumRecordsCheckedInPid`` is unchecked again once enough other records
carry it; an ``input`` or ``email`` with ``eval`` ``unique`` or
``uniqueInPid`` is rewritten to a value no other record holds. They do not
exclude the type; the draft leaves them at their default.

A value is validated against the column's TCA type, the way the DataHandler
reads it: a select against its static items, and against the acting user's
``explicit_allowdeny`` where the column declares ``authMode`` — for any
select, not only ``CType``; a check as a boolean; a number as a whole number
(or a decimal where the column says so, with at most the two places the
DataHandler stores) within the TCA range, compared as the DataHandler
compares it — a decimal first rounded to two places, then rounded up against
the upper bound and rounded down against the lower — so a decimal inside a
fractional bound that the DataHandler would clamp is refused; a datetime as
anything PHP reads; an
input or text within the TCA ``max`` or the tool's own bound, and an input or
a text without the RTE not below the TCA ``min``, a bound given as a numeric
string counting as the integer; a colour with an alpha pair only where the
column declares ``opacity``; an email as a valid address. The first wrong key
or value refuses the whole call before anything is written, and the refusal
names the columns the type does offer.

**Page TSconfig narrows the form per page, and the tool honours it.**
``TCEFORM.tt_content`` is applied by FormEngine only — the DataHandler stores
a type or an item the form would not have offered. After the page is
authorised, and so after the neutral refusal, the tool reads the page's
TSconfig as FormEngine reads it (``BackendUtility::getPagesTSconfig()``, with
``<column>.types.<CType>.`` merged over ``<column>.``) and refuses a type that
``CType.keepItems`` or ``CType.removeItems`` takes out of the selector, a
``fields`` key, a body or a header whose column ``disabled`` hides — the
header is written on every call, so a hidden header refuses every call of
that type on that page — and a ``select`` value, a ``column`` or a
``language`` that the ``keepItems`` or ``removeItems`` of its column (for the
last two ``colPos`` and ``sys_language_uid``, which FormEngine filters in
``TcaSelectItems`` and ``TcaLanguage``) removes; the refusal names the rule.
``addItems`` is not read. Like FormEngine, it does not apply the item rules to
``radio`` or ``check``. Core itself ships one such rule —
``TCEFORM.tt_content.imageorient.types.image.removeItems = 8,9,10,17,18,25,26``.
The type list in the tool description is the TCA-level set and does not
depend on a page; TCEFORM narrows it per page at call time.

**A ``datetime`` is handed to the DataHandler as the integer both supported
cores store verbatim** — a Unix timestamp, or seconds of the day on a ``time``
column. A string is not that shape: 13.4's ``DataHandler`` reads it as UTC
wall time and subtracts the server's offset, so a day given as ``2026-09-21``
would land on the evening before on any server outside UTC, while 14.3 reads
an offset correctly. A column with a native ``dbType`` takes unqualified local
wall time, which both cores store in the column's own format — the date, the
time, or both — without shifting it. The approval card is the human gate of
:ref:`ADR-136 <adr-136>`, and a timestamp is not readable, so the card shows
the moment the integer stands for in the server's zone — ``2026-09-21
00:00:00``, or ``14:30:00`` for seconds of the day — not the integer.

**The exclude-field grant is asked before the write**, per column, the way
:ref:`ADR-192 <adr-192>` asks it: the DataHandler drops an ``exclude`` column
the acting user holds no ``non_exclude_fields`` grant for in silence and
creates the element without it, which is half of an approved draft. It is
asked for the columns the tool writes itself as well — the hidden column,
``header``, ``bodytext``, and ``colPos`` and ``sys_language_uid`` where they
differ from the ``0`` the silence would leave; core marks the hidden and the
language column ``exclude``. ``exclude`` is read as a boolean cast, as core's
schema reads it, so an extension's integer ``1`` counts. Before the grant, the
chosen type is checked against the acting user's ``explicit_allowdeny`` where
``CType`` declares ``authMode``: the DataHandler drops a disallowed type in
silence and creates the element as the default type. The read-back then
checks every column the call set, position and language included; a column
that still did not take is reported and the element is deleted again, exactly
as a visible element is. The report names both causes it cannot tell apart: a
value rewritten by TYPO3 under a rule this tool does not check, and a missing
grant. Three kinds are checked for presence rather than
equality, because the DataHandler rewrites them on purpose — a ``datetime`` is
normalised to its ``format`` and clamped to its ``range``, a ``text`` column
with ``enableRichtext`` passes through the RTE transformation, and an
``input`` with an ``eval`` beyond ``trim`` is transformed by it; a decimal is
compared as a number, because the database renders ``12.00`` as it likes.

**The wire shape stays small.** The description lists the types that pass
the exclusion rule, as before, says that the page's TSconfig may narrow them
and their options, and that ``fields`` takes further scalar columns of the
type.
The JSON schema declares ``fields`` as an object with free keys, in the
boolean ``additionalProperties`` form ``read_records`` ships — a type array
is a union type, which Gemini's schema dialect does not express — and its
description names the scalar kinds a value may take and where the allowed
keys come from. It does not enumerate the columns of every type: a model
that wants them reads the TCA through the structure tools, or sends a wrong
key once and is told.

.. _adr-196-bound:

Why a rule bounds the tool as well as a list did
================================================

ADR-135's argument was never that the list had to be short; it was that what
the tool can do wrong must be readable in the diff and reviewed once. A rule
satisfies that when its exclusions are explicit and finite, and here they are:
four type names, one prefix, two item groups and the Extbase plugin
registration; twelve column types, the record-backed ``select`` and every
type the tool does not know; three fillable kinds refused as keys; seventeen
column names and one prefix, the disabled column under whatever name
``ctrl.enablecolumns`` gives it, plus the two arguments of their own; and the
page's TCEFORM rules. Everything a model can reach
is a scalar column of a prose element on one table, with the element hidden.
The blast radius is bounded by the exclusions, and the exclusions are what a
reviewer reads.

What the rule buys over the list is that it follows the installation. The
demo's site package, a client's own elements, the next core version's new
type: each is offered or excluded by the same rule, without a release of this
extension, and an installation can read the rule's outcome in the tool's
description.

What it costs is stated below.

.. _adr-196-excluded:

What stays excluded, and why
============================

Plugins, raw HTML (``html``), shortcuts, dividers and menus
   Their payload references records or pages, or runs code. An assistant that
   can place a plugin can place any plugin; an assistant that can write raw
   HTML can write a script. The legacy ``list`` element, ``html``,
   ``shortcut``, ``div`` and the menus are denied by name, whatever the form
   says. A plugin content type is excluded by its Extbase registration or by
   the ``plugins`` or ``forms`` item group: without a FlexForm its form is
   ``header``'s and would pass the column rule.

FlexForm columns
   Configuration, not prose, and its schema depends on a pointer field the
   tool would have to resolve. A type carrying one is excluded.

Inline children, group and folder references, record-backed selects
   They create or reference other records. The one-record rule of ADR-146 is
   what makes every preview readable and every refusal whole; a type whose
   form needs children is a batch by another name.

File references as arguments
   A ``file`` column does not exclude a type, and it is never an argument:
   the relation is a different risk class than a scalar, and
   ``attach_file_to_content_element`` exists for it, with its own
   authorisation on the file side.

Links and categories as arguments
   Same shape as files: they do not exclude, and they are not filled. A link
   decides where a visitor is sent; a category is a reference to a record.

Slugs and passwords
   A slug decides routing; a password is a credential. A content type carrying
   either is not a prose element.

Access and publication columns
   ``hidden``, ``starttime``, ``endtime``, ``fe_group``, ``editlock``.
   Publication and audience are not editorial, and ``hidden`` is the one value
   this tool exists to set itself.

.. _adr-196-consequences:

Consequences
============

✓ Every scalar content type an installation declares can be drafted, with its
options, through one tool — where four could be.

✓ The exclusions are in one place and are read once: the constants at the
top of the tool, quoted above.

✓ ``textmedia`` is offered as before, media attached later; ``textpic``,
``image`` and core's ``table`` become reachable on a stock installation.
ADR-146's list had ``header``, ``text``, ``textmedia`` and ``bullets`` only.

✕ The description a model receives is now installation-specific, and so is
the set of columns a call may carry. Two installations running the same
extension version offer different things, and a prompt written against one
may not transfer.

✕ A ``select`` with an ``itemsProcFunc`` is validated against its static
items only; a value the processor would have added is refused.

✕ A ``check`` with several items is a bitmask the tool does not set; it is
refused as a key, and the draft leaves it at its default.

✕ A ``datetime``, an RTE-enabled ``text`` column and an ``input`` with an
``eval`` beyond ``trim`` are verified for presence, not for value. A column
the DataHandler clamped to its range reads back as present.

✕ A plugin that is not an Extbase plugin — registered through
``ExtensionManagementUtility::addPlugin()`` directly — and whose item sits in
a group other than ``plugins`` or ``forms`` is judged by its form alone:
excluded when it carries a FlexForm, offered when it does not. Nothing in the
TCA marks such a type as a plugin.

✕ ``category`` and ``link`` columns were meant to exclude a type and cannot:
core's own TCA puts ``categories`` on every content type and ``header_link``
in the header palette every type uses. Treating them as unfilled is the only
rule under which the four original types remain offered.

.. _adr-196-revisit:

Revisit when
============

An installation asks for a type the rule excludes and the request is
legitimate — a ``select`` with a ``foreign_table`` that is a configuration
table rather than content, or a ``check`` with several items. The answer is
then a narrower classification, not a wider one.

Also revisit if a ``fields`` column is reported as not taken in practice after
the grant check passed. The read-back is the backstop for a silence the grant
check is meant to end; a case that reaches it names a second silence.

And when a second table wants the same shape. The rule is written for
``tt_content`` and its system columns; a generic record editor is still the
question :ref:`ADR-135 <adr-135>` declined, and this record does not reopen
it.
