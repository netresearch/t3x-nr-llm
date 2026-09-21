.. include:: /Includes.rst.txt

.. _adr-195:

============================================================================
ADR-195: The ninth writer sets a page's social image, and stays out of the first's allow-list
============================================================================

:Status: Accepted
:Date: 2026-09-22
:Amends: :ref:`ADR-135 <adr-135>` (two of its three excluded FAL fields get a writer of their own)
:Authors: Netresearch DTT GmbH

.. _adr-195-context:

Context
=======

:ref:`ADR-135 <adr-135>` kept ``og_image``, ``twitter_image`` and ``media`` out
of ``update_page_metadata`` because they are FAL relations —
``sys_file_reference`` rows written through the DataHandler's relation
handling, a different risk class than setting a scalar. That reasoning stands.
It also left the social preview image of a page with no writer at all: an
assistant asked to prepare a page for sharing can set its Open Graph title and
description and must then tell the editor to pick the image by hand.

The two columns are EXT:seo's. In ``typo3/cms-seo`` 14.3.7
(``Configuration/TCA/Overrides/pages.php``) each is ``'type' => 'file'`` with
``'allowed' => 'common-image-types'``, ``'exclude' => true`` and
``allowLanguageSynchronization``; the same declaration is in every 13.4 and 14
copy read for this record (13.4.21 to 14.3.7). Without the extension the
columns do not exist.

The obvious moves are to widen ``update_page_metadata`` or to widen
``attach_file_to_content_element`` (the seventh writer, `#865`; it has no
record of its own, and :ref:`ADR-184 <adr-184>` describes it as the tool that
appends a file reference to a content element's file fields). This record
says why neither is taken, and what the ninth writing tool does instead.

.. _adr-195-decision:

Decision
========

``set_page_social_image`` is the ninth writing tool, on the terms of the
previous eight: disabled by default, in the ``editing`` group, an explicit
:php:`ToolEffect`, a human approval before every call
(:ref:`ADR-134 <adr-134>`), a preview at suspend (:ref:`ADR-136 <adr-136>`)
that is a pure function of the arguments and the current state
(:ref:`ADR-184 <adr-184>`), a write through the ``DataHandler`` under the
acting user's permissions (:ref:`ADR-083 <adr-083>`), a read-after-write
verification, an editor-action declaration (:ref:`ADR-152 <adr-152>`) on
``pages``, and a refusal vocabulary that never confirms a uid exists. It uses
the :ref:`ADR-146 <adr-146>` ``plan()`` shape.

It takes a page uid, a ``field`` that is exactly ``og_image`` or
``twitter_image``, and a ``sys_file`` uid, and it creates exactly one
``sys_file_reference`` on that page and field. It never uploads, moves or
renames a file.

.. _adr-195-narrow:

A ninth tool rather than a wider first or seventh
=================================================

``update_page_metadata`` sets scalars; every entry in its allow-list is an
``input`` or ``text`` column and its whole refusal vocabulary is about field
names and lengths. A relation in that list would give one tool two datamap
shapes and two read-backs, and the approval card of "set the description" would
have to be read for whether it also deletes a reference. The ADR-135 exclusion
is kept, and its sentence is narrowed to say where the two image fields went.

``attach_file_to_content_element`` is about content elements by name, by
arguments (``element``, ``field``) and by its editor-action declaration on
``tt_content``. Appending is its contract: it adds a file to a list and never
removes one. A social preview is not a list, and an appending writer on a page
field would hand the "which image wins" question to whoever renders the page.

So the two writers that touch ``sys_file_reference`` are disjoint by the
parent table, as the two ``sys_file_metadata`` writers are disjoint by field
(:ref:`ADR-192 <adr-192>`), and the newer one names the older on the wire, so
a model told "not here" is not left to guess where. ``media`` stays without a
writer: it is a list by design and nothing renders it as a single image.

.. _adr-195-one-reference:

One reference per field is this tool's rule, not the TCA's
==========================================================

The brief for this tool assumed that ``typo3/cms-seo`` declares ``maxitems`` on
the two columns. It does not, in any version read: the DataHandler accepts a
list of references there, and EXT:seo's :php:`MetaTagGenerator` renders every
``og_image`` reference as its own ``og:image`` (the Open Graph manager allows
multiple occurrences) while ``twitter:image`` is rendered once.

The tool nevertheless keeps each field at **at most one live default-language
reference**, and that is a decision recorded here rather than a constraint read
from the TCA. A social preview is one image, the Open Graph protocol gives the
first of several ``og:image`` tags preference, and a card that says "adds a
second image" asks the approver to know which of two a platform will pick. The
tool therefore does not read ``maxitems`` at all: a check that can never
change what happens when the field always ends at exactly one reference would
be a declaration nothing reads. An installation that declares ``maxitems``
itself is satisfied by construction for any value of one or more.

.. _adr-195-replace:

``replace`` is explicit and destructive, and says so
====================================================

When the field already holds a reference the call is refused and the refusal
names the file referenced now. ``"replace": true`` is the only way past it,
and it **deletes** that reference through the DataHandler — recoverably
(``deleted = 1``) and in ``sys_log`` — in the same run that creates the new
one. This mirrors ``create_translation_draft``'s ``overwrite``
(:ref:`ADR-146 <adr-146>`): the destructive step is a word the approver reads
on the card, never a default, and the preview gives it a line of its own.

The alternative — replacing silently, because "set the image" plainly means
the new one should show — was rejected for the reason the refusal vocabulary
exists: a call that discards something must say what it discards, and a model
that did not know an image was there must hear that one is.

The effect is :php:`ToolEffect::NON_IDEMPOTENT_WRITE`. Without ``replace`` a
second run refuses, so a reaped run that already succeeded would report
failure for a write that happened; with it, a second run discards a reference
an editor may have set between the two attempts. Neither is a repeat an
at-least-once runtime may perform on its own.

.. _adr-195-one-run:

The page row travels with the reference, in one DataHandler run
===============================================================

Three facts about core decided the datamap's shape, and all three were read
in ``typo3/cms-core`` 14.3.7 rather than assumed.

**The permission the DataHandler asks depends on what else is in the
datamap.** :php:`DataHandler::hasPermissionToInsert()`,
:php:`DataHandler::hasPermissionToUpdate()` and
:php:`DataHandler::deleteRecord()` check a ``sys_file_reference`` row against
``PAGE_EDIT`` when the datamap also carries a ``pages`` row, and against
``CONTENT_EDIT`` otherwise. ``PAGE_EDIT`` is the right an editor of page
properties holds and the one ``update_page_metadata`` authorises against;
``CONTENT_EDIT`` is about the page's content. The tool therefore checks
``PAGE_EDIT`` itself, writes the page row and the reference row in one
datamap, deletes the replaced reference through the cmdmap of the **same**
run, and — when the DataHandler refuses part of the write — takes the orphan
back in one run that again carries the page row. Written as separate runs, the
delete would need a right the user who just passed the tool's own check does
not necessarily hold, and the orphan would stay. The functional test grants
the editors ``PAGE_EDIT`` without ``CONTENT_EDIT`` for exactly that reason.

**The field-level grant is asked before the write.** Both columns are
``exclude`` fields. For a user without the ``non_exclude_fields`` grant the
DataHandler creates the reference row and drops the page's side of the
relation in silence, with an empty ``errorLog`` — a reference EXT:seo never
renders, because :php:`MetaTagGenerator` reads the page's counter before it
looks for rows. The grant is asked through the same method the DataHandler
asks it with (:php:`BackendUserAuthentication::check('non_exclude_fields', …)`,
as :ref:`ADR-192 <adr-192>` does), and the whole call is refused before
anything is written. The read-back stays as the backstop: it requires the row
on the named page, field and file, exactly one live default-language reference
on that field, and a page counter of one.

**A translation that follows its parent gets its own copy, on its own uid.**
Both columns declare ``allowLanguageSynchronization``, and a translated page in
the ``parent`` state is synchronised by core's :php:`DataMapProcessor`: the
write mints a localized reference with ``uid_foreign`` set to the translated
page's uid and ``l10n_parent`` set to the new row, and the cmdmap delete of the
replaced default-language row deletes its localization with it. Measured, not
read: the probe that established it is the reason the tool's queries pin
``uid_foreign`` to the page and its read-back accepts exactly one row. The tool
writes default-language pages only and refuses a translation, naming its
default-language page — whether a translation follows its parent or carries
its own image is a page-properties decision the editor makes, not the tool.

.. _adr-195-availability:

Without EXT:seo
===============

The repository has no per-tool availability hook — :php:`ToolInterface` has no
conditional member and :php:`ToolAvailabilityServiceInterface` reports enable
state — so a tool whose columns are absent cannot withdraw itself from the
catalogue. It does what ``create_content_element_draft`` does with content
types: the ``field`` enum on the wire follows the live TCA, falls back to both
names rather than to an empty enum a model cannot satisfy, and a call names
EXT:seo in its refusal so the model stops retrying a field this installation
does not have.

.. _adr-195-consequences:

Consequences
============

✓ Nine editorial writes are available where eight were, and a page's social
preview image is writable at all.

✓ No writer on ``sys_file_reference`` shares a parent table with another, and
no field of ``pages`` has two writers, so every approval card answers for
exactly one tool.

✓ The ADR-135 exclusion keeps its reasoning; its sentence now says where two
of the three fields went.

✕ Preparing a page for sharing costs one approval per image plus one for the
texts, because the texts are ``update_page_metadata``'s. That is the trade
:ref:`ADR-180 <adr-180>` and :ref:`ADR-192 <adr-192>` made — one card, one
thing.

✕ A model that wants a second ``og:image`` — which the protocol permits and
EXT:seo would render — cannot get one from this tool.

✕ A non-admin who may edit the page but holds no ``non_exclude_fields`` grant
for the column is refused outright rather than told which administrator to
ask; the refusal names the grant, and that is as far as a tool can go.

.. _adr-195-revisit:

Revisit when
============

A writer for ``media``, or for any page relation that IS a list, is proposed:
then the appending contract of ``attach_file_to_content_element`` is the shape
to generalise, and the single-image rule above must not be copied.

Also revisit if ``typo3/cms-seo`` ever declares ``maxitems`` on these columns:
the rule this record chose would then be the TCA's, and the tool should read
it rather than restate it.
