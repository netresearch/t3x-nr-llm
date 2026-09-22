.. include:: /Includes.rst.txt

.. _adr-194:

============================================================================
ADR-194: Two more metadata fields, one of them a select
============================================================================

:Status: Accepted
:Date: 2026-09-21
:Amends: :ref:`ADR-135 <adr-135>` (the field allow-list of ``update_page_metadata``), :ref:`ADR-192 <adr-192>` (the fields of ``update_fal_asset_meta``)
:Authors: Netresearch DTT GmbH

.. _adr-194-context:

Context
=======

A live editorial run on the Netresearch demo installation (NEXT-158) ended with
two fields an editor could not set through the assistant: the Twitter card
type of a page and the copyright notice of an image. Both are descriptive
metadata in the sense of :ref:`ADR-135 <adr-135>` — neither decides where a
URL points, who sees a record or who may edit it — and both were simply absent
from the two writers that own their tables.

The two fields differ from every field the writers had so far.

``pages.twitter_card``
   is a ``select``. EXT:seo declares its items — the empty string, ``summary``
   and ``summary_large_image`` in the versions this repository supports — and
   an installation may add to them. The DataHandler does not compare a static
   select's value with its items: without a check any string up to the length
   bound would be stored, and the backend form would then show it as an invalid
   value. A length bound, which is all the writer applied so far, says nothing
   about a select.

``sys_file_metadata.copyright``
   is not core's. It belongs to EXT:filemetadata, and without that extension
   neither the TCA column nor the database column exists. ``title`` and
   ``description``, the two fields :ref:`ADR-192 <adr-192>` gave the writer,
   are core's and always there.

.. _adr-194-decision:

Decision
========

``twitter_card`` joins the allow-list of ``update_page_metadata``. The list is
still intersected with the live TCA, so an installation without EXT:seo is not
offered the field. For a ``select`` column the writer reads the ``items`` the
live TCA declares at call time and refuses a value that is not one of them,
naming the allowed values in the refusal. The empty string is allowed where an
item declares it, and only there; a divider is an entry of ``items`` and not a
value. The allowed values also appear in the argument's description on the
wire, so a model can choose before it calls. They do not appear as a JSON
schema ``enum``: the empty string is a legitimate value, and a provider is known
to reject a function declaration whose ``enum`` holds one.

``copyright`` joins the fields of ``update_fal_asset_meta``, where the live TCA
declares the column. Where it does not, the field is neither offered in the
tool's specification nor accepted: a call naming it is refused as the unknown
argument it is, and the read-back query never names a column the database may
not have. The field is handled exactly as ``description``: measured in
characters against the tool's own bound of 2000, the TCA ``max`` winning where
an installation declares one; an empty string clears it; a field left out keeps
its value; the field-level grant is checked before anything is written, and a
missing grant refuses the whole call.

Nothing else changes: one record per call, live workspace, default language,
the approval pause, the neutral refusal for a record the acting user may not
reach.

.. _adr-194-not:

What stays excluded
===================

``og_image`` and ``twitter_image`` stay out of ``update_page_metadata``. They
are file references, the risk class :ref:`ADR-135 <adr-135>` keeps out of a
scalar writer; a writer of their own is the subject of a separate record.

.. _adr-194-consequences:

Consequences
============

✓ The two gaps the demo run named are closed within the writers that own the
tables, under the review those writers already had.

✓ A select value is checked against what the backend form itself offers, so the
tool cannot store what an editor could not pick.

✕ The writer now carries one column-type-specific check. A further select on
the allow-list gets it for free; a further column type (``check``,
``datetime``) would need its own.

✕ The functional tests of both writers need EXT:seo and EXT:filemetadata
loaded, which adds both as development requirements of this repository.

.. _adr-194-revisit:

Revisit when
============

A third column type is proposed for either allow-list, or a select on the list
gets ``itemsProcFunc`` or a ``foreign_table`` — dynamic items the static read
cannot see.
