.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

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

:guilabel:`AI > Overview`, docheader tab
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
    :php:`Service\\Governance\\DataClassEnforcementResolver` — the same
    object the gate itself asks. Only a literal ``observe`` observes; leading and
    trailing whitespace and letter case are ignored, everything else
    enforces (:ref:`ADR-113 <adr-113>`).

``skills.minTrustLevel`` (default ``untrusted``)
    The publisher-trust floor a skill's source must meet before the skill is
    injected into a prompt or may grant tools: ``untrusted``, ``community``,
    ``verified`` or ``first_party``. Read by
    :php:`Service\\Skill\\SkillComposerFactory`, which builds every skill
    composer. A missing, unreadable or unrecognised value resolves to
    ``untrusted`` — the lowest floor, which excludes no skill by publisher
    trust. Enabled state, orphan status and the composition checks still apply.
    See :ref:`administration-skills-isolation`.

.. _administration-governance-verify:

Telling whether it works
========================

The gate attempts to record every denial — and every observe-mode flag — as
a governance event carrying the tool name, the reason, the trust zone and
the ceiling. Recording is best effort: an unavailable audit database must
preserve the original denial or observation, and the affected event may be
missing. The recorder attempts a warning containing only the bounded
exception class; even a failing logger cannot replace the policy decision.
Two places show recorded events:

- The :guilabel:`Tool denials by reason` dashboard widget. The
  :guilabel:`Trust zone ceiling` bar is the data-class axis. It counts a
  rolling 30 days and has no mode filter: an observe-mode flag and a real
  removal are the same row, with the same reason. Only the event's
  ``detail`` column separates them (``observedOnly=1`` marks a flag), and
  no view reads that column.
- The :guilabel:`Governance blocks` widget for the wider picture: tool-gate
  denials, guardrail response blocks, and the guardrail's own
  ``approval_required`` verdict on a response.

  The ``invocation_denied`` bar counts calls an installation-specific rule
  refused after the tool passed the offering gate. Its ``reason`` is a bounded
  installation code, such as ``outside_site``, and ``detail`` names the rule as
  ``invocationRule``. These events also appear in the run timeline. The
  :guilabel:`Tool denials by reason` widget counts only ``tool_denied`` events,
  whose reasons belong to :php:`ToolDenialReason`; invocation rules do not
  change those counts.

  A ``write_unapproved`` bar counts writes the runtime refused because
  nothing had approved them: a pending call that reached the input-resume
  path after its tool was switched on mid-run. It is a refusal, not an
  approval record, which is why it belongs in this table.

  The ``approval_required`` bar is **not** a count of tools held for human
  approval. A tool-approval suspend writes no row in this table,
  deliberately: the approval trail lives on the run itself, under the
  ``approval`` retention window described above, because a run waiting for
  an approver carries resumable work and must outlive the telemetry-length
  window this table uses. Read it per run in the run timeline, not here.

The :guilabel:`Trust zone ceiling` bar therefore answers "how often did the
data-class axis fire in the last 30 days", not "how many tools would
enforcement remove". Use it to see
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

Use :ref:`administration-data-retention` for the authoritative persistence
matrix, :ref:`routing-reference` for ranking rules and the references below
for profiles, simulation and diagnostic columns.


.. toctree::
   :maxdepth: 1

   GovernancePolicy
   GovernanceSimulator
   RoutingDiagnostics
   AiWritten
