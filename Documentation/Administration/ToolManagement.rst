.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

=================================
Managing enabled tools and groups
=================================

.. _administration-tools-manage:

Managing tools
==============

The :guilabel:`AI > Operation > Tools` module lists every registered tool
with its global enable state and lets an admin toggle it. A **disabled** tool
is refused on every run, everywhere — the runtime gate is fail-closed, so a
disabled tool can never be offered to the model regardless of a skill's
``allowed-tools`` or the per-run selection in the playground. Some built-in
tools (for example ``get_env_raw`` and ``get_php_info_raw``) ship **disabled
by default** because they return unredacted, secret-bearing output; enable
them only deliberately.

An imported tool whose name is already registered is dropped; the existing
tool keeps its name. A failing tool provider retains the rows it supplied
before the failure, and healthy providers remain available. Optional logging
failures do not change either outcome.

A tool that is refused never reaches the model, so by itself the model cannot
tell it from a tool that does not exist. An extension that runs a chat can ask
:php:`\Netresearch\NrLlm\Service\Tool\UnavailableToolsResolverInterface` for
the tools one configuration and one backend user will NOT be offered, each
with the gate that holds it back — ``toolDisabled``, ``requiresAdmin``,
``configurationGroup``, ``configurationExcluded`` or ``trustZone`` — and pass
that to the model, which
can then say "there is a tool for that, it is not enabled for you"
(:ref:`ADR-201 <adr-201>`). A builtin tool the trust-zone gate only
observes is offered, and is not in that list; a remote (MCP) tool is always
enforced by that gate, so above the ceiling it is listed in either mode.
Remote tools are listed for administrators only.

.. figure:: /Images/ToolsModule.png
   :alt: The Tools management module listing each built-in tool with an
       Enabled or Disabled badge and an Enable/Disable toggle
   :class: with-border with-shadow
   :zoom: lightbox

   The Tools module — each registered tool with its global enable state and a
   toggle. The ``_raw`` variants show as :guilabel:`Disabled`, the redacted
   tools as :guilabel:`Enabled`; the :guilabel:`Default` badge marks a tool
   sitting at its shipped state.

A tool that carries an **editor action** declaration
(:ref:`ADR-152 <adr-152>`) reads differently in that list: it shows an icon,
its translated name, one sentence written for a human, and the record types it
addresses — instead of the wire name and the description written for the
language model. Ten of the twenty-three writing tools declare one —
``create_record_draft`` has no subject record and declares none
(:ref:`ADR-197 <adr-197>`), ``attach_file_to_record`` declares none for the
same reason (:ref:`ADR-212 <adr-212>`), and the six of
:ref:`ADR-198 <adr-198>` and ``set_file_reference_alternative_text``
(:ref:`ADR-230 <adr-230>`) and the four site tools of :ref:`ADR-257 <adr-257>`
are reached through the assistant only — and the wire name stays
visible as the technical detail the toggle acts on. A read-only tool is
unchanged.

The declaration is presentation only. It does not decide whether a tool writes
— that is the tool's declared effect — and it changes nothing about how a call
is fenced, approved or audited.

A declaration that throws leaves its row under the wire name and preserves
healthy declarations beside it. Failure to deliver its optional warning also
preserves module rendering and the tool's availability
(:ref:`ADR-152 <adr-152>`).

.. _administration-tools-groups:

Tool groups
===========

Every tool belongs to a **group** (its ``getGroup()`` value). The built-in
groups carry a translated name in the module header beside their identifier; a
group a third-party extension brings has no translated name and shows its
identifier alone. The built-in taxonomy:

=================  ============================================================
Group              Tools
=================  ============================================================
``content``        ``search_records``, ``get_page_content``, ``read_records``,
                   ``get_record_history``, ``ask_choice``,
                   ``highlight_record``
``structure``      ``get_pagetree``, ``get_tca``, ``read_fal_asset_meta``,
                   ``get_full_tca``, ``get_table_schema``, ``get_flexform_schema``,
                   ``resolve_url``, ``validate_tca``
``system``         ``get_env`` (+ raw), ``get_php_info`` (+ raw),
                   ``fetch_logs``, ``probe_url``, ``list_extensions``,
                   ``list_scheduler_tasks``, ``get_system_status``,
                   ``list_deprecations``, ``list_middlewares``
``accounts``       ``list_be_users`` (+ raw), ``list_be_groups``
``configuration``  ``get_typoscript``, ``get_tsconfig``, ``fluid_resolve``,
                   ``check_typoscript``, ``get_site_config``,
                   ``load_skill``, ``list_redirects``
``code``           ``get_last_exception``, ``read_source``, ``search_code``
``files``          ``list_fal_storages``, ``browse_fal_folder``,
                   ``search_fal_files``, ``get_fal_references``,
                   ``find_missing_files``
``rag``            ``site_rag_query``, ``site_fetch_source``
``web``            ``fetch_external_url`` — the only group that reaches hosts
                   outside the installation
``editing``        ``update_page_metadata``, ``set_page_social_image``,
                   ``set_file_alternative_text``, ``update_fal_asset_meta``,
                   ``set_file_categories``, ``attach_file_to_content_element``,
                   ``attach_file_to_record``, ``move_content_element``,
                   ``create_content_element_draft``, ``create_page_draft``,
                   ``create_translation_draft``, ``create_record_draft``,
                   ``update_content_element``, ``publish_record``,
                   ``delete_record``, ``copy_record``, ``move_page``,
                   ``replace_file_reference``,
                   ``set_file_reference_alternative_text``,
                   ``create_redirect``, ``update_redirect``,
                   ``disable_redirect``, ``clear_cache``
                   — the only WRITING group
=================  ============================================================

``load_skill`` loads an approved skill into an agent run's system message
(ADR-214). It is offered only to a run whose configuration has skills attached
as *loaded on demand* and at least one of them is an approved instruction; its
description lists those skills by uid, name and description. The skill
allow-list and the configuration's allowed tool groups do not restrict it, so
a skill declaring ``allowed-tools: []`` does not switch it off; the global tool
state and the trust zone do. Loading a skill grants no tools. It does not
require admin: a non-admin backend user's run may load a skill, because the
text it adds is an approved version an administrator vouched for, attached
to the configuration, and it widens nothing the user may do. In a run that
invokes a process skill the exemption does not hold; there the process
skill's approved declaration decides whether ``load_skill`` is offered.

Groups can be switched on three levels, and the result cascades
**fail-closed** — a tool is offered only when *every* level permits it:

#. **Centrally** in the Tools module: each group header carries an
   Enable/Disable group toggle. A disabled group refuses all of its tools —
   including same-group tools installed later — and a per-tool override can
   **not** re-enable a tool inside a disabled group (predictable,
   fail-closed). Per-tool toggles keep working but take effect only once the
   group is enabled again.
#. **Per configuration**: the :guilabel:`Allowed tool groups` field on an
   LLM configuration restricts agent runs with that configuration to tools of
   the selected groups (empty = all groups). This intersects with a skill's
   ``allowed-tools`` declaration.
#. **Per run** in the playground: the tool checkboxes are grouped, and each
   group checkbox (de)selects its children.

Below the group level, a configuration can also exclude single tools — see
:ref:`administration-tools-excluded`.

Third-party extensions declare their own group per tool; the recommended
value is the extension key, so an admin can disable an extension's whole
tool family with one toggle. The design is recorded in
:ref:`ADR-043 <adr-043>`.

.. _administration-tools-excluded:

Excluding single tools per configuration
========================================

The :guilabel:`Excluded tools` field on an LLM configuration (tab
:guilabel:`Access Control`, below :guilabel:`Allowed tool groups`) withholds single
tools from agent runs with that configuration. A configuration that may use
every tool of ``editing`` except ``delete_record`` keeps the group and excludes
that one tool. The field lists the registered tools by group; a name stored
earlier whose tool is no longer registered appears under :guilabel:`No longer
registered` and can be removed there.

An exclusion only narrows. It applies on top of the global state, the allowed
tool groups, a skill's ``allowed-tools`` and the trust zone, and can never
enable a tool any of them refuses. It also applies to ``load_skill``, which the
skill allow-list and the group gate leave alone.

* An excluded tool is not offered to the model. A call the model sends anyway
  is refused when it is executed.
* Other configurations are not affected: they keep the tool.
* A tool registered later is not excluded until it is selected here.
* A run that waits in the queue, for an approval or for input is continued
  against the exclusions it started under together with the ones that apply
  when it resumes. An exclusion added meanwhile takes the tool away; one
  removed meanwhile does not give it back to that run.

The tools a configuration excludes are shown in two places: the Tools module
names, under each tool, the active configurations that exclude it, and the
:guilabel:`Governance` tab of :guilabel:`AI > Overview` lists the excluded
tools per active configuration. The list of tools not offered to a chat
(:ref:`above <administration-tools-manage>`) reports such a tool with the
reason ``configurationExcluded``. The design is recorded in
:ref:`ADR-261 <adr-261>`.
