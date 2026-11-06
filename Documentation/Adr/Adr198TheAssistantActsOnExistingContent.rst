.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-198:

============================================================================
ADR-198: The assistant acts on existing pages and content elements
============================================================================

:Status: Accepted (the rights ``replace_file_reference`` asks are widened to every record that holds an element as an inline child — see :ref:`ADR-230 <adr-230>`; rail 3 is relative to the run's workspace for ``update_content_element`` — see :ref:`ADR-260 <adr-260>` — and for ``delete_record``, ``copy_record`` and ``move_page`` — see :ref:`ADR-266 <adr-266>`)
:Date: 2026-09-23
:Amends: :ref:`ADR-135 <adr-135>` (its safety line against writers that update, publish or delete existing records, for ``pages`` and ``tt_content`` under the rails below), :ref:`ADR-184 <adr-184>` (its "beyond the excerpt" paragraph, for the two updating writers' field changes), :ref:`ADR-197 <adr-197>` (its "does not update or delete" paragraph, which stated that line)
:Amended: 2026-10-25 by :ref:`ADR-230 <adr-230>` (the owner chain of an inline child); 2026-11-04 by :ref:`ADR-260 <adr-260>` (rail 3 for ``update_content_element`` in a run started in a draft workspace: live rows and rows new in that workspace, never another workspace's); 2026-11-05 by :ref:`ADR-266 <adr-266>` (rail 3 likewise for ``delete_record``, ``copy_record`` and ``move_page``); 2026-11-06 by the amendment below (every writer reads back before it refuses)
:Authors: Netresearch DTT GmbH

.. _adr-198-context:

Context
=======

Every writer up to :ref:`ADR-197 <adr-197>` either creates a hidden draft or
changes one narrow, descriptive part of a record: page metadata, an
alternative text, a file reference appended, an element moved. None of them
changes what an existing element says, makes a record visible, or removes
one. :ref:`ADR-135 <adr-135>` drew that line on purpose — a model is
steerable by injected prose, so "the model would not do that" is not a
control — and :ref:`ADR-197 <adr-197>` restated it for the generic creator:
a wrong CREATE leaves a hidden record to delete, a wrong UPDATE overwrites
work.

NEXT-160 kept the line and named the gap: an assistant that can draft a page
cannot correct a typo in it, publish the draft a human has reviewed, or take
away an element that is wrong. It recorded update and publish as "a security
line, to decide separately". The product owner decided on 2026-09-23 to move
the line for the two tables editors work in. This record is that decision
and the rails it comes with.

.. _adr-198-decision:

Decision
========

Six builtin writers act on existing ``pages`` and ``tt_content`` rows. Each
is narrow — one act on one record — and every rail below is part of the
decision; removing one reopens ADR-135's argument.

``update_content_element``
   Sets columns of one content element. The field set is the one
   ``create_content_element_draft`` offers for a new element of the same
   type (:ref:`ADR-196 <adr-196>`), read from the live TCA of the element's
   ``CType`` with its ``columnsOverrides``: the scalar columns of the type's
   form (header, subheader, body text, header layout, a date, …), each value
   checked by the same rules before the write. An element whose type the
   exclusion rule leaves out — raw HTML, plugins, menus, shortcuts, a form
   holding a FlexForm or inline children — is refused whole. Relations,
   FlexForms and the identity, position, visibility, publication, audience
   and translation columns are never fields. The rules live in one trait
   (``ReadsContentTypeFormsTrait``) that both writers use, so there is one
   answer to "which types, which columns, which values".

``publish_record``
   Sets the hidden column of one page or content element to 0 and nothing
   else. Start and stop times and access groups stay as they are, and the
   approval card names them — and a hidden default-language record behind a
   translation — where they still restrict the record.

``delete_record``
   Deletes one page or content element with core's delete command. Both
   tables declare a ``delete`` column, so the row is flagged and stays
   recoverable. What core deletes with it is counted on the card first: the
   translations of a default-language record, and for a page its records
   and its whole branch. A page with subpages is refused unless the call sets
   ``include_subpages`` — core has no switch to keep the branch — and a
   branch of more than 50 pages is refused outright. A site root is never
   deleted. The card also counts the records the reference index says still
   point at the record. Core deletes every record stored on a deleted page,
   in every language, without asking for each; for a non-admin the tool
   refuses first what core refuses (a table outside ``tables_modify`` with
   records on the pages, a page translation the user may not edit) and one
   thing more: content on the pages in a language the user may not edit.
   The card lists the subpage uids (ten, then "and N more") and counts the
   translations of the subpages and the records stored on the pages, table
   by table. These counts include records the acting user cannot see; they
   are aggregates only, never titles or uids of records beyond the branch.
   A delete that would take a workspace draft along is refused (rail 3).
   Core's delete in the live workspace discards for good the versions — a
   move version on another page included — and the new workspace
   translations of every live row it deletes: the record, its translations,
   the records on deleted pages, the records of a deleted page translation's
   language, and the inline and file children of each. It strands the other
   drafts it meets: a new page or record of another workspace on a deleted
   page, a draft in a deleted page translation's language, a new workspace
   translation of a deleted page. The tool counts both kinds, each draft
   once, and refuses as well when a table cannot be asked.

``copy_record`` and ``move_page``
   Structural acts: a copy of one element or page, a page moved to another
   parent. :ref:`ADR-199 <adr-199>` records what they bring into being and
   why a copy is hidden and never takes the subpages along. ``move_page``
   asks the permissions core's ``moveRecord()`` asks — ``PAGE_DELETE`` on
   the page and ``PAGE_NEW`` on the new parent for a new parent,
   ``PAGE_EDIT`` within the same parent — refuses a site root and a target
   inside the page's own branch or under a page translation, and says on the
   card how many subpages move along, that the page keeps its URL path —
   core does not regenerate ``slug`` on a move — and when it moves into
   another site, into a site or out of every site.

``replace_file_reference``
   Replaces the file of one existing reference on a content element's
   ``image``, ``assets`` or ``media`` field, or removes the reference.
   ``attach_file_to_content_element`` only appends. A replacement is a new
   reference at the old one's position and the old one deleted, in one
   DataHandler run with the datamap read back before the cmdmap, as
   :ref:`ADR-195 <adr-195>`'s writer does it. Nothing of the old reference
   is carried over: its title, alternative text, description and crop
   described the old file. Core deletes the translated overlays of the old
   reference with it; the tool refuses where the acting user may not change
   a translated element they sit on, names them on the card, and afterwards
   sets each translated element's field to the references it still carries
   and reads the counters back.

The rails every one of them carries:

1. **Two tables, by name.** ``pages`` and ``tt_content`` (``sys_file_reference``
   on a content element for the last writer). Another table is another
   review.
2. **The acting user's rights, asked before the write and enforced again by
   the DataHandler.** The page permission core asks for that act; the
   record-level rights core's ``checkRecordEditAccess()`` asks —
   ``tables_modify``, the record's language, the ``authMode`` grant for each
   select value (``CType``), ``editlock`` — asked without that method, which
   TYPO3 13 lacks, and without ``recordEditAccessInternals()``, which TYPO3 14
   deprecates; the field-level grant for every column a call sets, because
   the DataHandler drops such a column in silence. A missing record and a
   forbidden one get the same neutral refusal.
3. **Live rows only.** In the live workspace the DataHandler writes to any
   uid it is handed, a workspace draft included. Every writer — these six
   and the ones before them that address a record by uid — reads a row only
   where ``t3ver_wsid``, ``t3ver_oid`` and ``t3ver_state`` are all 0 on a
   workspace-aware table, so a draft is not there for it: not written, not
   copied, not counted and not shown on an approval card. Three counts keep
   every workspace on purpose: core's non-admin table check before a page
   delete, which core makes the same way; the ADR-193 check for connected
   translations, where a draft counts because it becomes live when published;
   and ``delete_record``'s count of the drafts a delete would take along,
   which refuses the delete when it is not 0.
4. **Language as ADR-193 left it.** A translation core moves, copies or
   deletes together with its default-language record is refused by itself
   and the refusal names that record; every translation core carries along
   must pass the same record-level check as the record itself — language,
   lock, content type — because core handles each translation on its own and
   goes on past one it refuses, which would leave half an act behind; a
   column a translation takes from its
   default-language element (``l10n_mode = exclude``) is not a field; a
   standalone element is not copied beside connected translations
   (:ref:`ADR-193 <adr-193>`).
5. **Approval before, read-back after.** Each declares a write effect, so
   every call pauses for a human (ADR-134), and the card is the plan the write
   executes (ADR-136, ADR-184). On the cards of ``update_content_element`` and
   ``update_page_metadata`` a field's before and after is bound to the whole
   value: two short values are shown in full; otherwise the card shows the
   section that differs, where it starts, and the length and a short SHA-256 of
   both values, so a change past any excerpt — an appended link — changes the
   card and is compared on resume; a character a reader cannot see is
   written as its code point. This amends ADR-184's rule that only the
   excerpt binds, for these two writers. After the write the result is read back
   whatever the DataHandler's error log says, and the answer states what is
   actually the case: what did not take is named, a record that came into being
   wrong is deleted again and the answer says whether that worked, and a delete
   or move that happened while core refused part of it is reported as done, with
   the part that was left behind.
6. **Live workspace, a full backend environment, disabled by default, the
   ``editing`` group**, not admin-only — as every writer since ADR-135. The
   data class is the ``editing`` group's default, ``EDITOR_CONTENT``: what
   the tools echo is the editorial text of the records they touch.
7. **No editor action.** None of the six declares one
   (:ref:`ADR-152 <adr-152>`). An editor action is offered from a record's
   context menu and in bulk over selected records
   (:ref:`ADR-162 <adr-162>`); "delete fifty pages" as one click is a
   decision of its own, not a side effect of this one. The six are reached
   through the assistant.

``WriteKind`` gains its third case, ``DELETED``, which its docblock
reserved for the first deleting writer: ``delete_record`` and the removal in
``replace_file_reference`` name the record they deleted.

What stays out: a generic update of any table (the rails above are read
against two tables' TCA and core's handling of them), the page properties
beyond ``update_page_metadata``'s fields, publishing into a workspace, a
hard delete, and undelete.

.. _adr-198-consequences:

Consequences
============

✓ The assistant can finish the editorial loop it starts: draft, correct,
publish, restructure and clean up, under the editor's own rights and behind
an approval each time.

✓ The type exclusion rule of ADR-196 is one implementation for the creating
and the updating writer; a type that becomes unsafe to create becomes
unsafe to edit in the same change.

✕ A wrong approval now changes or removes live content. The approval card is the
control: it shows every column's change bound to the whole value (for the two
updating writers), what a delete takes along and what still points at it. The
delete is recoverable from the recycler; an overwritten text is recoverable from
the record history.

✕ ``update_content_element`` leaves what did take written when one column
did not, as ``update_page_metadata`` does. The answer reports the record as
written in part and names what took and what did not.

✕ The delete card's counts include records the acting user cannot see, so
an editor may learn that a branch holds more than they can see; core's
delete requires the delete right on every page of it anyway, and the card
shows counts, not titles.

✕ The observed outcome of a run (:ref:`ADR-185 <adr-185>`) reads the records
a run wrote; for a deleted one, how later history is judged is ADR-185's
rule, unchanged here and not measured for deletions.

.. _adr-198-amendment-read-back-first:

Amendment (2026-11-06): every writer reads back before it refuses
=================================================================

Rail 5's "read back whatever the DataHandler's error log says" held for the
six writers of this ADR. At 17 other places — 14 in eleven writers, three in
the shared helpers that create a record, version one and write a redirect —
a writer still refused as soon as the log was not empty, before it read
anything back (:ref:`ADR-206 <adr-206>` counted 13 of them). An
installation's DataHandler hook that writes another record along with the one
being saved puts that record into the same run; where the user may not edit
it, core logs "Attempt to modify record pages:170 without permission or
non-existing page" into the log of the write that landed. A non-admin editor
in a draft workspace had the description of a page stored as a draft, and the
assistant told them that TYPO3 had refused the change and nothing was written.

Every writer now reads back first. What took is reported as written — with
its completeness, the draft workspace note and the provenance event — and the
log's entries follow as "TYPO3 also reported: …", bounded as before. Where
the read-back fails, the answer is a failure that names what the read-back
found, the same note after it; a complaint is not taken for the reason, as on
such an installation the hook's refusal follows every save. TYPO3's refusal is
the reason only where no record came into being at all — a create, a copy, a
translation or a version the run did not make. In particular:

- ``update_fal_asset_meta`` reports a call where some fields took as written
  in part, as ``update_page_metadata`` does; it used to report it as failed.
- ``create_page_draft``, ``create_content_element_draft`` and
  ``create_record_draft`` judge a row core created despite a complaint by
  their read-back and take it back where it is wrong; ``create_redirect``
  reports a row that does not hold the requested values as written in part,
  as before. They used to answer "refused" and leave the row in place.
- ``copy_record`` keeps a copy that landed hidden where it was asked. What core
  copies along — inline children, a page's content, a translation in a
  language the target site lacks — is not read back, so beside a complaint
  the copy is reported as written in part, to be checked, and stays hidden.
- ``create_translation_draft`` keeps a translation that is hidden and
  connected, whatever the log of the ``localize``, the hiding write or the
  delete of the translation it replaces says.
- ``move_content_element`` reads back the place as well as page and column —
  directly after the anchor, or first on the page without one, in the order
  core keeps across the whole page — because a move on the element's own
  page and column that core refused leaves page and column as asked. On the
  page and in the column but not at the place, the move is reported as
  written in part.
- ``set_page_social_image`` asks the ``tables_modify`` grant for ``pages`` and
  ``sys_file_reference`` before the write. In a replacement the page's count
  is right whether core wrote the page row or refused it, so its read-back
  cannot see a refused page row.
- The version an attaching writer makes first is kept where core made it
  despite a complaint, as this call's own: the attach that follows reads back
  and takes it back where it fails.

The field-fill writer already decided by its read-back and only logs the
complaints (:ref:`ADR-265 <adr-265>`); it is unchanged.

✕ A complaint names the other record by table and uid, as a refusal always
did; an editor may learn that a hook writes a page they cannot edit.

✕ A call that asks for values a record already holds, beside a complaint, is
reported as written with the complaint, as it was without one: nothing
changed, and the read-back cannot tell whether TYPO3 refused the record
itself.

✕ Where TYPO3 refuses the asked record itself, the answer leads with what did
not take and the writer's guess at the cause (a missing field grant, a hook),
and TYPO3's refusal follows as "TYPO3 also reported"; it used to lead with
the refusal.

.. _adr-198-revisit:

Revisit when
============

An installation needs one of the six on a table other than ``pages`` or
``tt_content``; an editor action for them is wanted; or a deletion's
observed outcome turns out to be judged wrongly.
