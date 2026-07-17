.. include:: /Includes.rst.txt

.. _adr-078:

============================================================================
ADR-078: Budget pre-flight for the specialized image/speech services
============================================================================

:Status: Accepted
:Date: 2026-07-17
:Authors: Netresearch DTT GmbH

.. _adr-078-context:

Context
=======

Chat-shaped calls run through the provider middleware pipeline, so
``BudgetMiddleware`` (:ref:`ADR-025 <adr-025>`) enforces per-user and
per-configuration spend ceilings before any provider request. The
specialized image/speech services (DALL-E, FAL, Whisper, TTS) dispatch
HTTP directly from ``AbstractSpecializedService`` and **bypass** that
pipeline.

:ref:`ADR-057 <adr-057>` gave the specialized option DTOs the
``beUserUid``/``plannedCost`` fields (via ``BudgetFieldsTrait`` /
``BudgetAwareOptionsInterface``) and wired them into *usage attribution*
— but deliberately deferred *enforcement*, recording that "routing these
services through a budget pre-flight is a separate decision with its own
trade-offs (no token cost model for FAL, multipart flows)" and leaving
``plannedCost`` carried-but-unused on the send path. This ADR is that
deferred follow-up.

.. _adr-078-decision:

Decision
========

**Add a pre-flight budget gate to** ``AbstractSpecializedService``. A new
protected ``enforceBudget(?int $beUserUid, ?float $plannedCost, ?string
$configurationIdentifier)`` mirrors ``BudgetMiddleware::handle()``: it
resolves the named configuration (reusing the existing
``findActiveConfiguration()``), calls ``BudgetServiceInterface::check()``
and throws ``BudgetExceededException`` before any HTTP dispatch when a
limit is exceeded. Each concrete service calls it after option resolution
and input validation, before building its request:

- ``DallEImageService`` — ``generate()``, ``generateMultiple()`` (from the
  options), and ``createVariations()``/``edit()`` (from the scalar
  ``beUserUid``, no cost/configuration).
- ``TextToSpeechService`` — ``synthesize()`` (``synthesizeToFile()`` /
  ``synthesizeLong()`` delegate to it, so they inherit the gate without
  double-gating).
- ``WhisperTranscriptionService`` — all three entry points
  (``transcribe()``, ``transcribeFromContent()``, ``translateToEnglish()``);
  gating only some would leave an enforcement bypass.
- ``FalImageService`` — ``generate()``/``generateMultiple()``, reading
  ``beUserUid``/``plannedCost`` from its allow-listed options array (a new
  ``extractPlannedCost()`` mirrors the existing ``extractBeUserUid()``; the
  payload builder drops both, so neither reaches the FAL API).

``DeepLTranslator`` also extends the base but its options carry no budget
fields — it is intentionally **not** gated.

**Fail-open by construction.** The gate hangs on a new *optional* trailing
constructor parameter ``?BudgetServiceInterface $budgetService = null``. It
autowires from the existing ``BudgetServiceInterface`` alias in production;
when absent (unconfigured deployments, unit tests) ``enforceBudget()`` is a
no-op. Even when wired, ``BudgetService::check()`` short-circuits to
"allowed" for calls without a backend user or without configured limits, so
nothing changes until an operator actually sets a cap.

.. _adr-078-consequences:

Consequences
============

- **Behavioural change, gated on configuration.** Deployments that already
  set per-user or per-configuration limits will start receiving
  ``BudgetExceededException`` on image/speech calls once a cap is hit —
  previously those calls were only *attributed* (ADR-057), never blocked.
  Deployments with no limits are unaffected.
- **FAL and DALL-E variations/edit have no cost model.** FAL publishes no
  static price list and the variations/edit endpoints carry no
  ``plannedCost``, so *cost* caps cannot trip for those paths — only
  request-count/token caps enforce. Operators must not assume a cost ceiling
  protects FAL image spend.
- **Catch surface.** ``BudgetExceededException`` lives in ``Exception\`` (the
  shared budget exception, ADR-025), not ``Specialized\Exception\``.
  Consumers that catch only ``SpecializedServiceException`` will not catch a
  budget denial — this is the intended shared-exception behaviour.
- Consumers that hand-rolled their own specialized pre-gate (e.g. an
  extension guarding image/TTS spend itself) can drop it; ``check()`` has no
  side effects, so a transitional double-gate is idempotent and harmless.
- The optional constructor parameter is a semver-minor change in the 0.x
  line, consistent with ADR-052/ADR-057; implementers that construct the
  specialized services by hand gain a nullable trailing argument.
