.. include:: /Includes.rst.txt

.. _adr-052:

================================================================
ADR-052: Usage attribution honours the caller-supplied beUserUid
================================================================

:Status: Accepted
:Date: 2026-07-12
:Authors: Netresearch DTT GmbH

.. _adr-052-context:

Context
=======

Every option object carries ``withBeUserUid()``
(``BudgetAwareOptionsInterface``), and the manager forwards that uid as
pipeline metadata (``BudgetMiddleware::METADATA_BE_USER_UID``), where
``BudgetMiddleware`` uses it for per-user budget **enforcement**. Usage
**attribution**, however, ignored it: ``UsageTrackerService`` always
read the ambient ``backend.user`` context aspect to fill the
``be_user`` column.

For backend-module calls the two sources agree. For every caller
outside a backend-user request — frontend plugins, Messenger/CLI
workers, scheduler tasks — they do not: the aspect resolves to ``0``,
so usage lands in the anonymous bucket even when the caller passed an
explicit uid. Downstream extensions worked around this by
impersonating a technical backend user for the duration of a call —
swapping the ``backend.user`` aspect (and restoring it in a
``finally``) purely so the usage row gets the right ``be_user``.
``nr_ai_search``'s ``BackendUserContext::runAs()`` is such a
workaround, wrapped around every RAG chat call. Enforcement and
attribution also disagreed with each other: the budget gate charged
the option-supplied user while the usage row credited the ambient one.

.. _adr-052-decision:

Decision
========

The caller-supplied uid wins; the ambient aspect stays the fallback.

- ``UsageTrackerServiceInterface::trackUsage()`` gains an optional
  trailing ``?int $beUserUid = null`` parameter. ``null`` preserves the
  previous behaviour (ambient ``backend.user`` aspect, ``0`` when
  unauthenticated).
- ``UsageMiddleware`` reads ``BudgetMiddleware::METADATA_BE_USER_UID``
  from the pipeline context — the same key the budget gate reads — and
  passes it through, so enforcement and attribution can no longer
  disagree.

.. _adr-052-consequences:

Consequences
============

- A consumer that already sets ``withBeUserUid()`` gets correct
  attribution in frontend/CLI contexts with no further wiring; the
  aspect-swap workaround becomes unnecessary for usage tracking.
- Backend-module calls are unaffected: they set no option uid, and the
  ambient fallback resolves the same user as before.
- ``UsageTrackerServiceInterface`` implementers must add the new
  parameter (semver-minor breaking in the 0.x line, same policy as
  ``ToolInterface::getGroup()`` in 0.15.0). In-repo,
  ``UsageTrackerService`` is the only implementation.
- The specialized translator path forwards the uid even though it
  bypasses the middleware pipeline: ``TranslationService`` re-attaches
  the resolved uid to the options array it hands to
  ``TranslatorInterface`` implementations (the ``beUserUid`` key —
  budget fields are deliberately excluded from
  ``TranslationOptions::toArray()``), and ``DeepLTranslator`` /
  ``LlmTranslator`` pass it on to ``trackUsage()``. The key is
  attribution metadata only; translators never send it to the remote
  API.
- The remaining specialized services are ambient-only:
  ``WhisperTranscriptionService``, ``TextToSpeechService``,
  ``DallEImageService`` and ``FalImageService`` accept option shapes
  without budget fields (``TranscriptionOptions``,
  ``SpeechSynthesisOptions``, ``ImageGenerationOptions``, a plain
  array), so no caller-supplied uid reaches their ``trackUsage()``
  calls and attribution falls back to the ambient ``backend.user``
  aspect. Extending those option shapes is deferred until a consumer
  needs per-user attribution there.
