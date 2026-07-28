.. include:: /Includes.rst.txt

.. _adr-118:

============================================================================
ADR-118: Verify the specialized services from the backend
============================================================================

:Status: Accepted
:Date: 2026-07-28
:Authors: Netresearch DTT GmbH

.. _adr-118-context:

Context
=======

Translation, image generation and speech are configured through the Extension
Configuration: one nr-vault identifier per credential
(``translators.deepl.apiKeyIdentifier``, ``image.fal.apiKeyIdentifier``,
``providers.openai.apiKeyIdentifier`` for the DALL·E / Whisper / TTS family).

Nothing in the backend could reach any of them. Every entry point terminates in
a plain chat completion:

- the Playground drives :php:`AgentRuntimeInterface` with a single text prompt;
- the "Test" buttons on provider, model and configuration records call
  :php:`$adapter->complete()`;
- :php:`TaskExecutionService::execute()` never branches on ``TaskCategory``,
  ``TaskInputType`` or ``TaskOutputFormat`` — it always calls
  ``completeWithConfiguration()``, so a translation or image Task cannot be
  defined.

A repository-wide search confirms it: outside their own directories,
:php:`TranslationService` appears only in doc comments, and the image services
not at all. They are implemented, DI-wired and unit-tested, and reachable only
by a consuming extension that injects them.

The practical consequence is that an operator pastes a DeepL identifier into
the Extension Configuration and has no way to learn whether it works. The
failure surfaces later, in a consumer, as a runtime error.

.. _adr-118-decision:

Decision
========

**Add backend endpoints that exercise translation and image generation, as
verification surfaces rather than features.**

Three AJAX routes on :php:`SpecializedTestController`, rendered as two cards on
the existing test page:

.. list-table::
   :header-rows: 1
   :widths: 30 70

   * - Route
     - Purpose
   * - ``nrllm_test_translate``
     - Translate a snippet. With no translator named, the LLM path runs (no
       specialized credential needed); naming one routes to it, which is the
       case worth testing after configuring its vault identifier.
   * - ``nrllm_test_translators``
     - List the registered translators and whether each is configured, so the
       picker shows the answer before anything runs.
   * - ``nrllm_test_image``
     - Generate one image with either the OpenAI or the FAL service.

**Nothing is persisted.** A translation is returned as text. An image is
returned as whatever the provider produced — a URL from FAL, a data URI from
the OpenAI family — rendered in the browser and gone on reload. Storing it,
moving it into FAL or attaching it to a record is the consuming extension's
job and deliberately out of scope: nr_llm has no storage wiring for generated
images and this ADR does not add one.

**A missing credential is reported as 503, not 500.** That distinction is the
whole point of the endpoints, so :php:`ServiceUnavailableException` is caught
separately and answered with a message naming the Extension Configuration.
A runtime failure of a configured service stays a 500 with the detail in the
log, per the existing error-sanitising convention.

.. _adr-118-decision-interface:

``ImageGeneratorInterface``
---------------------------

:php:`DallEImageService::generate()` takes an
:php:`ImageGenerationOptions` object as its second parameter,
:php:`FalImageService::generate()` a model identifier. The divergence is
deliberate — the providers model a request differently — and stays.

The new :php:`ImageGeneratorInterface` declares only the part they share,
``generate(string $prompt): ImageGenerationResult``. Both classes already
default every parameter after the prompt, so neither changes behaviour by
implementing it. It lets a caller that wants no provider-specific control treat
the two interchangeably, and it makes them mockable — both are ``final``, which
would otherwise leave the controller untestable.

Because both services satisfy the interface, the controller's two image
arguments are bound explicitly in ``Services.yaml``; the type alone cannot
disambiguate them.

.. _adr-118-consequences:

Consequences
============

- An operator can answer "does this credential work?" without writing consumer
  code, which is what the Extension Configuration fields have been missing
  since they were introduced.
- The endpoints spend real provider quota on each run. They are admin-only
  (:ref:`ADR-037 <adr-037>` guards every action), single-shot, and input is
  capped at 5000 characters.
- Speech (Whisper, text-to-speech) stays unreachable. Transcription needs an
  audio upload and synthesis produces a binary response; both raise the same
  storage question this ADR declines to answer for images, with less to gain.
- :php:`ImageGeneratorInterface` is public surface. A future image provider is
  expected to implement it.
