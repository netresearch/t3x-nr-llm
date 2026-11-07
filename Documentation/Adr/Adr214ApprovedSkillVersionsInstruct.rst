.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-214:

===========================================================================
ADR-214: Approved skill versions instruct, load on demand and run processes
===========================================================================

:Status: Accepted (item 10 amended: the API shape and the lineage store;
    a running process governs sequence and answer structure — see the
    :ref:`amendment of 2026-10-22 <adr-214-amendment-process-precedence>`;
    "keep" is a third denial reason — see the
    :ref:`amendment of 2026-10-24 <adr-214-amendment-keep-reason>`; a
    proposal carries a length only against a configured range — see the
    :ref:`amendment of 2026-10-30 <adr-214-amendment-length-only-with-range>`;
    the reads beside a question run before a process pauses — see the
    :ref:`amendment of 2026-10-31 <adr-214-amendment-reads-beside-a-question>`)
:Date: 2026-10-08
:Amended: 2026-10-18 by :ref:`ADR-228 <adr-228>`; 2026-10-25 by
    :ref:`ADR-229 <adr-229>` (items 1 and 3: the frontmatter's ``page`` is
    stored and editable like the process marker, but is not part of the
    version)
:Amends: :ref:`ADR-036 <adr-036>` (item 3 and the tail drop of item 5, for
    skills approved as instructions; item 6: the integrity check compares a
    version digest over body and frontmatter fields, kept in a new column);
    :ref:`ADR-035 <adr-035>` (items 4 and 5: change detection compares that
    version digest, so a frontmatter-only change is a change);
    :ref:`ADR-061 <adr-061>` (items 1 and 4: the trust level also decides the
    framing, and backend-authored skills are admitted by their source's
    level); :ref:`ADR-038 <adr-038>` (item 5: the allow-list is resolved once
    per run over every effective attached skill); :ref:`ADR-031 <adr-031>` (a
    caller's system message keeps snippet text an administrator marked, on
    configurations that opt in); :ref:`ADR-200 <adr-200>` (a denial carries an
    enumerated reason); :ref:`ADR-165 <adr-165>` (resume also re-reads the
    invoked skill and intersects the stored allow-list);
    :ref:`ADR-169 <adr-169>` (section 4: the exclude list grows by the skill
    and skill-source fields of item 3); :ref:`ADR-084 <adr-084>` (in a run
    holding a process pin, in a turn that suspends for approval, the turn's
    non-remote reads that need no approval execute before the suspend and
    every other call is refused); :ref:`ADR-105 <adr-105>` (in such a run, a
    turn that pauses for input settles the calls beside the question the same
    way); :ref:`ADR-136 <adr-136>` (an approval-bound
    write tool also returns its pending target as structured values next to
    the preview); :ref:`ADR-130 <adr-130>` (the approve grant does not open a
    run holding a process pin); :ref:`ADR-172 <adr-172>` (on such a run the
    four-eyes pin rule is checked before the self-approval refusal and stops
    the run); :ref:`ADR-182 <adr-182>` (``withWriteTarget()`` takes a
    completeness, and the write step carries it and a hook-failure flag); :ref:`ADR-150 <adr-150>`
:Authors: Netresearch DTT GmbH

Approved skill versions may supply instructions, load on demand and pin a
process across runs. Approval applies to a version digest over body and
frontmatter; a manifest body fingerprint alone is not approval or a publisher
signature. Later amendments refine lineage and process precedence.

Use these current guides:

- Approve or revoke a version: :ref:`administration-skills-approval`.
- Attach and load skills: :ref:`administration-skills-attach`.
- Start, resume, cancel or end a process: :ref:`api-agent-runtime`.
- Review write proposals and partial results: :ref:`administration-tools`.
- Integrate stored conversations: :ref:`api-conversations`.

The runtime rechecks the acting user, configuration, skills and tool policy.
A process pin does not bypass permissions. Token admission uses a local
estimate, not a guarantee that a provider will accept a request.
``AgentRuntimeInterface::endProcess()`` explicitly ends a process pin; this
is no longer an open product question.

The appendices retain the original context, detailed decisions, amendments
and alternatives so that existing section links and reasoning remain usable.

.. toctree::
   :maxdepth: 1

   History/Index
