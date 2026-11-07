.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _responsible-ai-gaps:

=============================
Gaps, owners and acceptance
=============================

This register distinguishes code defects, integration work and missing
operating evidence. **P1** means address before relying on the affected claim;
**P2** means planned improvement or application qualification. A P2 item becomes
P1 when the application promises that property or its assessed risk requires
it. Roles must be assigned to named owners in a deployment.

The two implementation defects below were identified by source inspection at
0.43.20. Their corrections are implemented with the accompanying ADRs. They
do not close the independent integration and operating work.

.. _responsible-ai-rai01:

RAI-01: apply the privacy of attempted providers
==============================================

**P1 / implementation / nr-llm maintainers.** At the assessment baseline,
:php:`RunPrivacyLevelResolver::forRun()` joins the configuration's fixed model;
criteria selection and fallback can execute another provider. A returned
generator identity misses failed attempts that already received the request.
An explicit provider/task ``metadata`` rule must also be distinguishable from
the global default, which allows an encrypted background answer.

**Correction:** :ref:`ADR-270 <adr-270>`. Tighten durable run policy before
dispatch to an actual provider, include failed attempts, filter earlier audit
content and prevent later writers or resumed segments from loosening it.
Keep background-answer policy distinct from ordinary audit content.
Persisted calls bypass provider-response replay; envelope rotation cannot
restore a concurrently removed answer.

**Acceptance:** functional tests inspect stored event payloads and background
answers across criteria selection, fallback, failure, resume, explicit
metadata, stricter configuration and stale writes. Failure to enforce storage
policy prevents dispatch. Historical reads and backups cannot be erased by a
later tightening; operational resume/session data has separate retention.

.. _responsible-ai-rai02:

RAI-02: compare the same completion benchmark
============================================

**P1 / implementation / nr-llm maintainers.** A :php:`GoldenPromptSet`
identifier alone does not identify its content. Changing prompts, references,
assertions or effective evaluation settings could silently change a baseline
or the quality evidence used to route calls.

**Correction:** :ref:`ADR-271 <adr-271>`. Record a versioned benchmark
fingerprint separately from verified serving-generator identity. Baselines
and routing select a comparable revision. Historical rows without proof
remain readable but are not assigned invented identity.
The final adapter-bound request is included. Decision/custom grading without
effective judge-request evidence remains unknown; its scores remain in history.

**Acceptance:** tests mutate each relevant input, check persisted fingerprints
and exact baseline/routing selection, and reject unknown/mixed generators or
incompatible graders. A valid score of zero remains distinct from no evidence.
The :ref:`evaluation guide <developer-quality-evaluation>` records migration
and the limits of declared settings and remote-model reproducibility.

.. _responsible-ai-rai03:

RAI-03: demonstrate retention across copies
==========================================

**P1 for a deletion promise; otherwise P2 / operating evidence / operator.**
:php:`PurgePrivacyDataCommand` invokes category repositories. Sessions,
suspended/queued execution data and background answers are not all equivalent
to audit logs. Cache TTLs and the usage ledger have different rules.

**Delivered:** the inventory and evidence procedure in
:ref:`responsible-ai-retention-evidence`. **Open:** run that procedure against
the installation, its cache backend, indexes, backups and provider contracts.

**Acceptance:** synthetic markers disappear from every in-scope store after
the declared interval; exclusions have a purpose, owner and expiry; logs prove
scheduled execution and failures alert an owner. Test restoration so a backup
does not silently reintroduce expired data. Do not call a row-delete test a
universal erasure proof.

.. _responsible-ai-rai04:

RAI-04: distinguish a source from a supported claim
=================================================

**P2; P1 when verified sources are promised / integration / application team.**
:php:`EvidenceSource` and :php:`SourceReference` already represent retrieval
sources. Generic completion does not promise validated citations. Adding a
universal citation DTO without a concrete consumer would not validate claims.

**Delivered:** a source-validation procedure in
:ref:`responsible-ai-source-evidence`. **Open:** integrate it in the consuming
search or publishing application; add a shared contract only when its
consumer and validation semantics are specified.

**Acceptance:** fabricated, missing, stale and inaccessible sources are
distinguished from validated support. Test that a real URL with an unrelated
passage does not count as support and that display cannot bypass access rules.
Keep retrieved, claim-checked and human-reviewed states separate.

.. _responsible-ai-rai05:

RAI-05: qualify the actual application
====================================

**P2; P1 for consequential uses / evaluation evidence / application team.**
:php:`BuiltinGoldenPromptSetProvider` is explicitly a smoke set. It proves
neither representative quality nor fairness or accessibility.

**Delivered:** :ref:`responsible-ai-application-evaluation` with a runnable
synthetic pattern and risk-linked human checks. **Open:** domain-specific
cases, relevant languages/groups, independent reference judgments and measured
results. No live-provider quality evaluation was performed for this inventory.

**Acceptance:** version the dataset, configuration, grader and thresholds;
report per-category results, sample counts and failures; include human review,
affected-person feedback and an appropriate simpler/non-AI comparison. Recheck
after provider/model, prompt, retrieval or workflow changes.

.. _responsible-ai-rai06:

RAI-06: inventory all outgoing payloads
======================================

**P1 for a comprehensive egress-protection claim / coverage and integration /
nr-llm maintainers and application team.**
:php:`InputGuardrailScreener` screens supported text, while
:php:`InputContextClassifier` folds declarations for configured context.
Neither establishes automatic classification of arbitrary user input, image
pixels, audio, embedding input or opaque provider payloads.

**Delivered:** :ref:`responsible-ai-egress` identifies these boundaries.
**Open:** instrument the application's actual transports and close whichever
gaps its data policy forbids. Do not silently rewrite embedding input and
declare semantic quality unchanged; assess the effect on indexing/retrieval.

**Acceptance:** a synthetic-marker transport test covers ordinary, streaming,
fallback, tool, retrieval, embedding, multimodal and specialised-service paths
that the deployment enables. Every payload has a recipient and an explicit
admit, transform or reject rule; unknown paths invalidate a universal claim.

.. _responsible-ai-rai07:

RAI-07: substantiate local and regional processing
================================================

**P1 when locality is promised; otherwise P2 / provider and operating evidence
/ operator.** A :php:`TrustZone` declaration is a routing input, not a network
measurement or contract. A local primary with an external fallback is not an
entirely local chain.

**Delivered:** :ref:`responsible-ai-egress` records actual recipients and
fallbacks. **Open:** endpoint/subprocessor and processing-location evidence,
deployment topology, network restrictions, and the model/dataset licence
inventory. The same applies to translators, embedding/reranking services,
grading models and tool servers.

**Acceptance:** exercise failover and observe recipients; verify contractual
claims and all dependencies; record change review for endpoints and fallbacks.
Do not equate an EU endpoint, self-hosting and open-source model licensing.

.. _responsible-ai-rai08:

RAI-08: carry origin information into publication
================================================

**P2; P1 where disclosure applies / integration / application and editorial
teams.** ``tx_nrllm_ai_written`` and :php:`AfterAiRecordWrittenEvent` already
exist. A stored field alone is neither a visitor notice nor portable marking
of every media output.

**Delivered:** the existing template example is linked into the end-to-end
procedure in :ref:`responsible-ai-disclosure-evidence`. **Open:** run it in
the actual site, including translations, workspaces, publication and caches.

**Acceptance:** applicable notices reach users accessibly at the required
point; unsupported tables/media and old content have a stated policy. Test
human edits, cleared marks, copies and exports. Assess Article 50 duties and
exceptions separately; do not treat every AI-assisted text identically.

.. _responsible-ai-rai09:

RAI-09: qualify export and provider substitution
================================================

**P2 / bounded inspection and migration evidence / application team.**
The :ref:`portability inventory <responsible-ai-portability>` identifies
billing export and preset import without inventing a universal export API.

**Delivered:** source inventory and migration scope. **Open:** a supported
non-PII round trip for the chosen application state, provider substitution and
vault-reference rebinding. **Acceptance:** document included/excluded entities,
versions, references and permissions; demonstrate reimport and representative
behavior at the destination. Do not export secrets to satisfy portability.

.. _responsible-ai-rai10:

RAI-10: explain what a governance-profile match means
====================================================

**P2 / product explanation / nr-llm maintainers.**
:php:`GovernanceProfileEvaluator::deviations()` compares described settings;
it does not inspect contracts, physical location or the whole application.

**Delivered:** explicit English/German backend wording and this assessment.
**Acceptance:** matching and deviating views explain the bounded comparison;
existing runtime resolvers remain authoritative. Profile names and a green
match do not imply certification, DPG recognition or legal compliance.
