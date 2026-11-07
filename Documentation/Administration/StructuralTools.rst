.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

=============================
Updating and managing records
=============================

``update_content_element``
   Changes fields of one existing content element (:ref:`ADR-198 <adr-198>`).
   The field set is the one ``create_content_element_draft`` offers for a new
   element of the same type — the scalar columns of the type's form, header and
   body text included — checked by the same rules (:ref:`ADR-196 <adr-196>`): a
   value outside a select's items, a number outside its range, a column the
   page's TSconfig hides or makes read-only is refused, and the refusal names
   the columns the type offers. An element whose type the exclusion rule leaves
   out — raw HTML, plugins, menus, shortcuts, a form holding a FlexForm or
   inline children — is refused whole; relations and the identity, position,
   visibility, publication, audience and translation columns are never fields. A
   column a translation takes from its default-language element (``l10n_mode =
   exclude``) is refused and the element to change named. The approval card
   shows every column's change, bound to the whole value. Where some columns
   take and others do not, the answer says which. Authorised by content-edit
   rights on the page, the record-level rights and the field-level grant for
   every column.

``publish_record``
   Clears the hidden flag of one page or content element — the step after a
   human has reviewed a draft — and changes nothing else. Start and stop
   times and access groups stay as they are; the approval card names them,
   and a hidden default-language record behind a translation, where they still
   keep the record from visitors. A record that is not hidden is not written.
   Needs page-edit rights on a page or content-edit rights on an element's
   page, and the field-level grant for the hidden column.

``delete_record``
   Deletes one page or content element with core's delete command. The row is
   flagged ``deleted`` and stays recoverable from the recycler. What core
   deletes with it is counted on the approval card first: the translations of a
   default-language record, and for a page its subpages (their uids, ten and
   then "and N more"), their translations, and the records stored on the pages
   table by table. The counts include records the acting user cannot see; they
   are counts only, never titles. A page with subpages is refused unless the
   call sets ``include_subpages``; a branch of more than 50 pages is refused
   outright, and a site root is never deleted. The card also counts the records
   the reference index says still point at the record — links and shortcuts that
   will break. Needs delete rights on a page (and on every page of its branch),
   content-edit rights for an element, and the right to edit every translation
   that goes along; for a page, also ``tables_modify`` for every table with
   records on it and the languages of the content on it. A delete that would
   take a workspace draft along is refused: core discards the versions and
   new workspace translations of what it deletes for good, and strands the
   other drafts on a deleted page. Publish or discard them in their workspace
   first. In a draft workspace the record and its translations are marked for
   deletion there and deleted live when the workspace is published; the drafts
   of other workspaces still stop the call, a record new or moved in the run's
   workspace is refused before the card (:ref:`ADR-266 <adr-266>`).

``copy_record``
   Copies one content element to a page and column, or one page under a
   parent, with core's copy command (:ref:`ADR-199 <adr-199>`). The copy and
   every translation copied with it are **always hidden**, whatever core's
   ``hideAtCopy``, the user's preferences and page TSconfig say, and a page is
   **always copied without its subpages**: core would hide only the top page
   of a copied branch. What happens to the translations of a
   default-language record depends on the TYPO3 release and the target's
   **site**: TYPO3 14 and 13.4.25 or later copy none outside a site and,
   inside one, a translation only where the site has its language (for an
   element, where the target page is translated into it); one core refuses
   fails the copy, which is then taken back. Before 13.4.25, core asks no
   site: it copies every page translation, and drops an element translation
   the target page is not translated into without an error. The card states
   the rule of the running core. The answer says how many were copied. A
   page's content is copied with the page. A translation is refused by
   itself, as are a page translation as target, a site root, and a
   standalone element beside connected translations on the target page
   (:ref:`ADR-193 <adr-193>`).

``move_page``
   Moves one default-language page to a new parent or directly after a
   sibling. Its content, translations and subpages move with it, and it keeps
   its uid and its URL path — core does not regenerate the slug on a move.
   The approval card says so, counts the subpages that move along, and warns
   when the page moves into another site, into a site or out of every site.
   Asks the permissions core asks: delete rights on the page and new-page
   rights on the new parent for a new parent, edit rights within the same
   parent. A site root, a translation, a page translation as parent and a
   target inside the page's own branch are refused.

``replace_file_reference``
   Points one existing file reference on a content element's ``image``,
   ``assets`` or ``media`` field at another **existing** file, or removes the
   reference; ``attach_file_to_content_element`` only appends. A replacement
   takes the old reference's position, and nothing of the old reference is
   carried over — its title, alternative text, description and crop described
   the old file; the card says which texts the new reference will show. The
   file itself is never changed, and the new file must lie in a permitted
   storage inside the acting user's file mounts and carry an extension the
   field accepts. Core deletes the translated references of the old one with
   it: the card names them, the call is refused where the acting user may
   not change the translated element they sit on, and afterwards each
   translated element's reference count is set and read back. Where the
   element is itself an inline child (an extension such as EXT:news holds
   content elements that way), every record that holds it, up the chain,
   must pass the table, page and record-level checks as well
   (:ref:`ADR-230 <adr-230>`); otherwise the call is refused in the neutral
   words. A grid container holding the element as a ``tt_content`` child is
   not asked, because the page module edits its children on their own.

``set_file_reference_alternative_text``
   Sets the alternative text of **one use** of an image: the ``alternative``
   column of one existing ``sys_file_reference``, identified by its uid, in
   place (:ref:`ADR-230 <adr-230>`). The reference may sit on a content
   element, on a page's own file field or on a record of any other table in a
   ``type => file`` column. It writes that one column and nothing else — not
   the file's own alternative text (``set_file_alternative_text`` writes that,
   and every use without a text of its own shows it), not the record, and not
   the reference's translations. A translated reference is written in its own
   language.

   Rights are checked before the write: content-edit rights on the record's
   page, and for a page's own file field page-edit rights as well (the
   DataHandler asks for content-edit rights on a reference's page even when
   the reference belongs to the page); for a non-admin, a page without an
   edit lock; ``tables_modify`` for ``sys_file_reference``; the record-level
   rights (``tables_modify`` on the record's table, its language, the content
   type, the record's own edit lock); access to the reference's language; and
   the field-level grants for ``sys_file_reference:alternative`` and for the
   record's file field. Where the record is an inline child — a carousel item
   inside a content element — every record that holds it, up the chain, must
   pass the same table, page and record-level checks: an edit lock or a
   content type the user may not edit on the outer element stops the call. A
   container of the same table — a grid element holding content elements —
   is not asked, because the page module edits its children on their own. A
   reference that does not exist, is deleted, is a workspace version or sits
   outside a file field, an inline child whose owner is gone, and a record,
   owner or page the user may not edit, are refused in the same words; a
   page's file field without content-edit rights and a page edit lock are
   refused by name.

   Two things worth knowing before enabling it:

   - **An empty text is refused**, a text of spaces too. Unlike
     ``set_file_alternative_text``, an empty string does not mark the image
     as decorative here: whether an image is decorative is a separate
     decision, and an empty alternative text reads like a forgotten one.
   - **In the run's workspace** (:ref:`ADR-262 <adr-262>`). An image use
     that exists only in another draft workspace is not there for this tool.

   The approval card names the record, its page, the language, the field and
   the file, and shows the reference's own text next to the proposed one;
   where the reference has no text of its own, the card says that the file's
   text applies today. To read a reference's current text, ``read_records``
   on ``sys_file_reference`` returns its ``alternative`` column.
