.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _editorial-profile:

==============================
Editorial profile: drafts only
==============================

An editorial configuration lets editors have text, metadata and structure
changed by the assistant while every change stays a draft they review in
TYPO3's workspace module. The setting :guilabel:`Require a draft workspace for
changes` on the :guilabel:`Access` tab of a configuration turns this on. It is
off by default, and a configuration that never had it behaves as before.

What the setting does
=====================

With the setting on, a write by a run that does not work in a draft workspace
is refused. This holds for administrators too: the test is the workspace the run
works in, never the administrator flag. Reads are not affected.

The run's workspace is the one the editor worked in when they sent the request
(:ref:`tool-workspaces`). It does not follow a later switch of the editor
mid-run. A request sent in the live workspace is therefore refused for writes,
and so is a request from before workspaces were recorded.

The refusal comes early:

#. Before an approval card is shown. A write the setting refuses does not ask
   the editor for approval; the assistant answers with the refusal instead.
   Beside a call that still waits for approval, the refused call stays on the
   card, says that it will be refused, and is refused when the turn is approved,
   whatever the settings say by then.
#. Right before every call runs, including each call of a run that resumes
   after an approval or an answer. A setting switched on, or access to the
   workspace withdrawn, while a run waits refuses the approved call.

The editor reads, in their backend language, that the configuration requires a
draft workspace, that nothing was changed, and that switching to a draft
workspace and sending the request again is the way on. The run timeline and the
governance log record an ``invocation_denied`` event. When the call is refused
as it runs, the event names the rule ``draft_workspace_required`` and one of
three reasons: ``live_workspace`` (the run works live, or recorded no
workspace), ``workspace_unavailable`` (the run recorded a draft workspace its
user cannot use any more, or has no acting user) and ``remote_write`` (see
below). A refusal that was already shown on a card, or that was settled before
the call ran, is recorded with the reason ``refused_on_card`` or
``refused_for_skipped_approval`` instead; the rule behind it is the one on the
card.

Publishing stays a separate step
================================

Nothing publishes a workspace. A draft becomes public through TYPO3's
workspace publication workflow. ``publish_record`` clears the hidden flag of one
page or content element; under this setting that happens only in a draft
workspace, where it is a workspace version that stays a draft too. It has its
own entry and heading on the approval card, but it can be proposed in the same
turn as an edit, and a turn is approved as one. An editorial configuration that
must never offer it excludes it by name.

Recommended configuration
=========================

#. Switch :guilabel:`Require a draft workspace for changes` on.
#. Name the tool groups the profile needs under :guilabel:`Allowed tool groups`
   and exclude single tools by name under :guilabel:`Excluded tools`. The two
   lists are the configuration's tool list. They are enforced when the run
   starts and again when it resumes. It is a list by group plus exclusions, not
   a fixed list by name: a tool registered later inside an allowed group is
   offered unless it is excluded.
#. Exclude ``publish_record`` where the assistant must not unhide anything.
#. Expect that tools which write outside TYPO3's workspaces, MCP server tools
   and remote agents, are refused in every workspace under this setting, with
   the reason ``remote_write``: a draft workspace cannot hold what they write,
   and MCP tools declare a write whatever they do. A configuration that needs
   them stays without the setting. The redirect writers (``create_redirect``,
   ``update_redirect``, ``disable_redirect``) write live only; with this setting
   they never run, in live because the rule refuses them and in a draft
   workspace because they refuse themselves. Exclude them too.
#. Switch :guilabel:`Require a second approver` on as well where the approval is
   an audit control; it is off by default and independent of this setting.

What it does not do
===================

- It does not restrict a run to one page and its content. That is a separate
  control, set by whatever starts the run: :ref:`assignment-scope`.
- It does not judge tools that declare no effect. They count as reads, as
  everywhere else; a tool that writes declares it.
- It does not cover installation hooks that write outside the workspace
  (:ref:`tool-workspaces-limits`), nor writers that do not go through the
  assistant's tool loop, such as the field fill writer.

The scope of an assignment is :ref:`assignment-scope`. For a developer rule that is asked before the card, see
:ref:`api-tool-invocation-policy`. The decision is in :ref:`adr-293`.
