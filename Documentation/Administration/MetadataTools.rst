.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

======================
Metadata field writers
======================

``update_page_metadata``
   Sets a fixed set of descriptive fields on one page. Editable: ``title``,
   ``subtitle``, ``nav_title``, ``abstract``, ``description``, ``keywords``
   and — when EXT:seo is installed — ``seo_title``, ``og_title``,
   ``og_description``, ``twitter_title``, ``twitter_description`` and
   ``twitter_card``. The last one is a select: its value must be one of the
   items the TCA declares, and the refusal names them. Anything
   else (``slug``, ``hidden``, ``doktype``, ``fe_group``, ``perms_*``,
   ``no_index``, the image relations …) is refused. Authorised by the acting
   user's page-edit right; the DataHandler then enforces ``tables_modify`` and
   the field-level grants. The two social images have a writer of their own,
   ``set_page_social_image``.

``set_page_social_image``
   Sets the social preview image of one page — ``og_image`` (Open Graph) or
   ``twitter_image`` — to an **existing** managed file, identified by its
   ``sys_file`` uid. Both columns come with EXT:seo; without the extension the
   call is refused and says so. It creates exactly one ``sys_file_reference``
   and never uploads, moves or renames a file (:ref:`ADR-195 <adr-195>`).

   Authorised on both ends: the acting user needs page-edit rights on the
   page, and the file has to lie in a permitted storage inside that user's own
   file mounts. Either failure is refused in the same words as a page or file
   that does not exist. Both columns are exclude fields, so a non-admin editor
   also needs the field-level grants ``pages:og_image`` /
   ``pages:twitter_image`` — without them the whole call is refused **before**
   anything is written, because the DataHandler would otherwise create the
   reference and drop the page's side of it in silence. A column an
   installation hides from non-admins (``displayCond`` of
   ``HIDE_FOR_NON_ADMINS``) is refused for every non-admin the same way, and
   the refusal names the condition.

   Three things worth knowing before enabling it:

   - **The field must accept the file.** Both columns accept the image
     extensions the installation configures (``common-image-types``); a
     ``.txt`` is refused rather than written.
   - **An image that is already there stops the call.** The refusal names the
     file referenced now; ``replace`` is the only way past it and it
     **deletes** that reference first — recoverably (``deleted = 1``) and in
     ``sys_log`` — and the approval card says so on its own line.
   - **Default-language pages only.** A translated page is refused and the
     default-language page named; whether a translation follows its parent or
     carries its own image is a page-properties decision
     (``allowLanguageSynchronization``) the tool leaves to the editor. And
     because TYPO3 saves a page's translations along with the page, an editor
     whose allowed languages leave out a language the page is translated into,
     or who may not edit one of its translations — a translation carries page
     permissions of its own — is refused before anything is written; the
     refusal names the translation.

   The page's own reference count is re-read afterwards, because EXT:seo reads
   it before it looks for the file: a reference the page does not count is one
   nothing renders. When that check fails, the new reference is deleted again
   and the page is written back to what it held; the tool then reads the page
   once more and the message says whether it is back as the call found it or
   what is still there, and names anything TYPO3 reported while putting it
   back — on a translated page that can be a translation it could not
   re-synchronise, which the tool reports rather than repairs.

``set_file_alternative_text``
   Started from the Editor Action Center, the run sends a downscaled copy of the
   image to the model (:ref:`configuration-vision`).

   Sets the alternative text (``sys_file_metadata.alternative``) of one managed
   file, identified by its ``sys_file`` uid — the accessibility gap an editor
   most often leaves behind. It writes that one field and nothing else.

   Authorised by the same storage allow-list and file mounts as the read-only
   FAL tools; core's own file-metadata permission check (a **writable** file
   mount) then applies inside the DataHandler, so a read-only mount is refused
   there.

   Two limits worth knowing before enabling it:

   - It **never creates** a metadata record. A file that carries none is
     refused, in the same words as a file the user may not reach — so the
     model cannot tell "not yours" from "not indexed".
   - It writes the default-language metadata in the run's recorded workspace:
     live in workspace 0, or the corresponding draft in a draft workspace.
     It takes no language argument and does not translate metadata. Publishing
     a draft remains a separate action. See :ref:`tool-workspaces`.

   An empty string is accepted and is the correct value for a decorative
   image.

``update_fal_asset_meta``
   Sets the **title** and the **description** (``sys_file_metadata.title``,
   ``sys_file_metadata.description``) of one managed file, identified by its
   ``sys_file`` uid, and the **copyright** notice where EXT:filemetadata
   provides the column. Any of the fields may be given; at least one is
   required.

   It is a second tool rather than a wider ``set_file_alternative_text``
   because the two are deliberately **field-disjoint**: this one does not
   write the alternative text and that one writes nothing else, so no
   file field has two writers and an approver never has to work out which of
   two cards won.

   It shares the alternative-text tool's whole permission surface — the same
   storage allow-list and file mounts, core's writable-mount check inside the
   DataHandler, the same neutral refusal, no metadata record ever created, and
   default-language metadata in the run's recorded workspace. A draft stays a
   draft until it is published; see :ref:`tool-workspaces`.

   Two behaviours worth knowing before enabling it:

   - A field the call **leaves out keeps its stored value**; an **empty
     string** clears the field it names. The two are not the same, and a call
     that sets the title never touches a description somebody wrote by hand.
   - Core marks ``title`` as an **exclude field** and ``description`` not, so
     a non-admin editor needs the field-level grant
     ``sys_file_metadata:title`` for the title. Without it the whole call is
     refused **before** anything is written, rather than the DataHandler
     dropping the title in silence and applying the description — which would
     leave the asset described by half of an approved call.

   The title is bounded in **bytes**, not characters: the column is
   ``tinytext`` and holds 255 bytes, so accented and non-Latin characters
   count for more than one.

``set_file_categories``
   Sets the categories (``sys_file_metadata.categories``) of one managed file,
   identified by its ``sys_file`` uid, choosing only from existing categories
   (:ref:`ADR-268 <adr-268>`). Started from the Editor Action Center, the run
   sends a downscaled copy of the image and the list of categories the model
   may choose from (:ref:`configuration-vision`).

   The list is the categories below the root an administrator names in
   ``vision.categories.rootUid``: live, not hidden, in the default language,
   and inside the editor's category mounts where the editor has any. At most
   ten levels below the root and 200 categories are read; a category beyond
   either limit cannot be chosen, and a file that carries one keeps it. Without
   a root the tool refuses every call and the editor action is not offered. A
   uid outside the list refuses the whole call before any approval, and the
   refusal names the list. No category is ever created.

   The call names the complete set the file should carry from the list.
   Categories the file carries from outside the list stay, so an editor's own
   tagging in another tree is never removed; an empty set removes the file's
   categories from the list only. The approval card shows the titles before
   and after, and the uids in its technical details.

   It shares the alternative-text tool's permission surface — storage
   allow-list, file mounts, core's writable-mount check, the neutral refusal,
   no metadata record created, the default-language record only — and works
   in a draft workspace like it.
