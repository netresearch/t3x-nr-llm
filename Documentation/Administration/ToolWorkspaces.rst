.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _tool-workspaces:

===============================
Tools, languages and workspaces
===============================

A managed run records the initiating user's workspace. Supported tools read
and write that workspace even when a different user decides the approval or
a worker executes it. Current permissions and configuration are checked again;
an approval does not grant additional record access.

.. list-table:: Current workspace support
   :header-rows: 1
   :widths: 24 45 31

   * - Family
     - Tools
     - Draft workspace behavior
   * - Field writers
     - ``update_content_element``, ``update_page_metadata``,
       ``set_file_reference_alternative_text``, ``set_file_alternative_text``,
       ``update_fal_asset_meta``, ``set_file_categories``, ``publish_record``
     - Write a workspace version. Unhiding there does not publish it live.
   * - Creating writers
     - ``create_content_element_draft``, ``create_page_draft``,
       ``create_record_draft``, ``attach_file_to_content_element``,
       ``attach_file_to_record``
     - Create in the run's workspace. Unversioned tables are refused.
   * - Structural writers
     - ``move_content_element``, ``move_page``, ``copy_record``,
       ``delete_record``, ``create_translation_draft``
     - Use core's workspace commands, with the restrictions below.
   * - Reference replacement
     - ``set_page_social_image``, ``replace_file_reference``
     - Version the page or element, add the new reference in the run's
       workspace and mark the replaced one for deletion there.
   * - Redirect writers
     - ``create_redirect``, ``update_redirect``, ``disable_redirect``
     - Live only because ``sys_redirect`` is not versioned.

The read tools for records, page structure and file metadata use the run's
workspace view. They do not treat another workspace's version UID as a live
record identity. Missing recorded workspace on legacy runs does not mean the
editor's currently selected draft workspace.

.. _tool-workspaces-limits:

Limits to review before approval
================================

- File-default metadata writers address the default-language metadata;
  they do not translate it. A file-reference alternative text belongs to one
  use of an image, rather than changing its file default.
- A draft becomes public only through the workspace's publication workflow.
  Core's file views outside frontend preview can still show live metadata.
- A create that would renumber live sorting while working in a draft is
  refused; choose another position or make room in live first.
- Structural calls refuse cases where core would perform a different action:
  deleting new or moved drafts, copying a page with unversioned records,
  overwriting an existing translation, and the documented TYPO3 13.4
  translation-deletion conflict.
- Replacing or removing a reference that exists only as a draft in the
  workspace discards that draft; it never was live and is not kept in the
  recycler.
- Refusal before approval is distinct from a call waiting for approval. It
  stays refused for that turn even if an earlier call removes the reason.
- Copy readback checks the main record and returned translations. It does not
  prove every inline child or reference was copied. A valid hidden main copy
  can survive a core complaint about another copied record.
- A core translation copy can be visible briefly before the second write
  hides it. Do not interpret the hidden-draft workflow as zero visibility at
  every intermediate instant.

These controls do not establish complete isolation of all installation hooks,
TSconfig or global actor-dependent behavior. Read the approval card and test
custom DataHandler integrations in the supported workspace before enabling
writers. The decision history is in :ref:`adr-260`, :ref:`adr-262`,
:ref:`adr-264`, :ref:`adr-266` and :ref:`adr-274`.
