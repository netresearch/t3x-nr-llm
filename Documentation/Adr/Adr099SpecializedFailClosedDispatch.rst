.. include:: /Includes.rst.txt

.. _adr-099:

============================================================================
ADR-099: Fail-closed HTTP egress for the specialized services
============================================================================

:Status: Accepted
:Date: 2026-07-21
:Authors: Netresearch DTT GmbH

.. _adr-099-context:

Context
=======

:ref:`ADR-097 <adr-097>` routed every specialized dispatch through the pipeline
via ``runLifecycle()``, so an image / speech / translation call now gets the same
telemetry row, correlation id and circuit breaker as a chat call. But the
wrapping is a convention: a new service method — or a refactor — that builds a
request and sends it without calling ``runLifecycle()`` would compile, pass, and
silently spend against the provider with no telemetry, no circuit breaker and no
usage. Nothing enforced the convention.

.. _adr-099-decision:

Decision
========

The HTTP egress fails closed. ``AbstractSpecializedService`` tracks a private
``$withinLifecycle`` flag, set only while a ``runLifecycle()`` dispatch is
executing (saved/restored around the terminal, so nesting is safe).
``assertWithinLifecycle()`` throws a ``\LogicException`` when the flag is false,
and it is called at every HTTP-egress point — ``executeRequest()`` and the two
binary/multipart senders in ``TextToSpeechService`` and
``WhisperTranscriptionService`` — **before** their ``try`` block, so the guard is
not swallowed by the ``Throwable -> ServiceUnavailableException`` mapping.

A ``\LogicException`` (not a service exception) is deliberate: dispatching
outside a lifecycle is a programmer error, not a runtime fault, and must not be
retried or mapped to a transient failure.

Enabling the guard required routing the last unwrapped provider calls through
``runLifecycle()``. DeepL's ``detectLanguage()`` is a billable translate call and
now runs as ``ProviderOperation::Translation`` — closing a real telemetry gap.
Its ``getUsage()`` and ``getGlossaries()`` are free metadata lookups; they run as
the new ``ProviderOperation::Metadata`` so they are observable and circuit-breaker
guarded like any provider HTTP call, but labelled honestly rather than as a
translation.

.. _adr-099-consequences:

Consequences
============

- A specialized service method that reaches the provider without
  ``runLifecycle()`` now throws immediately instead of spending unobserved. The
  guard is the single invariant behind ADR-097's promise.
- ``ProviderOperation`` gained a ``Metadata`` case for provider status /
  metadata calls that are not themselves an AI generation.
- DeepL ``detectLanguage`` / ``getUsage`` / ``getGlossaries`` now emit a
  telemetry row and are subject to the provider circuit breaker. They record no
  usage and enforce no budget (they are not billable generations, apart from the
  translate call ``detectLanguage`` already made), so cost accounting is
  unchanged.
- Test doubles that exercise the low-level HTTP helpers must dispatch inside a
  lifecycle — the ``TestableSpecializedService`` delegate wraps its call in
  ``runLifecycle()``, mirroring production. A deliberately unwrapped delegate
  asserts the guard throws.
- Still deferred: folding the per-service usage recording into a tagged pipeline
  extractor read by the usage middleware.
