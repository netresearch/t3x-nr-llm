.. include:: /Includes.rst.txt

.. _adr-057:

===============================================================
ADR-057: Speech and image services carry attribution in options
===============================================================

:Status: Accepted
:Date: 2026-07-14
:Authors: Netresearch DTT GmbH

.. _adr-057-context:

Context
=======

:ref:`ADR-052 <adr-052>` made the caller-supplied ``beUserUid`` win
over the ambient ``backend.user`` aspect for usage attribution, but
deferred the four specialized speech/image services: their option
shapes (``TranscriptionOptions``, ``SpeechSynthesisOptions``,
``ImageGenerationOptions``, and ``FalImageService``'s plain array)
carried no budget fields, so every transcription, synthesis and image
generation landed in the ambient bucket — ``be_user = 0`` for
frontend, CLI and worker callers — with no way for a consumer to
attribute the spend.

Unlike chat/completion/embedding/vision, these services do not run
through the middleware pipeline (they dispatch HTTP directly via
``AbstractSpecializedService``), so neither ``BudgetMiddleware`` nor
``UsageMiddleware`` can supply the uid; the services call
``trackUsage()`` themselves.

.. _adr-057-decision:

Decision
========

Extend the options-based attribution of ADR-052 to the specialized
services — attribution only, no enforcement:

- ``TranscriptionOptions``, ``SpeechSynthesisOptions`` and
  ``ImageGenerationOptions`` implement ``BudgetAwareOptionsInterface``
  via ``BudgetFieldsTrait``: optional trailing ``beUserUid`` /
  ``plannedCost`` constructor parameters, ``fromArray()`` keys of the
  same names, validation rejecting negative values. The fields stay
  out of ``toArray()`` — the services build their wire payload from
  ``toArray()``, so a missed exclusion would leak the uid to the
  remote API.
- ``WhisperTranscriptionService``, ``TextToSpeechService`` and
  ``DallEImageService`` forward ``getBeUserUid()`` to their
  ``trackUsage()`` calls.
- ``FalImageService`` keeps its plain options array (no DTO exists)
  and reads a documented ``beUserUid`` key — the same array pattern
  ``TranslationService`` uses for translators. The payload builder is
  an explicit allowlist, so the key never reaches the FAL API.
- ``DallEImageService::createVariations()`` and ``edit()`` take no
  options object; they gain an optional trailing ``?int $beUserUid``
  scalar parameter instead of growing a new options type for two
  DALL-E-2-only endpoints.

.. _adr-057-consequences:

Consequences
============

- Consumers can attribute speech and image spend per backend user from
  any context; without the uid the ambient fallback keeps the previous
  behaviour.
- Attribution and enforcement remain decoupled: the specialized
  services still bypass the middleware pipeline, so per-user budget
  ceilings are NOT enforced on speech/image calls. ``plannedCost`` is
  carried but unused there. Routing these services through a budget
  pre-flight is a separate decision with its own trade-offs (no
  token-based cost model for FAL, multipart request flows) and gets
  its own ADR if a consumer needs it.
- The three option DTOs and the two DALL-E signatures are public
  surface; the additions are optional trailing parameters
  (semver-minor in the 0.x line, same policy as the ADR-052
  ``trackUsage()`` change).
- ``BudgetAwareOptionsInterface``'s docblock now names the
  attribution-only consumers so the "reaches BudgetMiddleware"
  assumption is not silently wrong.
