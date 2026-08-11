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

.. _administration-governance-verify:

Telling whether it works
========================

The gate records every denial — and every observe-mode flag — as a
governance event carrying the tool name, the reason, the trust zone and the
ceiling. Two places show them:

- The :guilabel:`Tool denials by reason` dashboard widget. The
  :guilabel:`Trust zone ceiling` bar is the data-class axis. It counts a
  rolling 30 days and has no mode filter: an observe-mode flag and a real
  removal are the same row, with the same reason. Only the event's
  ``detail`` column separates them (``observedOnly=1`` marks a flag), and
  no view reads that column.
- The :guilabel:`Governance blocks` widget for the wider picture, including
  guardrail blocks and approvals.

The bar therefore answers "how often did the data-class axis fire in the
last 30 days", not "how many tools would enforcement remove". Use it to see
*that* the axis fires. Take the number you act on from the table.

The list you actually need is one row per configuration and tool, and it
comes from ``tx_nrllm_governance_event``:

.. code-block:: sql
   :caption: What the data-class axis did, per configuration and tool

   SELECT configuration_identifier, tool_name, COUNT(*) AS events
   FROM tx_nrllm_governance_event
   WHERE decision = 'tool_denied'
     AND reason = 'trustZone'
     AND detail LIKE '%observedOnly=1%'
     AND crdate >= 1767225600
   GROUP BY configuration_identifier, tool_name;

``observedOnly=1`` gives you what enforcement *would* remove;
``observedOnly=0`` gives you what it *did* remove. ``crdate`` is Unix time
— set it to the moment the period you care about started, the switch to
``observe`` or the upgrade.

Each row names one configuration and one tool the axis acted on, and that
pairing is what you fix. Three ways to change it: raise the trust zone on
the provider record, remove an external fallback that drags the
configuration's reachable zone down to the external ceiling, or drop the
tool from the configuration's allowed groups.

So moving a long-running installation to ``enforce`` goes like this: note
the time, set ``observe``, let a representative workload run, run the query
for the observation window, fix the configurations it names, set
``enforce`` again. The bar is at its least trustworthy during exactly this
procedure — while you observe it still carries the enforce-mode removals of
the preceding 30 days, and after you switch back it carries the observe
flags for another 30. The query is unaffected, because it filters on both
the flag and the window.

Governance events are purged on ``privacy.retention.governance`` — make
sure that window is longer than your observation period, or the evidence is
gone before you read it.

.. _administration-governance-routing:

Why this model?
===============

The same tab answers the other question an operator asks about a
configuration: which model would actually serve a call through it, and why
not one of the others. Pick a configuration, optionally the operation the
call runs, optionally a policy mode to try, and press
:guilabel:`Explain` (:ref:`ADR-148 <adr-148>`).

The answer comes from :php:`Service\\Routing\\RoutingDecisionService` — the
decision point the runtime itself uses, not a second implementation of the
ranking. It reports:

- the selected model, and the eligible candidates in the order they were
  ranked, each with its score and the per-signal values behind it;
- every refused candidate with the reason it was refused — a missing
  capability, an excluded adapter type, a context window below the minimum,
  a cost above the ceiling, or a declared capability set without the one the
  operation needs;
- the effective policy mode, and whether the operation-capability axis is
  enforcing or only observing.

Three things are worth knowing before reading it:

**A fixed-mode configuration is not a decision.** If the configuration names
its model, nothing is chosen at call time. The tab says so instead of
presenting the named model as the winner of a one-candidate ranking — there
are no criteria to debug in that case.

**A signal without data is not a zero.** ``no data`` means nothing was
measured for that model. It neither promotes nor demotes: the score is the
weighted mean over the signals that *do* have data
(:ref:`ADR-142 <adr-142>`). In :guilabel:`Provider priority` mode no signal
is collected at all, and the ordering falls through to provider priority and
the established tiebreaks.

**Trying a policy mode changes nothing.** The mode selector evaluates a
hypothetical for that one page view. ``routing.policyMode`` in the Install
Tool is not written and not affected — the same read-only rule the rest of
the tab follows.

Only operations that actually constrain the decision are offered. The others
map to no required capability, so they would add nothing to the answer.

An empty result is reported in two distinguishable ways, because they need
opposite fixes: :guilabel:`No candidates at all` means the catalogue holds no
active model, while a populated :guilabel:`Refused, and why` table means the
criteria and the model records disagree.

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
stored value, and the upgrade wizards read that distinction to tell "the
operator chose this" from "nobody ever set it". The core synchronisation
already erases it on its own the first time an admin enters the Install
Tool, so the apply button would not cause that loss — it would make it
unconditional.

The Install Tool owns the write, the synchronisation and the cache flush.
The tab reports what is in force.
