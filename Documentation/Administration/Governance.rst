.. include:: /Includes.rst.txt

.. _administration-governance:

================
Effective policy
================

Four extension configuration keys decide what an installation allows: how
much request content it stores, how long it keeps it, whether the tool gate
removes an over-ceiling tool or only records it, and which skills may reach
a prompt. They sit in three different sections of the Install Tool and are
read by three different services at runtime. The :guilabel:`Governance` tab
puts the values in force on one page.

.. _administration-governance-where:

Where the values are shown
==========================

:guilabel:`Admin Tools > LLM > Overview`, docheader tab
:guilabel:`Governance` (admin-only, like every other nr_llm admin surface).
The table has three columns: the setting, the value the runtime applies
right now, and the class that resolved it.

Two properties matter when reading it:

- **The value is what happens, not what is stored.** Each row is read
  through the same resolver the runtime uses, so the tab cannot drift from
  behaviour. A mistyped ``tools.dataClassEnforcement`` reads ``enforce``,
  because that is what the gate applies to the next run.
- **A row that cannot be answered reads** ``unknown``. It is never filled in
  from a shipped default. On a working installation every row is answered;
  the guarantee exists so a value shown here is always one the runtime would
  apply.

The tab changes nothing. All four keys are instance-wide and are set in
:guilabel:`Settings > Extension Configuration > nr_llm`
(:ref:`why <administration-governance-no-apply>`).

.. _administration-governance-keys:

The four keys
=============

``privacy.level`` (default ``metadata``)
    How much request content is written to the log tables — ``none`` and
    ``metadata`` drop the payload, ``redacted`` masks and caps it, ``full``
    stores it verbatim. Read by
    :php:`Service\\Privacy\\PrivacyPolicy`, which every content sink asks
    before it writes. An unrecognised or unreadable value resolves to
    ``metadata``: the installation stores less than you asked for, never
    more. See :ref:`administration-data-retention-what-is-stored`.

``privacy.retentionDays`` (default ``30``)
    The window after which ``nrllm:privacy:purge`` deletes a row. Read by
    the same :php:`PrivacyPolicy`. Empty, zero, negative or non-numeric
    falls back to 30 days — a window of ``0`` never means "delete
    immediately". Nothing is deleted until the purge command actually runs
    (:ref:`administration-data-retention-purging`).

``tools.dataClassEnforcement`` (default ``enforce``)
    Whether a tool whose data class exceeds the trust zone of the provider a
    run can reach is removed from that run (``enforce``) or still offered
    and merely recorded (``observe``). Read by
    :php:`Service\\Tool\\DataClassEnforcementResolver` — the same object the
    gate itself asks. Only a literal ``observe`` observes; leading and
    trailing whitespace and letter case are ignored, everything else
    enforces (:ref:`ADR-113 <adr-113>`).

``skills.minTrustLevel`` (default ``untrusted``)
    The publisher-trust floor a skill's source must meet before the skill is
    injected into a prompt or may grant tools: ``untrusted``, ``community``,
    ``verified`` or ``first_party``. Read by
    :php:`Service\\Skill\\SkillComposerFactory`, which builds every skill
    composer. A missing, unreadable or unrecognised value resolves to
    ``untrusted`` — the lowest floor, at which every enabled skill passes.
    See :ref:`administration-skills-isolation`.

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
prompts with no error anywhere — so it drops to the floor at which nothing
is hidden. Raising enforcement never grants a tool; raising the trust floor
only ever removes skills.

For an operator this has one practical consequence: **do not infer one key
from the other.** A typo in either key leaves the tab showing a plausible
value, but a different one in each case:

- The tab reads ``enforce`` although you set ``observe`` — the stored value
  is mistyped, and the gate is removing tools.
- The tab reads ``untrusted`` although you set ``verified`` — the stored
  value is mistyped, and every enabled skill is passing.

In both cases the tab is right and the Install Tool field is wrong. Fix the
field; the tab follows on the next request.

.. _administration-governance-retention-overrides:

The seven retention overrides
=============================

``privacy.retentionDays`` has seven per-category overrides
(``privacy.retention.conversation``, ``.agentRun``, ``.approval``,
``.telemetry``, ``.evaluation``, ``.skillAudit``, ``.governance``). They are
**not** on the tab: all seven ship as ``0``, which means "no override", so
on an untouched installation eleven rows would repeat the global window
eight times.

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

``privacy.level`` stays at ``metadata`` on purpose. ``none`` is not stricter:
both drop the content payload and keep the metadata, and no code path treats
them differently. Only ``redacted`` and ``full`` write content at all — pick
either deliberately, and never on an installation that must not hold request
content.

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

An installation upgraded from a version that predates the ``enforce``
default was pinned to ``observe`` by an upgrade wizard, so the flip did not
strip tools from a working setup (:ref:`ADR-115 <adr-115>`). If the tab
shows ``observe`` on such an installation, that pin is why. Review what
enforcement would remove, then set ``enforce``.

.. _administration-governance-verify:

Telling whether it works
========================

The gate records every denial — and every observe-mode flag — as a
governance event carrying the tool name, the reason, the trust zone and the
ceiling. Two places show them:

- The :guilabel:`Tool denials by reason` dashboard widget. The
  :guilabel:`Trust zone ceiling` bar is the data-class axis. In ``observe``
  it counts what enforcement *would* have removed; in ``enforce`` it counts
  what it did remove. The event's detail field distinguishes the two
  (``observedOnly=1`` for an observed flag).
- The :guilabel:`Governance blocks` widget for the wider picture, including
  guardrail blocks and approvals.

So the way to move a long-running installation to ``enforce`` is: switch to
``observe``, let a representative workload run, read the trust-zone bar,
fix the configurations that would lose a tool, switch back. Governance
events are purged on ``privacy.retention.governance`` — make sure that
window is longer than your observation period.

.. _administration-governance-no-apply:

Why there is no apply button
============================

The page is read-only on purpose (:ref:`ADR-140 <adr-140>`), not unfinished.
TYPO3 offers exactly one API for writing extension configuration, and it is
marked internal, writes the whole merged array back at once rather than a
single key, and is explicitly documented as unreliable when ``additional.php``
overrides a setting. An apply button would therefore report success while the
next request still served the old value — the worst thing a governance page
can do. It would also materialise every shipped default as an explicitly
stored value, which destroys the "did anyone ever choose this?" distinction
the upgrade wizards depend on.

The Install Tool owns the write, the synchronisation and the cache flush.
The tab reports what is in force.
