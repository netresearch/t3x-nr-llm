.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-326:

============================================================================
ADR-326: A draft takes FlexForm values and computed items
============================================================================

:Status: Accepted
:Date: 2026-11-24
:Amends: :ref:`ADR-324 <adr-324>` (simple FlexForm fields, and selects of child
    records with computed items)
:Authors: Netresearch DTT GmbH

.. _adr-326-context:

Context
=======

:ref:`ADR-324 <adr-324>` left two things empty and named both as the next step:
the values of a FlexForm, which got the data structure's defaults, and a
``select`` whose items come from an ``itemsProcFunc``. The ticket asks for the
icon of an icon group's item, and the product owner decided on 2026-11-24 that
the component loads the icon choice and that simple FlexForm fields can be set.

.. _adr-326-decision:

Decision
========

**A child record's processed select is fillable.** It is a ``selectSingle``
with an ``itemsProcFunc`` and no ``foreign_table``. Only child records: the
columns of ``tt_content`` itself keep the rule they had, so
``update_content_element`` is unchanged. TYPO3 14's ``itemsProcessors`` are not
run; such a column stays empty.

**The items are computed for the record that is drafted.** The processor gets
the row as the backend form hands it over, a select value as a list, with the
record's other values; processed selects are checked last and in the form's
order, because the items of one can depend on another. The acting user is the
ambient user while the processor runs. The
tool checks the page's permission before, and the grants of the columns a
record names; a caller of ``dataMap()`` checks the page itself. Choices the
page's ``TCEFORM`` or ``authMode`` take away are not offered.
Core's ``ItemProcessingService`` is not used: on TYPO3 13.4 it turns an
exception into a flash message and returns the unprocessed items. A processor
that fails refuses the value by saying that the choices could not be computed.
The DataHandler does not check a select against its processor, so this check is
the only one.

**A model chooses by name.** bootstrap_package's icon values are file paths and
its two sets hold 801 icons. An item's label names it where the label is plain
text and unique; the stored value is accepted too. The tool description marks
such a column (``~``) without its items. A name that does not exist is answered
with the nearest ones. ``ContentElementComposer::describe()`` lists the names:
``items``, or ``itemsBy`` (the earlier select they depend on, its default, and
the names per choice). ``itemsPartial`` marks a list that may leave choices out:
two selects decide, or one has more than 50 choices.
A column whose processor fails is not described.

**Simple FlexForm fields are fillable** through ``fields``, as
``{FlexForm column: {field: value}}``: a single ``input``, ``text`` without
RTE, ``select`` with static items, ``check``, ``number``, ``radio``, ``color``,
``email`` or ``link`` the column rule of :ref:`ADR-196 <adr-196>` counts as
fillable, whose name occurs once in the data structure. Sections, references,
dates and rich text keep the default. Values are checked like columns, written
over the defaults, shown on the approval card and read back one by one.

**A FlexForm field's own restrictions fail closed.** The DataHandler asks the
grant of the FlexForm column only. A field that declares ``exclude`` is for
administrators. A ``TCEFORM`` rule (``disabled``, ``config.readOnly``,
``keepItems``, ``removeItems``) for the sheet and field counts whatever data
structure key it is written under, and so does ``disabled`` on the sheet.

.. _adr-326-consequences:

Consequences
============

● On bootstrap_package 16.0.1 an icon group, a timeline and a card group are
drafted with icons, and card group, icon group, timeline and carousel with
their FlexForm options. Measured with its TCA on TYPO3 14.3: 200 Glyphicons and
601 Ionicons, each set with its "none" item.

✕ ``describe()`` of a type with icons grows to about 14 KB of JSON. A caller
that lists several such types in one prompt carries the names several times.

✕ A non-administrator cannot set a FlexForm field with ``exclude``, even with
core's grant for that field.

✕ A processed select placed before the one it depends on gets the choices for
that one's default. Its form would be reloaded by the editor; a draft is not.

✕ The processor's row holds the record's own columns. The relation, visibility
and reference columns and FormEngine's ``inlineParent*`` parameters are not
passed, and the site of a page that exists in a draft workspace only may not be
found. A processor that needs one of them can fail or offer other choices.

✕ ``describe()`` has no page: a processor that reads the page or its TSconfig
describes the choices of page 0, while ``dataMap()`` checks those of the page.

✕ A FlexForm select with an ``itemsProcFunc`` or a ``foreign_table`` stays at
its default, such as bootstrap_package's "open item" of an accordion.
