.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _responsible-ai-operational-evidence:

====================
Operational evidence
====================

These procedures are instructions for qualification, not recorded successful
production checks. Use synthetic markers and test accounts in an isolated
installation. Keep production personal data and secrets out of test reports.

.. _responsible-ai-evidence-record:

Record a claim and its evidence
==============================

For each claim, record the application/purpose, affected users, source commit,
TYPO3/PHP versions, relevant configuration revision, provider/model identity,
data paths, test procedure, expected and observed result, timestamp and owner.
Classify the evidence as source inspection, automated behavior test, deployment
experiment or external recognition. Link the evidence and document known
exclusions, failure response and the next review trigger. An unperformed test
is **not assessed**, not passed.

Do not put confidential configuration in a public report. Use bounded
identifiers and controlled evidence storage; record its own access and
retention policy. A source-code link can substantiate a mechanism, but not an
operator's actual configuration.

.. _responsible-ai-retention-evidence:

Retention and deletion
======================

Inventory the following separately before promising deletion:

.. list-table::
    :header-rows: 1

    * - Store
      - Existing lifecycle / evidence to collect
    * - Conversation transcripts
      - Content is needed for conversation replay independently of audit
        privacy level. Verify the conversation retention window and session
        purge, including child messages.
    * - Agent event payloads and finished runs
      - Audit filtering plus ``privacy.retention.agentRun``. Inspect both
        run rows and related events after purge.
    * - Suspended and queued execution state
      - Operational content permits resume/execution. Verify unfinished-run
        expiry, queue behavior and what happens to an expired approval.
    * - Background answers
      - Stored separately from audit content; encryption and policy are not
        deletion. Verify the answer disappears with its run and is not
        repopulated by a late worker.
    * - Evaluation output and skill audit
      - Their category windows govern stored evidence/content. Preserve only
        the non-sensitive evidence needed by the assessment policy.
    * - Telemetry, call outcomes and governance events
      - Check their respective purge paths and related-row handling. Metadata
        can still identify people or behavior.
    * - Answer and embedding caches
      - Inspect configured cache backend, keys, TTL, invalidation and backups.
        The central database purge is not a cache purge.
    * - Request-idempotency cache outside persisted agent runs
      - :php:`IdempotencyMiddleware` can retain a caller-keyed response for
        24 hours without applying the audit privacy level. Include this
        independent content copy and its cache backend in deletion policies.
    * - Usage ledger
      - The central privacy purge deliberately retains usage rows, including
        backend-user references. Define the purpose and separate lifecycle.
    * - TYPO3 content, indexes, backups and external services
      - These have independent owners and retention. Include provider logs,
        tool servers, database replicas and restored backups where relevant.

Create distinguishable synthetic markers in each enabled path, including a
suspended run and a background answer. Exercise the configured purge in the
isolated installation and inspect the actual stores, not only its success
message. The command is documented in
:ref:`administration-data-retention-purging`.

Check the time boundary, category overrides and successful scheduled execution.
Exercise a failed purge and confirm that an owner is notified. Restore a test
backup and demonstrate how expired data is removed before service resumes.
Record exceptions and external deletion limitations. Do not run destructive
qualification commands against a production installation without its operating
procedure and authorization.

.. _responsible-ai-egress:

Outgoing data and recipient evidence
====================================

These are source-inspection boundaries in the reviewed code, not results of
packet inspection:

.. list-table::
    :header-rows: 1

    * - Path
      - Mechanism and remaining question
    * - Configured chat, completion and tool text
      - :php:`LlmServiceManager` uses input screening. Declared system prompts,
        snippets and skills also use context classification. Arbitrary caller
        text has no automatic sensitivity declaration.
    * - Structured/opaque message content
      - :php:`InputGuardrailScreener` screens string content and preserves
        provider items/tool fields. Assess every non-text payload separately.
    * - Embeddings
      - :php:`LlmServiceManager::embed()` passes its input to the provider
        without the chat text screener. Apply an application-specific policy
        before indexing or querying; test retrieval quality after any transform.
    * - Vision
      - :php:`LlmServiceManager::vision()` screens accompanying text. Image
        bytes, image URLs and text visible in pixels need separate controls.
    * - Audio, images and translation services
      - Inspect each specialised adapter and its payload, including binary
        input, generated media and glossary exchanges. Text screening is not
        a claim about every byte in those requests.
    * - Retrieval, MCP and other tools
      - Tool declarations/gates and retrieval access checks apply at their
        own boundaries. Inspect the actual external tool/server and results.
    * - Fallback and grading
      - Enumerate every attempted recipient, including failed attempts and
        evaluation graders. A successful primary request proves no fallback
        property.

For each enabled path, use a recording test transport and synthetic markers.
Assert on the raw outbound body, headers and destination; do not send test
secrets to public providers. Exercise denial, redaction, streaming, fallback,
resume and errors. Record whether each marker is admitted, transformed or
rejected and why. Record paths that were not exercised.

Compare observed destinations with endpoint configuration, network rules,
subprocessor information and contracts. A ``local`` or regional trust-zone
declaration cannot supply this evidence. Repeat when endpoints, models,
fallbacks, graders, translation or tool services change.

.. _responsible-ai-source-evidence:

Source presentation
===================

Keep the retrieved source identifier/URL, the passage actually used and the
answer claim separate in the application. Represent validation as an explicit
state such as unverified, supports, contradicts or unavailable, with the
method and date. These are proposed application states, not an nr-llm API.
Human-reviewed is a separate fact; a second model's judgment is evidence with
its own limitations.

Exercise a fabricated identifier, a valid URL with unrelated text, a changed
or deleted passage and a source the viewer may not read. Recheck permissions
when displaying sources, escape titles/excerpts, and never let a generated
link bypass endpoint restrictions. Show uncertainty and a correction route
when support is missing. Test that a plausible answer without evidence is not
silently promoted to a verified answer.

.. _responsible-ai-disclosure-evidence:

From AI write to published notice
=================================

Start with the existing example at :ref:`administration-ai-written-records`.
The site team decides wording, placement, accessibility and applicable duties;
the field is one input to that decision.

1. Make a synthetic AI text write to a supported ``pages`` or ``tt_content``
   record in a draft workspace. Confirm that the correct version is marked.
2. Preview as the intended visitor and verify the notice is visible and
   accessible in each relevant language. Check cached and uncached responses.
3. Publish, then check the public response. Exercise human edits, explicit
   clearing, a subsequent AI write, copying and translation.
4. Document unsupported tables, externally generated content, old unmarked
   records and exported media. A Boolean flag does not establish a reliable
   history, model attribution or portable machine-readable media provenance.
5. Independently test interaction notices and any applicable machine-readable
   output requirements. Refer to :ref:`responsible-ai-framework-transparency`;
   a visitor-facing text notice alone does not address every requirement.
