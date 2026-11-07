.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _responsible-ai-framework-mapping:

===================
Framework comparison
===================

This is a scoped comparison, not a certification or a legal assessment of
an installation. The technical capabilities and their limitations are in
:ref:`responsible-ai-current-state`; follow-up work is in
:ref:`responsible-ai-gaps`. A control can support several objectives without
proving that any framework is fulfilled.

Sources were checked on **7 November 2026**. The mappings below are this
project's interpretation. Recheck the sources and the deployed system when
using them for a customer, procurement exercise or public claim.

Evidence classes in the tables identify the **available entry point**:
**S** means source/document inspection only; **T** points to the behavior-test
contract and evidence in the owning ADR; **O** means deployment evidence is
still open. T does not claim that the entire external requirement has passed;
consult the ADR and the validation record for the tested revision. No row
claims a verified deployment. Owners are roles to assign to actual teams.

.. _responsible-ai-framework-dpg:

Digital Public Goods Standard
=============================

The `DPG Standard <https://www.digitalpublicgoods.net/standard>`__ has nine
indicators, with 9A--9C covering different harms. Its review concerns the
core solution, not individual installations. The following evidence prompts
apply to nr-llm's own scope:

.. list-table:: DPG indicators and assessment boundaries
    :header-rows: 1
    :widths: 22 33 45

    * - Indicator
      - Control or inspection reference
      - Evidence class, owner and remaining work
    * - 1. SDG relevance
      - :ref:`Application assessment <responsible-ai-rai05>`
      - **S/O; project and adopters.** Demonstrate public-benefit outcomes;
        openness alone is insufficient.
    * - 2. Open licensing
      - :file:`LICENSE`; :ref:`licence boundary <responsible-ai-freedom>`
      - **S/O; maintainers and operator.** Review dependencies and the
        separate model, dataset and service terms.
    * - 3. Clear ownership
      - :ref:`Responsibility register <responsible-ai-owners>`
      - **S/O; project and application owner.** Name accountable people and
        reporting channels for the deployment.
    * - 4. Platform independence
      - :ref:`Provider abstraction <adr-001>`;
        :ref:`portability inventory <responsible-ai-portability>`
      - **S/O; integrator.** Test substitution and enumerate proprietary
        dependencies under :ref:`RAI-09 <responsible-ai-rai09>`.
    * - 5. Documentation
      - :ref:`Capability inventory <responsible-ai-current-state>`;
        :ref:`evidence record <responsible-ai-evidence-record>`
      - **S/O; maintainers and integrator.** Document the actual application,
        operating procedure and limitations.
    * - 6. Data extraction
      - :php:`AnalyticsController::exportBillingAction()`;
        :ref:`inspected scope <responsible-ai-portability>`
      - **S/O; integrator.** Qualify the non-PII round trip in
        :ref:`RAI-09 <responsible-ai-rai09>`; billing CSV can contain PII.
    * - 7. Privacy and applicable laws
      - :ref:`Data-flow inventory <responsible-ai-egress>`;
        :ref:`locality evidence <responsible-ai-rai07>`
      - **S/O; operator.** Assess purpose, actual recipients, contracts and
        applicable obligations.
    * - 8. Standards and best practices
      - :ref:`Provider interface design <adr-001>`;
        :ref:`claim-maintenance rule <adr-269>`
      - **S/O; maintainers and integrator.** Link versioned checks and
        validate the deployed integration; no whole-standard pass is claimed.
    * - 9A. Privacy and security
      - :php:`RunStepPrivacyFilter`; :ref:`provider privacy <adr-270>`;
        :ref:`retention procedure <responsible-ai-retention-evidence>`
      - **T/O; maintainers and operator.** Verify configured permissions,
        recipients and deletion across copies under RAI-01/03/06.
    * - 9B. Inappropriate or illegal content
      - :ref:`Approval controls <adr-132>`;
        :ref:`application test matrix <responsible-ai-evaluation-matrix>`
      - **S/O; application team.** Implement suitable moderation and incident
        handling; approval alone does not assess content legality.
    * - 9C. Protection from harassment
      - :file:`CODE_OF_CONDUCT.md`;
        :ref:`complaint ownership <responsible-ai-owners>`
      - **S/O; community and application owners.** Test reporting and response
        procedures for application users as well as contributors.

The `practical DPG guide
<https://content.digitalpublicgoods.net/A_Practical_Guide_to_the_DPG_Standard_0249fee444.pdf>`__
clarifies the non-PII scope of indicator 6. This is not a reason to export
credentials or bypass access restrictions.

The `TYPO3 registry entry <https://www.digitalpublicgoods.net/r/typo3>`__
lists only the TYPO3 core repository as assessed. It explicitly excludes
unlisted repositories, add-ons and features. Its recognition therefore
does **not** establish recognition of nr-llm or a customer installation.

.. _responsible-ai-framework-altai:

ALTAI
=====

The European Commission's `ALTAI self-assessment
<https://digital-strategy.ec.europa.eu/en/library/assessment-list-trustworthy-artificial-intelligence-altai-self-assessment>`__
organizes assessment into seven areas. Use the complete checklist for a
specific application; the following is a starting map, not its completion.

.. list-table:: ALTAI areas and remaining application evidence
    :header-rows: 1
    :widths: 25 30 45

    * - Area
      - Control or inspection reference
      - Evidence class, owner and remaining work
    * - Human agency and oversight
      - :ref:`Permissions <administration-permissions>`;
        :ref:`approval boundary <adr-132>`
      - **S/O; integrator and operator.** Reviewers can understand, refuse,
        correct and escalate actions; exercise the real workflow.
    * - Technical robustness and safety
      - :ref:`Fallback privacy tests <adr-270>`;
        :ref:`egress qualification <responsible-ai-rai06>`
      - **T/O; maintainers and operator.** Exercise deployed failure,
        shutdown and recovery procedures beyond the tested privacy scope.
    * - Privacy and data governance
      - :php:`PrivacyPolicy`; :php:`PurgePrivacyDataCommand`;
        :ref:`RAI-03 <responsible-ai-rai03>`
      - **S/O; operator.** Verify actual recipients, lawful handling,
        schedules and deletion of all in-scope copies.
    * - Transparency
      - :php:`AfterAiRecordWrittenEvent`;
        :ref:`source checks <responsible-ai-rai04>`;
        :ref:`publication checks <responsible-ai-rai08>`
      - **S/O; application and editorial teams.** Test actual notices,
        source support, uncertainty and user understanding.
    * - Diversity, non-discrimination and fairness
      - :php:`GoldenPromptSet`;
        :ref:`application evaluation <responsible-ai-rai05>`
      - **S/O; application team and domain reviewers.** Test relevant groups,
        languages and accessibility with affected-person feedback.
    * - Environmental and societal well-being
      - :ref:`Usage reporting <adr-231>`;
        :ref:`resource assessment <responsible-ai-evaluation-matrix>`
      - **S/O; operator and application team.** Measure resource impact,
        benefits and harms against suitable alternatives.
    * - Accountability
      - :ref:`Evidence ownership <adr-269>`;
        :ref:`assessment record <responsible-ai-evidence-record>`
      - **S/O; project and deployment owners.** Name people, exercise
        complaint/incident handling, and review outcomes.

A successful test of an approval mechanism does not demonstrate effective
human oversight. A benchmark score does not demonstrate fairness across
populations. Budget, token and cost records do not measure energy, water or
emissions.

.. _responsible-ai-framework-nist:

NIST AI RMF and Generative AI Profile
===================================

This comparison uses **AI RMF 1.0** and **NIST AI 600-1, July 2024**.
The `NIST overview <https://www.nist.gov/itl/ai-risk-management-framework>`__
describes voluntary use and ongoing revision work. Pin the edition in an
assessment rather than silently adopting later changes.

The action IDs below come from the `Generative AI Profile
<https://nvlpubs.nist.gov/nistpubs/ai/NIST.AI.600-1.pdf>`__. The evidence
column is this project's proposed application of a selected subset.

.. list-table:: Selected NIST actions
    :header-rows: 1
    :widths: 20 30 50

    * - Action
      - Action theme and evidence entry point
      - Evidence class, owner and remaining work
    * - GV-1.3-002
      - Release thresholds; :ref:`evaluation report
        <responsible-ai-evaluation-report>`
      - **S/O; application owner.** Record acceptance criteria and decision.
    * - GV-1.3-007
      - Stop deployment; :ref:`evaluation report
        <responsible-ai-evaluation-report>`
      - **S/O; operator.** Exercise shutdown and assign its decision owner.
    * - GV-1.5-001
      - Review ownership; :ref:`claims rule <adr-269>`
      - **S/O; project and operator.** Name provenance/incident reviewers.
    * - GV-1.5-003
      - Evidence retention; :ref:`RAI-03 <responsible-ai-rai03>`
      - **S/O; operator.** Apply a privacy-aware evaluation-retention policy.
    * - GV-6.2-006
      - Fallback risks; :ref:`provider-attempt privacy <adr-270>`
      - **T/O; maintainers and operator.** Test the enabled fallback chain
        and actual recipients under :ref:`RAI-07 <responsible-ai-rai07>`.
    * - MP-1.1-001
      - Intended purpose; :ref:`assessment record
        <responsible-ai-evidence-record>`
      - **S/O; application team.** Describe purpose, data sources and limits.
    * - MP-1.1-003
      - Measurement plan; :ref:`RAI-05 <responsible-ai-rai05>`
      - **S/O; application team.** Run risk-linked cases and human review.
    * - MS-2.3-002
      - Capability validation; :ref:`benchmark identity <adr-271>`
      - **T/O; maintainers and evaluators.** Validate application claims;
        identity tests alone do not establish model quality.
    * - MS-2.5-001
      - Limit claims; :ref:`evaluation limits <responsible-ai-rai05>`
      - **S/O; evaluators.** Report sample limits and uncertainty.
    * - MS-2.5-003
      - Verify citations; :ref:`source procedure
        <responsible-ai-source-evidence>`
      - **S/O; application team.** Test claim support and ongoing source drift.
    * - MS-2.7-007
      - Adversarial tests; :ref:`egress procedure <responsible-ai-egress>`
      - **S/O; security and application teams.** Exercise injection/abuse
        scenarios across every enabled boundary.
    * - MS-2.11-001
      - Bias benchmarks; :ref:`test matrix
        <responsible-ai-evaluation-matrix>`
      - **S/O; domain evaluators.** Run relevant cases and document limits.
    * - MS-2.12-003
      - Environmental impact; :ref:`resource limits
        <responsible-ai-controls>`
      - **S/O; operator and provider.** Supply energy/water evidence and method.
    * - MG-4.1-002
      - Operational monitoring; :ref:`review procedure
        <responsible-ai-evaluation-report>`
      - **S/O; operator.** Record quality/incident review after deployment.

Benchmark revision and generator provenance are separate evidence.
Record evaluator versions, grader prompts/models, thresholds, settings and
sample sizes as well. A content fingerprint does not freeze an external
model or prove that the benchmark measures the intended outcome.

.. _responsible-ai-framework-transparency:

EU transparency requirements
============================

Legal duties are separate from voluntary ethical assessment. Evaluate the
actual system, role and use case against `Article 50
<https://ai-act-service-desk.ec.europa.eu/en/ai-act/article-50>`__ and the
Commission's `scope guidelines
<https://digital-strategy.ec.europa.eu/en/policies/guidelines-ai-transparency-obligations>`__.

.. list-table:: Distinct transparency questions
    :header-rows: 1
    :widths: 25 75

    * - Provision
      - Integration question
    * - Article 50(1)
      - Does a person interacting directly with the system receive the
        required AI notice, considering the applicable exceptions?
    * - Article 50(2)
      - Are relevant outputs marked in a machine-readable and detectable
        way? A private database flag alone does not demonstrate this.
    * - Article 50(3)
      - If applicable, are people informed about emotion-recognition or
        biometric-categorisation systems?
    * - Article 50(4)
      - Does publication require deepfake or public-interest-text
        disclosure? Assess the respective exceptions separately.
    * - Article 50(5)
      - Is the information clear, distinguishable and accessible by the
        first interaction or exposure?

Human review or editorial control, together with editorial responsibility,
is relevant to the text-publication exception in paragraph 4. It is not a
general exemption from every transparency duty.

Article 50 generally applies from **2 August 2026**. The
`consolidated AI Act, Article 111(4)
<https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:02024R1689-20260727>`__
provides until **2 December 2026** for Article 50(2) compliance by providers
of the specified synthetic-content systems placed on the market before
2 August 2026. This transition does not postpone all of Article 50.

The `Code of Practice on Transparency of AI-generated Content
<https://digital-strategy.ec.europa.eu/en/policies/code-practice-ai-generated-content>`__
was finalized on 10 June 2026. The Commission describes it as an adequate
voluntary compliance tool for its marking and labelling scope. It does not
replace the Act, constitute a general ethics certificate, or automatically
make users of nr-llm signatories. It is distinct from the GPAI model code.

.. _responsible-ai-framework-other:

Additional references and software freedom
=========================================

The `TYPO3 Open Web Manifesto
<https://docs.typo3.org/m/typo3/guide-policy/main/en-us/Association/OpenWebManifesto.html>`__
provides a values basis for independence, participation and privacy.
Provider choice and non-AI access paths are practical application choices
to assess against those values.

The `UNESCO Ethical Impact Assessment
<https://www.unesco.org/ethics-ai/en/eia>`__ helps assess individual systems
before and during use, including engagement with affected stakeholders.
The `OECD AI Principles <https://www.oecd.org/en/topics/ai-principles.html>`__
provide a broader reference. Neither is a product badge supplied by this
comparison.

`ISO/IEC 42001:2023 <https://www.iso.org/standard/42001>`__ addresses an
organization's AI management system. nr-llm controls can contribute
evidence; they cannot establish organizational certification.

The GPL licence remains unchanged. Development commitments, recommended
practices and supported-use boundaries add no licence restrictions. The
`Open Source Definition <https://opensource.org/osd>`__, notably sections
3, 5 and 6, protects modification and rejects discrimination by person,
group or field of use. An unevaluated use is not thereby prohibited under
the software licence, and a runtime safeguard is not a prohibition on
changing the software.
