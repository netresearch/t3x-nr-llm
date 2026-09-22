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
anything is written. The read-back stays as the backstop, and when it fails
it **takes the write back**. The row on the named page, field and file and a
page counter of one are checked before the replaced references are deleted,
so the page can be put back as the call found it: the new reference is
deleted and the previous ones, still live, are written back into the page's
field. That the field holds exactly one live default-language reference is
checked after the cmdmap, where whatever else is live is a previous reference
the delete did not remove; the new one is taken back the same way, and of the
list written back the DataHandler relates only the rows still live (measured
on the fixture with a partly removed list). The first version reported the
mismatch and left the new row in place, arguing that a repair would have to
guess which of two rows to keep — it does not, because the plan names the
rows that were there.

**What the put-back guarantees, and on which page.** The default-language
page: after the put-back the tool reads it again and reports from what it
holds, not from what the DataHandler said — the new reference and the copies
core minted of it are no longer live, and the page counts exactly the
default-language references that are. Where that is not the case the message
says what is still there, never "left as it was". The translation is not
verified. Writing the field back makes core's :php:`DataMapProcessor`
synchronise every parent-following translation, and where the translation no
longer holds a copy of a survivor — an administrator's dropped run deletes
those copies; an editor's cannot, because the delete is checked against
``CONTENT_EDIT`` without the page in that run's datamap — core issues a
``localize`` command for it. That command needs the translation's language on
the page's site and refuses for a deleted record, and when it refuses core
throws out of the datamap (``RuntimeException`` 1486233164; the first version
of the put-back let it escape with the new reference still live). Each half
of the put-back therefore runs under its own guard, the delete runs whatever
the datamap did, and whatever core threw or logged is named in the message.
Measured on the fixture: an editor's failed replace on a translated page
leaves both pages as they were; an administrator's re-mints the translation's
copies where the site declares the language, and where it does not the
translation is left counting references it no longer holds, which the message
reports and the tool does not repair.

**The counter's limit is this tool's steady state, not an edge.** The tool
leaves every field it sets at exactly one reference, so every replace on a
field it set before has a previous count of one, and a page side dropped on
such a replace leaves the counter at 1 — the read-back cannot tell that stale
count from the new one. On a translated page the second signal catches it: a
parent-following translation gets a copy of the new reference in the same
run, moved onto the translation's uid when the translation's page row is
written, so a copy still on the default-language page means the translation's
side was dropped too — the cmdmap would then delete the copy the translation
still holds and leave it counting an image it does not have. The read-back
treats such a copy as a mismatch and both pages go back to what they held. On
a page without translations no copy is minted, and there a dropped side at a
previous count of one stays undetectable: the run ends with the new row as
the single live, counted reference, which is the state asked for. The only
stricter signal would be the DataHandler's own history of the page row, which
the tool does not read.

Whether a column is subject to that grant, and whether it is dropped for a
different reason, is decided with the DataHandler's own predicates
(:php:`DataHandler::fillInFieldArray()`, ``typo3/cms-core`` 14.3.7 lines
1118–1123; :php:`DataHandler::getExcludeListArray()` in 13.4.21 is the same
pair). Core reads the flag as ``(bool)($config['exclude'] ?? false)``
(:php:`AbstractFieldType::supportsAccessControl()`), so an installation's
``'exclude' => 1`` puts the column under the grant, and it skips a column
whose ``displayCond`` is exactly the string ``HIDE_FOR_NON_ADMINS`` for every
non-admin, grant or no grant — also in silence. The first version of the tool
tested ``=== true`` and did not know the second shape; a review measured both
against the running DataHandler and found the reference row created and the
page's side dropped, with the pre-check passed. Both predicates are now
mirrored, and the second refusal names the display condition rather than a
grant the editor holds. The TCA is read from ``$GLOBALS['TCA']`` as the
sibling writers read it (:ref:`ADR-192 <adr-192>`): core compiles its schema
from that array, so both sides see the same shape without a second dependency
on the schema factory.

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

**A translation is saved with the page, in whatever language it is in.**
:php:`DataMapProcessor::finishTranslationItem()` (``typo3/cms-core`` 14.3.7
line 417; 13.4.26 line 375) puts every live translation of the page into the
datamap with its ``l10n_state`` — hidden ones too, and whether or not the
field being written is in the ``parent`` state — and
:php:`DataHandler::process_datamap()` then checks that record against the
acting user's ``allowed_languages`` (14.3.7 line 898 through
:php:`checkRecordEditAccess()`; 13.4.26 lines 895–896 through
``recordEditAccessInternals()``) and refuses it: "Language was not allowed".
The default-language row itself was writable, so an editor allowed the
default language alone is refused on every page that has a translation —
after the reference row is written, at the cost of a discard and two error
rows in ``sys_log``, and with a preview that showed nothing wrong, because the
preview never reaches the DataHandler. Measured on the fixture: the plain and
the replace call both came back with that message and nothing written.

The decision is to refuse **before** the write, in :php:`plan()`, when the
page has a live translation in a language the acting user may not edit. The
refusal names the translation, its language and the synchronisation that is
the cause, and the approval card shows it because :php:`plan()` is the
preview's too. Two alternatives were rejected. Accepting the DataHandler's
per-record independence and reporting the default-language write as done would
leave the translation without its synchronised copy of the reference, behind
a parent it is declared to follow. Restricting the pre-check to translations
whose field is in the ``parent`` state would refuse less, but core does not
make that distinction — the ``l10n_state`` record is written for every
translation — so the pre-check would let through exactly the calls the
DataHandler still refuses.

**A translation carries its own permission bits, and the same run asks
them.** The DataHandler checks ``PAGE_EDIT`` on every translation it saves
with the page (:php:`DataHandler::hasPermissionToUpdate()` for ``pages``,
14.3.7 line 7470; :php:`checkRecordUpdateAccess()` in 13.4.26 asks the same
bit) against the translation's own ``perms_*`` columns. Those can differ from
the parent's: core creates a translation as a new page row
(:php:`DataHandler::localizePage()`, 14.3.7 lines 5034–5063), which takes the
new-page permission defaults, and nothing synchronises ``perms_*`` from the
parent afterwards — ``pages`` declares no TCA column for them. An editor who
holds ``PAGE_EDIT`` on the page but not on its translation was therefore
refused after the reference row existed, and the put-back's own datamap made
core add the translation again, so the message carried the same complaint
twice. A review measured it on the fixture; the functional test pins it with
the translation's group and everybody bits at ``PAGE_SHOW``, because the
fixture grants everybody ``ALL`` and :php:`calcPerms()` ORs the two. The
pre-check above asks
:php:`doesUserHaveAccess()` with ``PAGE_EDIT`` on each translation — the call
:php:`plan()` already makes for the page — and refuses naming the translation,
with the language check first where both would fire.

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

✕ An editor whose ``allowed_languages`` leaves out a language the page is
translated into, or who holds ``PAGE_EDIT`` on the page but not on one of its
translations, cannot set the image on that page, although the
default-language row is theirs to edit: core saves the translation with the
page. The refusal names the translation, and the language where that is the
cause; an editor allowed that language and that translation, or an
administrator, can.

.. _adr-195-revisit:

Revisit when
============

A writer for ``media``, or for any page relation that IS a list, is proposed:
then the appending contract of ``attach_file_to_content_element`` is the shape
to generalise, and the single-image rule above must not be copied.

Also revisit if ``typo3/cms-seo`` ever declares ``maxitems`` on these columns:
the rule this record chose would then be the TCA's, and the tool should read
it rather than restate it.
