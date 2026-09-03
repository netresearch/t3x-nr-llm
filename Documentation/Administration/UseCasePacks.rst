.. include:: /Includes.rst.txt

.. _administration-usecase-packs:

===========================
Get Started: use-case packs
===========================

:guilabel:`AI > Setup > Get Started` asks what you want to do before it
asks anything technical — editorial assistance, translation, metadata, media
accessibility, agent workflows, developer integration — and answers with a
**use-case pack**: a named bundle of one configuration, several tasks and
several prompt snippets, plus the governance posture and the tool groups the
pack was written for.

The module is admin-only. The records it creates are ordinary records an
administrator could create by hand; nothing marks them as pack-owned.

*Editorial Starter* is the only pack currently shipped. The other five use cases
are listed with a "no pack yet" note and a link to the
:ref:`setup wizard <administration-wizards-setup>`, which stays the technical
route and is linked from every screen here.

.. _administration-usecase-packs-install:

What an install creates
=======================

Pick a use case, then a pack, and the plan screen lists every record the pack
declares with its identifier and its current state — *Would be created* or
*Already there*. Nothing is written until you press
:guilabel:`Create the missing records`.

*Editorial Starter* declares:

.. list-table::
   :header-rows: 1

   * - Record
     - What it is
   * - Configuration ``nr_llm.editorial_starter``
     - Low temperature, room for a medium-length article, requires nothing but
       the ``chat`` capability — so it installs against a local Ollama as
       readily as against a hosted provider. It sets no tool group restriction
       of its own.
   * - Four tasks
     - Summarise for a teaser, rewrite for clarity, proofread, suggest
       headlines. Each takes the text through ``{{input}}``.
   * - Two snippets
     - *House style* (tag ``tone_of_voice``) and *Target audience* (tag
       ``audience``). Both are meant to be edited — they are the pack's
       placeholders for your own voice.

The pack's configuration is a :ref:`configuration preset <adr-056>`, not a
second kind of record, so it also appears in the Configuration module's
:guilabel:`Pending presets` card and can be imported there instead.

.. _administration-usecase-packs-tags:

The snippet-tag link, and what it pulls in
==========================================

A configuration composes the active snippets carrying any of its tags
(:ref:`ADR-031 <adr-031>`). Installing therefore **adds** the pack's snippet
tags to its configuration's selection — without them the pack's snippets would
exist and be read by nothing. Tags you selected yourself are kept; nothing is
removed, and a tag already selected is written again by no install.

Because selection is by tag and not by owner, that link reaches in two
directions, and the plan screen names both before you confirm:

Outwards
   Existing configurations that already select one of the pack's tags compose
   the pack's new snippets into their prompts as well. They are listed by name
   and identifier.

Inwards
   Existing snippets that already carry one of the added tags are composed into
   the *pack's* configuration. They are listed with their **data class**,
   because that is the part that can stop the configuration from working: input
   context is classified by the strictest data class among the composed
   snippets, so one confidential snippet raises the whole configuration, and an
   enforcing input-context gate then refuses every send through it.

To keep a snippet out, deactivate it or remove the tag from it before
installing. To keep another configuration unchanged, edit its snippet-tag
selection first, or rename the pack's tags afterwards.

A pack may also ship snippets that are **not** linked this way
(:ref:`ADR-186 <adr-186>`): ones the declaring extension looks up itself, for
one call, instead of leaving them to tag composition. They are installed as
ordinary snippet records and appear in the Snippets module like any other, but
they contribute no tag to the configuration and reach no prompt through it — so
they show up in neither of the two directions above. `nr_repurpose` is the
example, and it declares every one of its snippets that way: audience, tone of
voice, persona, layout and style are each chosen per job in the job form, so
composing them all into every completion would be a defect rather than a
feature.

You can still point a configuration at them: select the tag on that
configuration in the Configurations module. The pack's declaration describes
what it installs, not what you are allowed to do with the record afterwards.

.. _administration-usecase-packs-recommend:

What a pack recommends but never applies
========================================

- **Governance posture.** The pack names the posture its content was written
  for (*Editorial Starter*: controlled cloud). Installing changes no governance
  value; the screen links to the :ref:`governance readout
  <administration-governance>` where the posture in force is shown.
- **Tool groups.** The pack names the groups its tasks benefit from
  (*Editorial Starter*: ``content``). Enabling a tool group stays an
  administrator decision in the :ref:`Tools module <administration-tools>`.
- **Editor actions.** The pack names the editorial writes it was designed for —
  see below. Installing enables none of them and runs none of them.
- **Nothing about providers or API keys.** The pack states model
  *requirements*. When no active model satisfies them, the plan says which
  requirement is missing and links to the setup wizard.

Both the tool groups and the editor actions are shown with their **current
state** rather than as a bare list, because both are switches you may still have
to throw:

.. list-table::
   :header-rows: 1

   * - Badge
     - Meaning
   * - *Enabled*
     - Registered here and switched on. For a tool group that is all it takes.
       An editor action additionally needs a **default configuration** the
       acting editor has access to — a pack installs none, and without one the
       action stays absent from the Editor Action Center even while this badge
       reads *Enabled*.
   * - *Disabled*
     - Registered here and switched off. Enable it in the
       :ref:`Tools module <administration-tools>` if you want it.
   * - *Not available here*
     - Not registered at all: the extension providing it is not installed, or
       the pack names it wrongly. Installing the pack neither adds it nor
       repairs it.

A pack installs **no skills**. A skill carries provenance and a trust level
from the source it was synced from, and a pack can produce neither; add a
:ref:`skill source <administration-skills>` instead.

.. _administration-usecase-packs-editor-actions:

Editor actions a pack is designed for
=====================================

A pack installs **tasks**, and a task runs as a plain completion — it produces
text you copy or apply yourself. The named editorial writes an editor triggers
from a record (set a file's alternative text, create a translation draft, update
page metadata) are **tools**, offered through the
:ref:`Editor Action Center <administration-tools-editor-actions>`, disabled by
default, enabled by an administrator, and paused for approval on every run.

``recommendedEditorActions`` lets a pack say which of those it was built for, so
a pack whose value depends on one — translation, alt text — can state it instead
of implying a workflow its records cannot perform. For each declared action the
plan screen shows the action's name and description, whether this installation
has it, whether it is enabled, which record types it applies to and the tool
group it sits under — that group is where its switch is in the Tools module —
with links to the Tools module and the Editor Action Center.

That is the whole feature. The plan does not enable an action, does not run one,
and does not connect a pack task to one — a task-to-action execution contract
would need a new execution path and is a separate decision
(:ref:`ADR-168 <adr-168>`).

*Editorial Starter* declares no editor action, deliberately: its four tasks are
text transforms, and an editor action runs on the **default** configuration
rather than on the pack's, so the pack's house-style snippet would not reach
one.

.. _administration-usecase-packs-reinstall:

Installing again
================

"Already installed" means *a record with that identifier exists* — nothing more.
The installer never overwrites and never compares contents, so:

- A task you renamed or whose prompt you rewrote is left exactly as it is.
- A record you **disabled** still counts as installed, so a second install
  cannot quietly resurrect what you switched off.
- A tag the configuration already selects is not written again.

A second install therefore reports records created: 0. The one case where it
still has work is a configuration created by importing the preset in the
Configuration module: its records exist but its snippet-tag selection does not
yet include the pack's tags, so the confirm button stays offered and the
success message names the tags it added.

A pack cannot be uninstalled. Nothing marks a record as pack-owned — that is
what makes the installed records ordinary — so removing them is deleting
records, one by one, like any other.

.. _administration-usecase-packs-cli:

Installing without the backend
==============================

A container entrypoint, a deploy step or a CI provisioning run has no backend
session to confirm a plan screen with. The same install is available on the
command line:

.. code-block:: bash
    :caption: Install a pack from a provisioning script

    vendor/bin/typo3 nrllm:usecasepack:install editorial-starter

It writes through the same installer the module uses, so it creates only what
is missing and reports what was already there. Being idempotent is what makes
it safe in a step that runs on every deploy — and it is the answer to an
instance rebuilt from a database seed that predates the pack, which would
otherwise come up with an empty snippet library.

Pass an identifier the installation does not declare and the command lists the
ones it does.

What it does not do is the half the installer refuses too: it enables no tool
group, enables no editor action and applies no governance profile. Those stay
decisions made in their own modules — see
:ref:`administration-usecase-packs-recommend`.

The full rationale is in :ref:`ADR-163 <adr-163>`, extended by
:ref:`ADR-186 <adr-186>`.
