.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _responsible-ai-current-state:

==========================
Capabilities and boundaries
==========================

This is a source-based inventory, not an assertion that every deployment has
enabled or tested the controls. The :ref:`gap register <responsible-ai-gaps>`
distinguishes implementation work from application and operating evidence.

.. _responsible-ai-controls:

Controls available to applications
=================================

.. list-table::
    :header-rows: 1
    :widths: 24 38 38

    * - Concern
      - Available mechanism / evidence entry point
      - Boundary
    * - Choice and independence
      - Provider adapters, configuration/model selection, fallback and support
        for self-hosted providers; :ref:`ADR-001 <adr-001>`.
      - Providers differ in capabilities and model behavior. Substitution needs
        an application test; an adapter does not prove open model weights,
        local processing or unrestricted model/data licensing.
    * - Human control
      - Permission checks, declared tool effects, approval and resume controls;
        :ref:`administration-permissions`, :ref:`ADR-132 <adr-132>` and
        :ref:`ADR-266 <adr-266>`.
      - An approval needs an informed reviewer with authority to reject it.
        Workspace support and cancellation do not promise undo of every
        already completed external effect. Integrators declare effects honestly.
    * - Content persistence
      - :php:`PrivacyPolicy`, :php:`RunStepPrivacyFilter` and run-specific
        privacy resolution; :ref:`administration-data-retention`.
      - Redaction is heuristic. Operational conversation/resume data,
        background answers, caches and billing rows have different rules.
        Provider-attempt changes are described in :ref:`ADR-270 <adr-270>`.
    * - Retention
      - :php:`PurgePrivacyDataCommand` and category-specific windows;
        :file:`Tests/Functional/Service/Privacy/PrivacyPurgeTest.php`.
      - A configured window schedules nothing. Database cleanup does not erase
        backups, external providers, search indexes or cache copies.
    * - Outgoing data
      - :php:`InputContextClassifier` and :php:`InputContextTrustGate` check
        declared context; :php:`InputGuardrailScreener` screens supported text.
      - Context declarations are not automatic PII detection. Raw embedding
        input, binary image/audio content and opaque provider items need their
        own assessment; see :ref:`responsible-ai-egress`.
    * - Evaluation and routing
      - Golden sets, deterministic/decision grading, regression detection,
        generator provenance and retrieval experiment identity;
        :ref:`developer-quality-evaluation`, :ref:`ADR-215 <adr-215>` and
        :ref:`ADR-271 <adr-271>`.
      - A benchmark fingerprint establishes declared inputs, not model
        fairness, factual correctness or immutable remote weights. Smoke tests
        are not representative application evaluations.
    * - Sources
      - :php:`EvidenceSource` carries source identifier, URL, excerpt, backend
        and language. :php:`SourceReference` parses identifiers strictly.
      - An available source is not proof that it supports an answer. Generic
        :php:`CompletionResponse` has no universal validated-citation contract.
        Recheck access when displaying a source.
    * - AI-origin information
      - :php:`AfterAiRecordWrittenEvent` and ``tx_nrllm_ai_written`` on supported
        page/content records; :ref:`administration-ai-written-records`.
      - The flag is editable, has no historical backfill and is not a complete
        provenance record. A consuming template must display a notice. It does
        not prove portable machine-readable marking of all generated media.
    * - Resource use
      - Budgets, usage/cost reporting, model selection and optional caches;
        :ref:`ADR-231 <adr-231>`, :ref:`ADR-249 <adr-249>`.
      - Counts and estimated cost are not energy, water or emissions
        measurements. Benefit and resource impact require an application study.
    * - Governance readout
      - :php:`GovernanceProfileEvaluator` compares declared profile settings;
        :ref:`administration-governance` and :ref:`ADR-145 <adr-145>`.
      - A match is a settings comparison. It does not verify processing
        location, contracts, all data paths or compliance with a framework.

.. _responsible-ai-portability:

Portability: inspected scope
===========================

The repository exposes a billing CSV through
:php:`AnalyticsController::exportBillingAction()`. It contains usage and user
information, so it is not an unrestricted non-personal-data export.
:php:`ConfigurationPresetImportService` imports configuration presets; an
importer alone does not prove a round-trip export. Retrieval adapters and
ordinary TYPO3 content storage provide integration points, not a universal
nr-llm migration bundle.

This inspection found no supported, complete round-trip export for the whole
nr-llm application state. That bounded finding is not a claim that TYPO3 lacks
database export or that consumers cannot implement migration. Before choosing
a portable operating model, inventory configurations, prompts, skills, term
lists, model/provider references, content, retrieval indexes and retained
evaluation evidence. Exclude credentials from a general export and rebind
vault references at the destination. Exercise reimport and compare results
under the destination's permissions. RAI-09 remains open until that exercise
has evidence.

.. _responsible-ai-outside-library:

What the library cannot establish
================================

The project cannot establish a deployment's lawful processing, provider
location, contractual guarantees, consent, staff competence, accessibility,
fairness across affected groups, editorial responsibility or environmental
impact by inspecting configuration alone. The integration and operator must
provide those records. Refer to the
:ref:`framework comparison <responsible-ai-framework-mapping>` for the
relationship to external programmes, and to
:ref:`responsible-ai-operational-evidence` for a reproducible evidence record.
