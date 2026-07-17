.. include:: /Includes.rst.txt

.. _adr-076:

====================================================================
ADR-076: Document understanding — native-first, rasterize fallback
====================================================================

:Status: Accepted
:Date: 2026-07-17
:Authors: Netresearch DTT GmbH

.. _adr-076-context:

Context
=======

Two providers already implement ``DocumentCapableInterface`` (Gemini,
Claude): they accept a whole PDF as a Base64 ``document`` content block
in a single chat call and reason over it natively. Meanwhile
``nr_ai_search`` built a PDF *ingestion* enrichment (its ADR-034) that
never uses that capability: it shells out to poppler (``pdfimages`` /
``pdftoppm``), rasterizes image-bearing pages, and sends each page
through ``VisionServiceInterface``. The renderer trio it wrote for that
— ``PdfRendererInterface``, ``PopplerPdfRenderer``,
``PdfRenderingException`` — is consumer-agnostic: nothing in it knows
about ingestion, chunking, or degradation policy.

The 2026-07 downstream-extraction analysis (issue #416) decided the
ingestion pipeline stays in the consumer, but that when nr_llm grows
document understanding it must be designed around the *existing* native
capability, with rasterization only as the compatibility fallback.

.. _adr-076-decision:

Decision
========

``Netresearch\NrLlm\Specialized\Document\DocumentAnalysisService`` is
the stateless "understand this document" primitive:
``analyzeDocument(string $pdf, string $prompt, ?ChatOptions $options)``.

**Native path first.** The service resolves the effective provider the
same way ``LlmServiceManager::chat()`` would (explicit per-call
provider, else the default configuration's provider type, else the
registry default). When that provider implements
``DocumentCapableInterface`` and supports PDF, the whole document goes
into ONE chat call as a Base64 ``document`` block. Native ingestion is
preferred because the model reasons over the *whole* document — layout,
cross-page references, figures in context — at one call's latency and
attribution, instead of N per-page approximations stitched together.

**Rasterization as fallback only.** For providers without document
support, the PDF is rasterized page-by-page and each page is read by
``VisionServiceInterface`` with the caller's prompt; the answers are
concatenated with ``[Page N]`` markers. The rasterizer is the ported
``nr_ai_search`` renderer behind a new seam:

- ``PdfRasterizerInterface`` — image-page inventory, single-page and
  whole-document rasterization to PNG blobs, availability probe.
- ``PopplerPdfRenderer`` — the poppler-backed implementation, ported
  essentially unchanged (array-argv ``proc_open``, no shell parsing,
  temp-stub cleanup in ``finally``), extended by the whole-document
  ``renderDocument()`` the fallback needs.
- ``PdfRasterizationException`` — the typed rasterization failure.

**poppler is an optional system dependency.** Declared in
``composer.json`` ``suggest`` (``poppler-utils``); it is only needed
when the fallback runs. When rasterization is needed but the binaries
are absent, the service throws the typed, actionable
``ServiceUnavailableException::rasterizerUnavailable()`` (install
poppler-utils, or configure a document-capable provider). When the
native path is taken, poppler is never touched.

**What stays consumer-side, permanently:** ingestion orchestration,
enable/disable and cost-cap configuration flags, per-page degradation
contracts ("a failed page keeps the plain-text extraction"), chunking
and indexing. On the fallback path a failed page therefore fails the
call — consumers own retry/degrade policy.

.. _adr-076-public-services:

Public-service accounting (count authority)
===========================================

``DocumentAnalysisService`` joins Category D (Specialized standalone
consumer API) as a concrete ``public: true`` service; the
``PdfRasterizerInterface`` alias stays private (DI autowiring only).
This supersedes ADR-071's count as the audited breakdown
(:ref:`ADR-028 <adr-028>` / :ref:`ADR-065 <adr-065>` process):

- Category A (documented downstream LLM-API contract): 16
- Category B (supporting-service interface aliases): 5
- Category C (concrete-only documented surface): 1
- Category D (Specialized standalone consumer API): **5** — Whisper,
  TextToSpeech, DallE, Fal, **DocumentAnalysis**
- Category E (resolved outside DI via ``makeInstance()``): 2

Total ``public: true`` overrides: **29**.

.. _adr-076-boundary:

Relation to ADR-050 and nr_ai_search
====================================

:ref:`ADR-050 <adr-050>` drew the retrieval boundary ("no consumer
pipeline in nr_llm"), and its reading so far kept *all* PDF-vision
processing consumer-side. This ADR supersedes that reading for the
stateless primitive only: "understand this document" is a capability on
the same footing as ``EmbeddingService::embed()`` — no persistent
state, no pipeline, no configuration flags. Everything pipeline-shaped
remains in ``nr_ai_search`` per ADR-050's stopping rule.

``nr_ai_search`` will adopt ``PdfRasterizerInterface`` /
``PopplerPdfRenderer`` (replacing its own copy) and amend its ADR-028
consumer-side in a follow-up change set — nothing in nr_llm depends on
that migration.

.. _adr-076-consequences:

Consequences
============

- Consumers get whole-document reasoning in one call on Gemini/Claude
  without writing provider-specific ``document`` blocks themselves.
- The fallback keeps the feature working on every vision-capable
  provider, at per-page cost/latency and without cross-page reasoning.
- One more audited public service (29); the policy test locks the
  count against this ADR.
- nr_llm gains an optional system-binary dependency surface (poppler),
  but only on the fallback path and typed-failing when absent.
