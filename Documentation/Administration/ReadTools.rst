.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

===================================
Read and interaction tool catalogue
===================================

.. _administration-tools-builtin:

The built-in tools
==================

nr-llm ships forty-six read-only tools and twenty-three writing tools. Each is a
reference implementation of the security contract: model-chosen arguments are
validated and scoped, volumes are capped, and secret-bearing output is either
redacted or gated behind a separate ``_raw`` variant. Forty ship
**enabled**; the three unredacted ``_raw`` variants (``get_env_raw``,
``get_php_info_raw`` and ``list_be_users_raw``), ``ask_choice``, ``highlight_record``, ``fetch_external_url``
(see :ref:`administration-tools-external-pages`) and all twenty-three writing tools
(``update_page_metadata``, ``set_page_social_image``,
``set_file_alternative_text``, ``update_fal_asset_meta``,
``set_file_categories``, ``move_content_element``, ``create_content_element_draft``,
``create_page_draft``, ``create_translation_draft``,
``attach_file_to_content_element``, ``attach_file_to_record``,
``create_record_draft``, ``update_content_element``, ``publish_record``,
``delete_record``, ``copy_record``, ``move_page``,
``replace_file_reference``, ``set_file_reference_alternative_text``,
``create_redirect``, ``update_redirect``, ``disable_redirect``,
``clear_cache``) ship **disabled** and must be enabled deliberately.
Many require admin; the read-only structure, content
and file tools (``get_pagetree``, ``get_tca``, ``get_full_tca``,
``get_table_schema``, ``get_flexform_schema``, ``fluid_resolve``,
``search_records``, ``get_page_content``, ``read_records``,
``get_record_history``, ``resolve_url``, ``validate_tca``,
``list_fal_storages``, ``browse_fal_folder``, ``search_fal_files``,
``get_fal_references``, ``find_missing_files``) are offered to non-admin
backend users — those self-enforce the acting user's TYPO3 permissions
(page-show rights, ``tables_select``) inside the tool, so a non-admin only
ever sees what the backend already grants them (see
:ref:`ADR-042 <adr-042>`). ``fetch_external_url`` is offered to non-admins
too; it reads public web pages only, which no TYPO3 permission covers, under
the controls in :ref:`administration-tools-external-pages`. ``load_skill`` is
offered to non-admins as well; it brings in only skill text an administrator
approved for the run's configuration (see below).
``ask_choice`` is offered to non-admins as well; it reads nothing.
``highlight_record`` is offered to non-admins as well; it accepts only the
record the run is about and the content elements on that page.

In a run started in a draft workspace the tools that read records —
``get_page_content``, ``read_records``, ``search_records``, ``get_pagetree``,
``read_fal_asset_meta``, ``search_fal_files``, ``get_fal_references``,
``get_record_history``, ``highlight_record`` and ``get_tsconfig`` — read them
as that workspace shows them: changed, new, moved, hidden or deleted there as
they are there, each record under its live uid, with filters, search terms,
limits and offsets applied to what the workspace shows. In live, and in a run
that did not record its workspace, they read live rows. A draft of a
workspace the run was not started in is never read, and a version row named
by its own uid is no record (:ref:`ADR-260 <adr-260>`,
:ref:`ADR-263 <adr-263>`).

The two tools below are the fullest illustrations of the contract:

``fetch_logs``
   Returns the most recent ``sys_log`` entries, newest first, with an
   optional PSR ``level`` filter and a ``limit`` (default 20, **hard-capped
   at 50**). Personally-identifying fields — the client IP, the backend user
   id and the serialized payload — are **redacted by omission**, because the
   result egresses to the external provider.

``read_fal_asset_meta``
   Returns read-only metadata (file name, MIME type, size, title, alternative
   text) for a single managed file (``sys_file``) by its ``uid``. The uid is
   model-chosen and therefore injection-steerable, so the lookup is
   **storage-scoped** (default: the default storage). A uid in a non-permitted
   storage returns the same neutral "not found or not permitted" string as a
   missing uid — the model cannot enumerate arbitrary files.

The remaining tools follow the same pattern:

``list_fal_storages``
   The file storages this run may touch (uid, name, driver, status flags).
   The effective set is the configured allow-list, intersected for
   non-admins with their file mounts; the server-side base path is never
   part of the output.

``browse_fal_folder``
   One FAL folder: subfolders (with file count), then files with size and
   MIME type. Storage-relative identifiers only; anything unresolvable
   collapses into one neutral denial. Capped at 100 entries.

``search_fal_files``
   Substring search over file name and metadata title/alternative within
   the accessible storages. ``%``/``_`` in the query match literally;
   missing files are excluded.

``get_fal_references``
   Where a file is used: ``sys_file_reference`` rows as ``table:uid
   (field)``, hidden references marked. Soft references (RTE links, plain
   URLs) are not tracked — stated in the output so "no references" is
   never read as "safe to delete". Non-admins only see references from
   tables they may read.

``find_missing_files``
   ``sys_file`` records whose physical file is gone (``missing = 1``) —
   the "broken image" diagnosis. The total count is always reported next
   to the capped listing.

``get_env`` / ``get_env_raw``
   Process environment variables. ``get_env`` redacts secret-looking values
   (password, token, key, secret, salt, DSN, …); ``get_env_raw`` returns them
   unredacted (database password, encryption key) and ships disabled.

``get_php_info`` / ``get_php_info_raw``
   PHP runtime configuration. ``get_php_info`` is redacted; ``get_php_info_raw``
   returns the full, secret-bearing ``phpinfo`` detail and ships disabled.

``get_pagetree``
   The backend page tree (uid, title, doktype) as a depth-indented outline;
   deleted and hidden pages are excluded — structure only, no content.

``get_tca``
   The TYPO3 TCA schema: with no argument it lists the configured table names;
   with a ``table`` argument it returns that table's field definitions.

``list_be_groups``
   The backend user groups (uid, title).

``list_be_users`` / ``list_be_users_raw``
   Backend users. ``list_be_users`` omits credentials (password hashes and MFA
   secrets are never included); ``list_be_users_raw`` returns the full
   non-credential profile columns and ships disabled.

``search_records``
   Full-text search across the record fields the TYPO3 backend search
   covers. On TYPO3 v13 these are a table's ``ctrl.searchFields``, or every
   input and text column when the table sets none; on TYPO3 v14 every column
   of a searchable type whose TCA does not set ``'searchable' => false``.
   Returns compact ``table:uid`` hits with a short excerpt around the match.
   Credential and nr-llm configuration tables are never searched; non-admins
   are limited to their ``tables_select`` tables and to hits on pages they
   may show.
   With ``include_hidden`` the search also returns hidden records, marked
   ``(hidden)``, so an agent can find a draft it created earlier; a non-admin
   gets them only on pages where they may edit content and with
   ``tables_modify`` on the table.

``get_page_content``
   One page's header data plus its content elements in column/sorting order
   (uid, colPos, CType, header, a short bodytext excerpt). Non-admins need
   page-show permission; only admins see hidden elements (marked
   ``[hidden]``).

``read_records``
   Generic equality-filtered read of one TCA table — never raw SQL. Fields
   are validated against the TCA and credential-like columns are silently
   dropped; the same table gates as ``search_records`` apply. With
   ``include_hidden`` hidden records are returned too, marked ``(hidden)``,
   under the same edit-permission rule as ``search_records``; deleted
   records are never returned.

``get_record_history``
   One record's change history from ``sys_history``, newest first: when,
   which backend user, which action, and per modification the changed
   fields as old → new values. Values of credential-like fields are never
   rendered — only the fact that they changed. Same table gates as
   ``read_records``, and non-admins additionally need page-show access on
   the record's page.

``resolve_url``
   Map a URL (or path) of this instance to the page that serves it: site,
   language, page uid/title/slug and route arguments. Routing only — no
   request is sent; foreign hosts cannot match by construction. Non-admins
   need page-show permission on the resolved page.

``get_typoscript``
   The resolved frontend TypoScript (setup or constants) effective on a page,
   with a dotted ``path`` drill-down and capped output. Admin-only —
   constants routinely carry API keys — and credential-like values render as
   ``[redacted]`` on top of that.

``get_tsconfig``
   The rootline-merged Page TSconfig effective on a page, with the same
   ``path`` drill-down, output cap and redaction as ``get_typoscript``.
   Admin-only.

``get_last_exception``
   The newest exception/error from the TYPO3 file logs with its parsed
   stack trace and the surrounding source lines of the project frames
   inlined. ``index`` steps back through older errors, ``search`` filters
   by message, class or component. Admin-only. Outside the chat,
   :ref:`administration-error-hints` asks a model for a cause and next step.

``read_source``
   A line-numbered range of one project source file. Paths must resolve
   inside the project root; dotfiles, ``var/*`` (except ``var/log``),
   ``config/system``, ``settings.php``/``additional.php``, key material
   and credential paths are structurally unreadable. Admin-only.

``search_code``
   Literal-substring (or opt-in regex) search across the project's source
   files, returning ``path:line`` hits. Vendor, ``var`` and dot
   directories are never searched; matched credential lines are
   value-redacted. Admin-only.

``probe_url``
   One GET against a URL of *this* instance: status, key headers, timing
   and a short body excerpt — and on a 5xx the matching exception from the
   TYPO3 logs is appended automatically. Foreign hosts and non-http(s)
   schemes are denied; redirects are reported, not followed. Admin-only.
``get_full_tca``
   The TCA index: the names and titles of all accessible tables, each with a
   pointer to ``get_table_schema``. A navigation aid so the model can traverse
   the schema without the whole (multi-megabyte) TCA being sent at once. The
   same table gates as ``get_table_schema`` apply. Optional ``filter`` and
   ``extension`` narrow the list.

``get_table_schema``
   One table's schema in a readable form: control settings plus, per field,
   its type and — for relational fields — the foreign table and relation kind
   (the value over ``get_tca``). Sensitive tables are denied for every user;
   credential-like columns show name and type only.

``get_flexform_schema``
   The data structure of a TCA FlexForm field, rendered as sheets and fields.
   When the field selects one of several structures by a pointer, the
   available keys are listed so a follow-up call can pass ``ds_pointer``. Same
   table gates as ``get_table_schema``.

``fluid_resolve``
   Which physical Fluid file backs a template, partial or layout name in an
   extension: the candidate paths in override order with an exists flag and
   the winning path — to debug a wrong or missing template. Paths only.
   (Resolves an extension's own ``Resources/Private`` paths; TypoScript
   override root paths need a live rendering context and are not reflected.)

``validate_tca``
   Structural TCA checks: ``ctrl.label``/``ctrl.type`` naming undefined
   columns, ``foreign_table`` references to unknown tables, ``showitem``
   entries referencing undefined columns or palettes. One table or all
   accessible tables; findings name schema keys, never record data.

``check_typoscript``
   Scans the TypoScript effective on a page (constants **and** setup) for
   syntax errors — invalid lines, unbalanced braces, ``@import`` matching no
   file — using the same core scanner as the backend's TypoScript module.
   Reports source and line number only, never the offending line's content
   (a constants line may carry an API key). Admin-only.

``list_extensions``
   The installed (active) extensions: key, version, composer name and
   title — no package paths. Admin-only.

``get_site_config``
   Without arguments the configured sites (identifier, base, root page);
   with ``identifier`` that site's configuration flattened to dotted
   ``key: value`` lines. Credential-like keys (camelCase included, e.g.
   ``apiKey``) render as ``[redacted]``. Admin-only.

``list_redirects``
   The redirects of EXT:redirects: uid, source host and path, target, status
   code and a disabled marker, newest first, at most 100 per call; filters
   by host or by text in the path or target. QR codes and short URLs of
   TYPO3 14 are left out. Says so when EXT:redirects is not installed.
   Admin-only (:ref:`ADR-257 <adr-257>`).

``list_scheduler_tasks``
   The scheduler tasks with next execution, disabled flag and a
   last-run-failed marker. The serialized task object is **never
   unserialized**; degrades gracefully when EXT:scheduler is absent.
   Admin-only.

``get_system_status``
   One compact block: TYPO3/PHP/database versions, application context,
   composer mode, OS family, timezone — no paths, no hostnames. Admin-only.

``list_deprecations``
   The newest distinct messages from the deprecation log, deduplicated with
   a ×count suffix and project paths relativized — the upgrade work list.
   Admin-only.

``list_middlewares``
   A PSR-15 middleware stack (frontend or backend) in execution order with
   identifiers and classes. Admin-only.

``site_rag_query``
   Curated evidence about the website's own **public** content for a
   question: source id, title, URL and match excerpt per source, retrieved
   from the best available search index — EXT:solr, ke_search,
   indexed_search or a database fallback — and labelled with the answering
   backend (:ref:`ADR-049 <adr-049>`). Index-level filtering is always
   public-only (what the anonymous visitor could read).

``site_fetch_source``
   The full indexed text behind a ``site_rag_query`` source id, capped at
   8000 characters — for reading a promising source beyond its excerpt.

``fetch_external_url``
   One public web page from the internet as readable text: title, headings,
   main text and links, wrapped as untrusted third-party content. Ships
   disabled; see :ref:`administration-tools-external-pages`.

``ask_choice``
   Asks the person one question with 2 to 10 short answers given in the call,
   and suspends the run until one is picked (``waiting_for_input``,
   :ref:`ADR-105 <adr-105>`). For the choices of a guided process that write
   nothing — which page, which finding first, whether to continue
   (:ref:`ADR-214 <adr-214>`). The answers form the ``enum`` of the input
   form, shown as a select, and a submission that is not one of them is
   refused. Typographic spaces in the question and the answers become plain
   spaces and soft hyphens are dropped; any other invisible character is
   refused by name. Reads and writes nothing; it never stands in for the approval of
   a write. Not admin-only. Ships disabled: enable it only where the client
   handles a run that waits for input — a chat that cannot answer such a
   pause ends the conversation's run as failed.

``highlight_record``
   Names the record the current point of a guided process is about, so the
   chat can show the editor where it is (:ref:`ADR-228 <adr-228>`). It
   accepts only the run's subject record — which the runtime checked the
   acting user may read before the run started — and, when that record is a
   page, a non-deleted ``tt_content`` element on it in the subject's language
   or in all languages, if the acting user may read ``tt_content``. The run
   records it as a ``highlight`` step holding the table and uid. Writes
   nothing. Not admin-only. Ships disabled: only a client that renders a
   highlight can use it.
