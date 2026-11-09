.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-274:

=============================================================
ADR-274: A model record carries its reasoning profile
=============================================================

:Status: Accepted
:Date: 2026-11-09
:Amends: :ref:`ADR-203 <adr-203>`; :ref:`ADR-160 <adr-160>`
:Authors: Netresearch DTT GmbH

Context
=======

The OpenAI adapter decides three things per model from a table in code,
``OpenAiModelProfiles``: whether the model is a reasoning model (then
sampling parameters such as ``temperature`` are not sent), which
``reasoning_effort`` values it accepts, and whether its tool calls go
through Chat Completions or Responses (:ref:`ADR-203 <adr-203>`). A model
the table does not name needs a release before it works.

On 2026-11-09 ``gpt-6.1-terra`` was such a model. It was listed by
``GET /v1/models`` and missing from the table, so a configured temperature
and every tool call failed with HTTP 400. The same measurement showed the
table itself wrong: OpenAI refuses ``max`` for every GPT-6 model, although
the model pages list it (the receipt is in
``specs/provider-profile-matrix.md``).

No source can replace the table completely:

*   OpenAI's model object carries ``id``, ``object``, ``created``,
    ``owned_by`` and ``shutdown_date``. It states no reasoning, effort or
    tool capability.
*   OpenRouter's public ``GET /api/v1/models`` states per model a
    ``reasoning`` object (``mandatory``, ``supported_efforts``) and
    ``supported_parameters``. For the five GPT-6 models it agrees with
    OpenAI's own answers on reasoning, on a non-default ``temperature``
    being unsupported, and on whether ``none`` is accepted
    (``mandatory: false`` exactly where ``none`` works). It disagrees on
    the scale: it lists ``max`` for all five, which OpenAI refuses. It also
    lists ``tools`` for ``gpt-6.1-terra``, which direct Chat Completions
    refuses: OpenRouter describes its own endpoint, not OpenAI's routes.
*   symfony/ai (``main`` at ``1fc422e47d``, 2026-11-06) keeps a
    hand-written OpenAI catalogue of about 70 models, throws
    ``ModelNotFoundException`` for any other name and accepts additional
    models through its constructor; ``gpt-6.1-terra`` is missing there too.
    Its OpenRouter bridge reads the live model list instead.

Decision
========

1. The profile is resolved per model record, in this order
-----------------------------------------------------------

#.  **Operator override** on the model record: reasoning yes/no, an effort
    scale, a tool route. Each field is optional; an empty field defers to
    the next source.
#.  **The bundled measured table** (``OpenAiModelProfiles``) for an exact
    family name or a dated snapshot of it.
#.  **The discovered profile** stored on the record by the last discovery
    run, with its source (next section).
#.  **The family default** in code, such as the GPT-6 default: reasoning,
    tools through Responses, no effort scale.
#.  **Unknown**: not a reasoning model, legacy route, no effort.

The bundled table outranks discovered data because each of its rows was
measured against the API; a third party's catalogue may describe its own
endpoint instead. The operator outranks everything because the operator
can measure their own deployment, which no table can.

2. The tool route is never discovered
-------------------------------------

Whether tools go through Chat Completions or Responses comes only from the
operator, the bundled table or the family default. Neither source above
states it for OpenAI's own endpoints; OpenRouter's ``tools`` flag describes
OpenRouter.

3. What each discovery source may set
-------------------------------------

*   **A provider's own model API**, where it states reasoning data about
    the endpoint being called: OpenRouter's ``reasoning`` object for a
    model configured on an OpenRouter provider. Reasoning yes/no and the
    effort scale are stored as OpenRouter states them, because they
    describe the endpoint the request goes to. Source
    ``CapabilitySource::Discovery``.
*   **OpenRouter's public catalogue as metadata for a direct OpenAI
    model**, matched by ``openai/<model id>``. Only reasoning yes/no is
    stored, never the scale, because the scale was measured to differ.
    Source ``CapabilitySource::ThirdPartyCatalog``, a new case: folding it
    into ``Discovery`` would claim the called provider confirmed it, which
    is the conflation :ref:`ADR-160 <adr-160>` exists to prevent.
*   No other discoverer stores a reasoning profile. Mistral, Gemini,
    Anthropic, Groq and Ollama listings state no reasoning flag the
    adapters consume.

The third-party lookup is **off by default** and switched on in the
extension configuration. It runs during model discovery only, never while
a request is being sent, and sends no credential and no content: it is one
anonymous ``GET`` of a public list. A failed lookup stores nothing and
leaves the record as it was.

4. Storage follows ADR-160's provenance pattern
-----------------------------------------------

The operator fields and the discovered fields are separate columns, so a
discovery run never overwrites an operator decision and the backend can
show which one applies. A missing discovered value is stored as empty, not
as "no": "not measured" and "measured: not a reasoning model" stay
different facts.

5. Consumers
------------

*   ``OpenAiProvider`` resolves the profile through the record when the
    adapter was created from a model record
    (``ProviderAdapterRegistry::createAdapterFromModel()``), and through the
    table alone otherwise, as before.
*   The configuration form's parameter constraints use the same resolved
    profile, so a reasoning model is not offered a temperature.
*   The model module shows the resolved profile and where each part came
    from.

Consequences
============

*   A new OpenAI model works without a release once an operator sets its
    profile, or once the opt-in catalogue lookup has seen it.
*   The bundled table stays the place for measured facts and still needs a
    change when OpenAI changes a model; the operator override covers the
    time until then.
*   ``CapabilitySource`` gains a case. Code that matches on it exhaustively
    must handle the new value.
*   OpenRouter's data can be wrong for OpenRouter too; it is shown with its
    source and date, and an operator override corrects it.
*   An OpenRouter-configured model's stored effort scale is recorded, but
    the OpenRouter adapter does not send an effort yet; that is a separate
    change.
