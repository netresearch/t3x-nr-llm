.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

==============================
Attaching and creating records
==============================

``attach_file_to_content_element``
   References an **existing** managed file from one content element, appended
   to one of its file fields (``image``, ``assets`` or ``media``). It creates
   exactly one ``sys_file_reference``. It never uploads, moves, copies or
   renames a file, and never touches ``sys_file_metadata`` — the file has to be
   there already.

   Authorised on both ends: the acting user needs content-edit rights on the
   element's page, and the file has to lie in a permitted storage inside that
   user's own file mounts. Either failure is refused in the same words as an
   element that does not exist.

   Four things worth knowing before enabling it:

   - **Only elements in the default language.** The reference is written in
     the default language, so a translated element is refused rather than
     given a reference in a language other than its own.
   - **The field must accept the file.** Each file field declares which
     extensions it takes, and they differ — ``image`` accepts fourteen,
     ``assets`` twenty-seven, ``media`` anything. A ``.docx`` on ``image`` is
     refused rather than written, because the FormEngine would reject the
     relation anyway.
   - **The field is only inferred when it is unambiguous.** When the element's
     type offers exactly one of the three, that one is used; when it offers
     several, the call must name the one it means. The tool does not pick.
   - **Calling twice attaches the file twice.** That is the declared effect
     (``NON_IDEMPOTENT_WRITE``), not an accident: two references to one image
     are a valid thing to want, so nothing deduplicates behind the caller's
     back.

   The new reference is always appended last, and the element's own reference
   count is re-read afterwards. A write that left the relation inconsistent is
   reported as a failure and the reference is removed again.

``attach_file_to_record``
   References an **existing** managed file from a file field of one record in
   a table that has no attach tool of its own — the image of a news article
   (``tx_news_domain_model_news``, field ``fal_media``) is the case it exists
   for (:ref:`ADR-212 <adr-212>`). It creates exactly one
   ``sys_file_reference``, never uploads, moves or renames a file, and may
   attach to a **hidden** record: that is the draft the assistant has just
   created, and the tool never changes its visibility.

   Rights are checked before the write: ``tables_modify`` on the table (a
   check ``attach_file_to_content_element`` does not make), content-edit rights
   on the record's page, the field-level grant for the file field, and a file
   inside a permitted storage and the user's own file mounts. Where the record
   is an inline child — a carousel item inside a content element — every
   record that holds it, up the chain, must pass the table, page and
   record-level checks as well (:ref:`ADR-230 <adr-230>`); a container of
   the record's own table is not asked. An absent record,
   an unreachable file, a page the user may not edit, an owner the user may
   not edit and an inline child whose owner is gone are refused in the same
   words.

   Things worth knowing before enabling it:

   - **pages and tt_content are refused** and point to
     ``set_page_social_image`` and ``attach_file_to_content_element``, as do
     system and sensitive tables.
   - **The field must be a file field of the table and accept the file.** The
     ``allowed`` and ``disallowed`` lists of the column apply, as narrowed by
     the record type's ``columnsOverrides``. The field is only
     inferred when the table has exactly one.
   - **Title, alternative text and description belong to the reference.** They
     override the file's own for this one place. The copyright is a property of
     the file (``sys_file_metadata``), not of a reference: set it with
     ``update_fal_asset_meta``.
   - **Default language only**, and calling twice attaches the file twice.

   For a news draft the first attached image becomes the ``og:image`` of the
   article page unless a reference flagged for the preview comes first: EXT:news
   prints the first preview reference, otherwise the first of ``fal_media``.

``move_content_element``
   Moves one content element to a page and a column. The element keeps its uid,
   its content, its language, its history and its references — only its place
   changes, which is what makes it the least committal write in the set.

   Both ends are authorised: the acting user needs content-edit rights on the
   **source** page as well as on the target, because moving an element out of a
   page edits that page's content too. An ``after_content_uid`` anchor must sit
   on the target page and in the same language; a wrong anchor is refused
   rather than silently corrected.

   The destination column is always stated explicitly on the wire, so the
   element lands where the approval card said it would even when the anchor
   element sits in a column the caller did not expect.

``create_content_element_draft``
   Creates one content element on a page — the first tool that brings a record
   into being. Three things bound it:

   - It is **always hidden**. There is no argument to switch that off:
     publishing is a separate act with a separate audience, and the approval
     that let the tool run approved a draft.
   - The content types are read from the installation's TCA at call time,
     under an exclusion rule (:ref:`ADR-196 <adr-196>`). A type is offered
     unless it is denied by name — ``list`` (the legacy plugin element),
     ``html``, ``shortcut``, ``div``, every ``menu_*`` — or it is a plugin,
     known by its Extbase registration or by the ``plugins`` or ``forms``
     item group, or its form holds a
     column whose payload is not prose: a FlexForm, inline children, a group
     or folder reference, a slug, a password. File, category and link
     relations do not exclude a type; the draft leaves them empty, so
     ``textmedia`` is offered and gets its media through
     ``attach_file_to_content_element``. The tool description lists what the
     installation offers.
   - The field set is the type's own scalar columns: headline, body text,
     column, language and position as arguments, and every further ``input``,
     ``text``, ``select`` (static items), ``check``, ``number``, ``datetime``,
     ``radio``, ``color`` or ``email`` column of the chosen type through
     ``fields``, validated against its TCA type as the DataHandler reads it.
     A ``check`` with several items, a ``check`` limited by
     ``maximumRecordsChecked`` and an ``input`` or ``email`` with ``eval``
     ``unique`` stay in the form and are refused as keys, because TYPO3 would
     change them in silence; so is a column the type's TCA declares
     ``readOnly``, and a read-only ``header`` or ``bodytext`` refuses the
     call. Identity, position,
     visibility, publication, audience and translation columns are refused by
     name; a wrong key or value refuses the whole call and names the columns
     the type offers. The chosen type is checked against the acting user's
     explicit allow-list, and the exclude-field grants per column — the
     tool's own columns included — before the write; a ``datetime`` is handed
     over as the integer both supported cores store. The page's TSconfig
     narrows all of this per page, as it narrows the backend form:
     ``TCEFORM.tt_content.CType.keepItems`` and ``removeItems``; ``disabled``
     and ``config.readOnly`` on a ``fields`` column, on ``bodytext`` and on
     ``header`` — a hidden or read-only header refuses the call, since the
     header is required, and like the backend form the tool does not read
     ``config.readOnly`` for a ``radio`` column; and
     ``keepItems`` and ``removeItems`` on a select column in ``fields``, on
     ``colPos`` for the ``column`` argument and on ``sys_language_uid`` for
     the ``language`` argument — each also under ``types.<CType>`` — are
     honoured, and the refusal names the rule. ``addItems`` is not read,
     and a column that is ``readOnly`` in the TCA stays refused even where
     a page sets ``config.readOnly = 0``. This
     is still not a generic record API: the table is fixed and a
     relation is never an argument.

   In a language other than the default one the element is created
   **standalone**, without a translation parent. That is refused on a page
   which already holds connected translations in that language
   (:ref:`ADR-193 <adr-193>`): a standalone element beside them is what the
   page module reports as *Inconsistent content detected*. The refusal sends
   the model to the default language and to ``create_translation_draft``. A
   page whose TSconfig sets
   ``mod.web_layout.allowInconsistentLanguageHandling`` is exempt, as it is
   from core's warning.

   ``bodytext`` is refused for a type whose form does not show it, such as
   ``header``. Otherwise it reaches the DataHandler and its RTE transformation
   exactly as an editor's input does. It is bounded in length and not otherwise filtered — an
   editor may write the same markup by hand, and a tool enforcing a stricter
   rule than the CMS would be enforcing a rule that does not exist.

``create_page_draft``
   Creates one standard page under a parent page — the first tool that brings a
   page into being (:ref:`ADR-180 <adr-180>`). It is bounded the same way the
   element draft is, and a little further:

   - It is **always hidden**, with no argument to switch that off. Core's own
     default for a new page is hidden as well; the tool does not rely on it —
     an installation that sets ``TCAdefaults.pages.hidden = 0`` gets the same
     read-back, and a page that came out visible is deleted again.
   - It is **always a standard page** in the **default language**. Shortcuts,
     mount points, links, folders and translations are out of reach; a
     translation has its own tool.
   - The field set is fixed: title, navigation title, position (``parent`` and
     an optional ``after_page_uid`` that must be a subpage of the same parent).
     The URL segment is generated by the DataHandler from the title.

   Authorised by the acting user's *new page* permission on the parent; the
   DataHandler then enforces ``tables_modify``, the page-type grant and the
   field-level grants on top.

   It creates the page and **nothing on it** — one record per call, as for
   every writer. The success message hands the model the new uid and names
   ``create_content_element_draft`` as the tool to put text on it, so "a page
   with an introduction" is two calls and two approvals, each showing one
   record.

``create_translation_draft``
   Translates one page or content element into another language by running
   core's own ``localize`` command, so connected-mode translations, inline
   children and every localisation hook behave exactly as they do in the
   backend.

   The result is **hidden**, which is the part core does not do: ``localize``
   copies the source's visibility, so a translation of a live page would go live
   the moment it was created.

   An existing translation stops the call and is named in the refusal. The
   ``overwrite`` argument is the only way past it: it **deletes** that
   translation first — recoverably (``deleted = 1``) and in ``sys_log`` — and
   the approval card says so on its own line. A language the record's site
   does not define is refused before anything is created.

   The text is then **machine-translated** (:ref:`ADR-209 <adr-209>`): every
   text column of the record's type that holds text in the source — headline,
   subheader, body, page title, navigation title, description and the like,
   but not the page's author — is translated from the source record and
   written into the translation. Rich text is sent as HTML, so its markup
   survives. A translation longer than a column's maximum length is cut to it,
   and the result says so. The ``translator`` argument chooses ``deepl`` or
   ``llm`` (the default); a translator the installation has not configured is
   refused. The glossary of the record's site for the language pair applies
   (see :ref:`Glossaries <administration-glossaries>`). The approval card
   names the translator, the fields, fields the user may not edit, and
   whether the site keeps a glossary for the pair.

   If a translation fails, comes back empty or is cut off at the translator's
   output limit, nothing is written: the draft keeps the copied source text
   and the result says *The text was NOT machine-translated* with the reason.
   If the write itself fails, the result names which fields hold the
   translation and which still hold the source text.

   Identical translations are answered from a cache for a day. A cached
   answer does not reach the translator, so it passes no budget check and
   records no usage. Saving or deleting a glossary, an LLM configuration, a
   model, a provider, a skill or a prompt snippet empties that cache.

``create_record_draft``
   Creates one record in a TCA table that has **no dedicated writer** — the
   fallback for extension tables such as a news record
   (:ref:`ADR-197 <adr-197>`). It serves a table by exclusion:

   - ``pages`` and ``tt_content`` have writers of their own and are refused
     by name; ``sys_*`` tables, the tables no tool may read (``be_users``,
     ``sys_log``, the nr_llm and nr_vault tables …) and tables declared
     ``adminOnly``, ``hideTable`` or ``readOnly`` are refused as well.
   - A table another **registered creator** declares through
     :php:`RecordCreatorInterface::getCreatedTables()` is refused and the tool
     named — read at call time, so an extension that ships its own creator
     withdraws the fallback the day it is installed. The builtin creators
     declare ``tt_content`` (``create_content_element_draft``), ``pages``
     (``create_page_draft``) and both (``create_translation_draft``). A
     declaration that cannot be read refuses the call and names the tool.
   - The extension configuration ``tools.createRecordDraft.deniedTables``
     (comma-separated table names, empty by default) excludes further tables
     on one installation. It can only narrow the list, never widen it, and a
     configuration that cannot be read refuses every table.
   - A table without a "disabled" enable column is refused, because nothing
     this extension writes may be visible before a human unhides it.

   Only **scalar** columns can be set — ``input``, ``text``, ``number``,
   ``email``, ``color``, ``datetime`` (as a timestamp), ``check``, ``radio``
   and ``select`` with static items — and only those the record type's form
   shows as writable on that page: a column the TCA declares ``readOnly`` is
   refused unless page TSconfig
   ``TCEFORM.<table>.<column>.config.readOnly = 0`` lifts it, as it does in
   the form (a ``radio`` excepted, whose ``readOnly`` the form does not let
   page TSconfig change).
   Relations, files, FlexForms, links and slugs are not arguments;
   ``hidden``, ``uid``, ``pid``, the language, timing, ownership and
   versioning columns are refused by name. Values are checked against the
   record type's TCA — its ``columnsOverrides`` included — before anything is
   written: the items of a select, ``max`` and ``range``, 0/1 for a check, a
   valid address for an email. The record type is the one the DataHandler
   gives the record — the call's value for the type column, else
   ``TCAdefaults`` from the page's TSconfig, then from the acting user's, else
   the column's default — and the tool writes it explicitly where the acting
   user may write the type column, leaving it to the DataHandler otherwise; a
   ``TCAdefaults``
   value that names no record type of the table is refused. Every column the
   record type marks required must be given. A value the DataHandler would rewrite is refused rather
   than written: a column with an ``eval`` the DataHandler acts on
   (``upper``, ``alphanum``, ``unique``, ``uniqueInPid``, an extension's
   registered evaluation …; a token it ignores is ignored here too), an
   eight-digit colour on a
   column without ``opacity``, a text shorter than its ``min``, a decimal
   with more than two decimal places (TYPO3 stores two), a decimal the range
   check would clamp. So is what the page's TSconfig takes out of the
   backend form: a column ``TCEFORM.<table>.<column>.disabled`` hides, a
   select value ``keepItems`` or ``removeItems`` filters out, a column
   ``TCEFORM.<table>.<column>.config.readOnly`` makes read-only, and a record
   type its type field does not offer on that page — the refusal names the
   rule.

   The record is **always hidden** and **always in the default language**.
   Authorised by the acting user's ``tables_modify`` grant, the content-edit
   permission on the page or folder, and the field-level grants for every
   column the call sets — asked before the write, because the DataHandler
   drops such a column in silence. What TYPO3 still rewrites — a hook of the
   installation, a grant the pre-check does not model — is read back
   afterwards, the default language included, and the record type unless it
   is core's fallback type and the tool could not write it; a record
   that does not carry what was approved is deleted again and the columns are
   named.

   The approval card names each column with the record type's TCA label — a
   showitem ``field;Label``, else a ``columnsOverrides`` label, else the
   column's own — in English, never in the viewer's language, because the
   card is compared byte for byte when the run resumes:
   ``published_at (Published at): "2026-09-10T10:00:00+00:00"``. A page
   TSconfig label override (``TCEFORM.<table>.<column>.label``) is not
   applied, although the backend form shows it: the card must not depend on
   the page or on the viewer's language.

   It declares no editor action — it has no record an editor would select —
   so it is reached through the assistant only, never from a record's
   context menu.
