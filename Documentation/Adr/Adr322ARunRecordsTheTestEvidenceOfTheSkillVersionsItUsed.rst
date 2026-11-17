.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-322:

=========================================================================
ADR-322: A run records the test evidence of the skill versions it used
=========================================================================

:Status: Accepted
:Date: 2026-11-17
:Authors: Netresearch DTT GmbH

Context
=======

A run names the skill versions each model call carried (:ref:`ADR-321
<adr-321>`). Whether a version had been tested, and on which model, was not
recorded anywhere. A completion evaluation identifies its benchmark by a
fingerprint over its prompts, settings, grading and the effective requests
(:ref:`ADR-271 <adr-271>`). A changed skill changes that fingerprint, but the
evaluation did not say which skill versions it had measured, so nothing tied
an evaluation to a version or a version to the evaluations that covered it.

Two premises bound what can be built:

- Evaluations run one-shot completions through the default configuration
  (:php:`EvaluationService::run()` calls the completion service, which calls
  :php:`LlmServiceManager::chat()`), never the agent loop. The versions they
  can measure are the ones that configuration composes into a prompt. A skill
  loaded on demand during a run is never measured.
- Evaluation results and agent runs have separate retention
  (:php:`PurgePrivacyDataCommand`). A run cannot point at an evaluation and
  expect it to be there later.

Decision
========

1. **An evaluation names what it measured.** :php:`LlmServiceManager::chat()`
   puts the skill versions its composition gave the request, and the
   configuration they came from, on the response's metadata
   (:php:`ComposedSkillVersions`), after the call and outside the provider
   pipeline. The evaluation stores them when every prompt's request carried
   the same versions; otherwise it names none and stands as evidence for none.
   So does an evaluation whose gradings do not share one yardstick, or none of
   whose decisions could be made: it is stored, but measured nothing a run
   could rely on. It also stores the configuration identifier and the version
   of its grading protocol (``tx_nrllm_eval_skill_version``, two columns of
   ``tx_nrllm_eval_result``). Identities and digests only.
2. **A run records the evidence that applied, when it applied.** After the
   answer of the model call that first carried a version, the loop records a
   ``skill_evidence`` step (:php:`RunTrace::recordSkillEvidence()`, additive
   ``@api``): for each version, the evaluations stored at that moment that
   measured exactly its digest, newest first, at most three
   (:php:`SkillEvidenceLookupInterface::LIMIT`). An empty list states that
   none applied. The step names composed versions only: a version loaded
   during the run is left out, since no evaluation measured it loaded, even at
   the same digest, and so is a version a resumed segment carries over, which
   the segment that composed it named if it was composed. The usage step's
   inclusion (:ref:`ADR-321 <adr-321>`) says which a left-out version was.
   Nothing is looked up later to fill in or replace it. A lookup that fails
   (for example before the database analyzer created the table) is logged and
   records no evidence step for that call: the run goes on, and the missing
   step reads apart from an empty list.
3. **Judged against the model that answered.** Each evaluation is marked
   ``match`` when the model the provider named on the call's answer is the
   evaluated model, ``other_model`` otherwise (for example after a fallback),
   ``unknown`` when either side names no model. Providers are not compared:
   the answer names its adapter, the evaluation its provider record.
4. **A snapshot, kept apart from the approval.** The run stores the
   evaluation's facts — uid, run date, set, benchmark fingerprint,
   configuration, provider, model, reported model, grader, protocol, prompt
   and pass counts, pass rate, mean score, scope. The usage entry keeps the
   approval; the evidence step keeps the measurement.
5. **Gone, never replaced.** The run timeline shows the recorded snapshot.
   Where the evaluation it came from is no longer stored — the same uid with
   the same run date, fingerprint and set — it adds ``report=gone``; where
   that check cannot be made, ``report=unknown``.

Consequences
============

●● For a run, an administrator reads which evaluations had measured the skill
versions it used, at that time, and whether they were measured on the model
that answered.

● A fallback to another model on the call that first carried the composed
versions shows as ``other_model`` on their evidence. A fallback on a later
call records no evidence.

● A later evaluation of the same version, or of a newer version, does not
change what an earlier run recorded.

◐ The tables hold identities, digests and figures; they follow the evaluation
results' retention and are purged with them.

✕ Skills loaded on demand, and anything a run does beyond a one-shot
completion — tool choice, write proposals, multi-step processes — are never
covered, because no evaluation runs the agent loop. They are left out of the
evidence, and the scope of every evidence is ``completion``.

◑ Evidence matches by skill and digest, not by configuration or role: an
evaluation through the default configuration counts for a run under another
configuration, whose system prompt or other skills may differ. The snapshot
stores the evaluation's configuration.

◑ An evaluation whose prompts disagreed on the versions they carried, or ran
with a pinned provider and therefore without the configuration's skills,
covers no version.

◐ Whoever may read a run sees its evidence: evaluation uid, set, model,
pass rate and fit, also where the evaluation module itself is for
administrators only. These are figures about a model, not personal data, and
the same reader already sees the run's skill identifiers and model.

◑ At most three evaluations per version are recorded, the newest stored at the
time. Older ones are not.

◑ Decision-graded evaluations are recorded like the others; their comparable
revision stays unknown (:ref:`ADR-271 <adr-271>`). Identical versions, model
and input still do not guarantee identical answers.
