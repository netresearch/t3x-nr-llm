.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

=======================================
Policy profiles and retention overrides
=======================================

.. _administration-governance-asymmetry:

Two keys, opposite fallbacks
============================

``tools.dataClassEnforcement`` and ``skills.minTrustLevel`` react to a
broken value in opposite directions, and the difference is deliberate:

===============================  =========================  ================
Key                              On a broken value          Effect
===============================  =========================  ================
``tools.dataClassEnforcement``   ``enforce``                Strictest
``skills.minTrustLevel``         ``untrusted``              Most permissive
===============================  =========================  ================

Both fall towards the outcome that cannot cause damage, but "safe" points
the other way in each case. A broken *enforcement* value must not switch a
security control off, so it enforces. A broken *trust* value must not raise
a bar nobody set, because that would silently hide working skills from
prompts with no error anywhere — so it drops to the floor that removes no
skill by publisher trust. Raising enforcement never grants a tool; raising the
trust floor only ever removes skills.

For an operator this has one practical consequence: **do not infer one key
from the other.** A typo in either key leaves the tab showing a plausible
value, but a different one in each case:

- The tab reads ``enforce`` although you set ``observe`` — the stored value
  is mistyped, and the gate is removing tools.
- The tab reads ``untrusted`` although you set ``verified`` — the stored
  value is mistyped, and the publisher-trust floor excludes no skill.

In both cases the tab is right and the Install Tool field is wrong. Fix the
field; the tab follows on the next request.

.. _administration-governance-retention-overrides:

The seven retention overrides
=============================

``privacy.retentionDays`` has seven per-category overrides
(``privacy.retention.conversation``, ``.agentRun``, ``.approval``,
``.telemetry``, ``.evaluation``, ``.skillAudit``, ``.governance``). They are
omitted from the tab when their effective window equals the global window.
All seven ship as ``0``, which means "no override". A configured category
whose known effective window differs from the known global window gets its
own row. An unknown category window is omitted; if the global window is
unknown, no category override rows appear.

``0`` never means "delete immediately" — neither does a negative or
non-numeric value. Each category simply uses ``privacy.retentionDays``
until you give it a window of its own.

Set an override when one category genuinely needs a different window:

- **Conversation transcripts** are the most sensitive rows the extension
  stores, and unlike everything else their content is kept regardless of
  ``privacy.level``. A shorter window here is the usual first override.
- **Runs awaiting a decision** (``approval``) need a *longer* window than
  finished runs. A run suspended for a human approval carries the state
  needed to resume it; purging it destroys work in flight. Give it more days
  than approvers realistically need.
- **Telemetry** and **governance events** carry no prompts or responses —
  only counts, reasons and the acting user. Keeping them longer than content
  rows buys capacity trends and denial evidence at no content risk.
- **Skill audit** is the append-only provenance trail for everything that
  reached a prompt. Keep it for as long as you may have to reconstruct that.

The full table of what each override covers is in
:ref:`administration-data-retention-how-long`.

.. _administration-governance-recommended:

Recommended settings
====================

The shipped defaults are a safe posture for a new installation. Nothing has
to be set to reach it — this block only makes it explicit:

.. code-block:: text
   :caption: Default posture — Install Tool, Extension Configuration, nr_llm

   privacy.level              = metadata
   privacy.retentionDays      = 30
   tools.dataClassEnforcement = enforce
   skills.minTrustLevel       = untrusted

For an installation that handles personal data, or that runs skills from
sources it does not publish itself:

.. code-block:: text
   :caption: Stricter posture

   privacy.level                   = metadata
   privacy.retentionDays           = 7
   privacy.retention.conversation  = 3
   privacy.retention.approval      = 30
   tools.dataClassEnforcement      = enforce
   skills.minTrustLevel            = verified

What changes against the default: rows are gone within a week and
conversation transcripts within three days, while runs waiting for an
approver survive a month; skills below ``verified`` are dropped from prompt
injection and from the allowed-tools union without being deleted, so
re-classifying a source brings them back.

At global ``metadata``, completed background answers can still be stored for
later collection. Global ``none`` prevents that answer storage; an explicit
per-run ``metadata`` ceiling also suppresses it. Both global levels omit
payloads from ordinary step logs. Waiting runs still retain the state needed
to resume. See :ref:`administration-data-retention` before choosing a level
for an installation that must not retain content.

.. warning::

   Switching ``tools.dataClassEnforcement`` from ``enforce`` to ``observe``
   turns the data-class axis off **instance-wide** — every configuration,
   every run, every user. Over-ceiling tools are offered again and only
   recorded. It is a diagnostic mode, not a setting to leave in place.

   Two things stay strict regardless: the other gate axes (unregistered
   tool, disabled tool, admin-only tool, the configuration's allowed tool
   groups) always enforce, and tools from an
   :ref:`MCP server <administration-mcp-servers>` never observe — a remote
   tool above the ceiling is removed even in observe mode.

After an upgrade from a version that predates ``enforce``
---------------------------------------------------------

An upgrade wizard exists to keep such an installation on ``observe``, so
the changed default does not strip tools from a working setup
(:ref:`ADR-115 <adr-115>`). It only takes effect if you run it —
:guilabel:`Admin Tools > Upgrade > Upgrade Wizard`, or
``vendor/bin/typo3 upgrade:run`` — and only while the installation still
qualifies: at least one provider configured, and no enforcement mode stored
yet.

The second condition expires on its own, often before anyone opens the
wizard list. TYPO3 writes the shipped defaults of an extension into
``settings.php`` when ``extension:setup`` runs and again whenever an admin
enters the Install Tool. That write stores ``enforce``, and a stored value
is what the wizard reads as "the operator already chose". From then on it
reports nothing to do and disappears from the list.

So do not assume the pin happened. Open the :guilabel:`Governance` tab
after the upgrade and read the row:

``observe``
    The wizard ran, or the value was already stored — from an earlier
    explicit choice, or from the default the older version shipped. Either
    way your runs are unchanged: over-ceiling tools are offered and
    recorded.
    Work through :ref:`administration-governance-verify`, fix what
    enforcement would remove, then set ``enforce``.

``enforce``
    Nothing preserved the old behaviour. The gate has been removing
    over-ceiling tools from every run since the update, without an error
    anywhere a user would see. The removals are recorded, so you can read
    what was lost instead of guessing — see
    :ref:`administration-governance-verify`. Two ways forward: fix those
    configurations and stay on ``enforce``, or set ``observe`` while you
    work through them and switch back afterwards.
