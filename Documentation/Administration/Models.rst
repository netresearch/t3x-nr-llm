.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _administration-models:

===============
Managing models
===============

Models represent specific LLM models available
through a provider (e.g., ``gpt-5``,
``claude-sonnet-4-6``, ``llama-3``).

.. figure:: /Images/backend-models.png
   :alt: Model list showing capabilities, context
       length, pricing, and default status
   :class: with-border with-shadow
   :zoom: lightbox

   The model list with capability badges, context
   length, and cost-per-token columns.

.. _administration-models-add:

Adding a model manually
=======================

1. Navigate to :guilabel:`AI > Setup >
   Models`.
2. Click :guilabel:`Add Model`.
3. Fill in the required fields:

   :guilabel:`Identifier`
      Unique slug (e.g., ``gpt-5``,
      ``claude-sonnet``).

   :guilabel:`Name`
      Display name (e.g., ``GPT-5 (128K)``).

   :guilabel:`Provider`
      Select the parent provider.

   :guilabel:`Model ID`
      The API model identifier as the provider
      expects it (e.g., ``gpt-5.3-instant``,
      ``claude-sonnet-4-6``).

4. Optionally set capabilities (``chat``,
   ``completion``, ``embeddings``, ``vision``,
   ``streaming``, ``tools``), context length,
   max output tokens, and pricing.
5. Click :guilabel:`Save`.

.. _administration-models-fetch:

Fetching models from a provider
===============================

Use :guilabel:`Fetch Models` beside the :guilabel:`Model ID` field
in a model edit form to browse the selected provider's catalogue:

1. Save the provider and select it in the model form.
2. Click :guilabel:`Fetch Models` and choose an entry from the dropdown.
   The choice sets the API model identifier and applies capability,
   token-limit and pricing suggestions where available. Where discovery
   cannot say what a model lacks (an OpenAI or Gemini chat model the
   built-in catalogue does not list yet, every Groq model, an Ollama model
   whose capabilities the host did not report) and the record already ticks
   that kind of model, the other ticked capabilities are kept as they are.
   Check them against the provider's documentation.
3. Review the fields in the current form, then click :guilabel:`Save`
   to persist the model record.

If live discovery fails and a built-in catalogue is available, the dropdown
shows that catalogue with a warning. Review those suggestions against the
provider before relying on them.

.. _administration-models-callable:

A listed model is not necessarily usable
----------------------------------------

A provider's model list is not proof that a model answers. OpenAI's
``GET /v1/models``, for example, still lists identifiers that the chat
endpoint rejects as deprecated, and identifiers that only work on another
endpoint or are not chat models at all.

A model that answers a plain request can still fail in use:

- Some models accept only their default temperature and refuse a request
  that sets one. The :php:`completeFactual()` and
  :php:`completeCreative()` presets send their own temperature.
- Some models refuse tool calls.
- A reasoning model spends its output budget on reasoning first. With a
  small budget it answers HTTP 200 with an empty message, which looks like
  a broken integration rather than an error.

Before you rely on a new model, run :guilabel:`Test Configuration` on a
configuration that uses it, and exercise the options and tools your use case
sends.

.. _administration-models-provenance:

Which capabilities the provider actually confirmed
==================================================

A capability badge in the model list says *that* the
model has the capability, not *who said so*. Those
are different claims, and the list separates them.

A grey badge means a live answer from the provider's
own model endpoint declared that capability, and the
row says when. A yellow badge with a question mark
means nobody confirmed it: either an administrator
ticked it by hand, or it came from the static model
catalogue bundled with the extension. Hover the
badge for the reason.

Expect the yellow badge for OpenAI, Anthropic, Groq
and the Gemini models the extension knows by name,
even against a reachable API. Those model endpoints
list model ids and no capabilities, so the tokens
come from the bundled catalogue on a live run
exactly as on an unreachable one, and the badge says
so rather than borrowing the provider's authority.
Mistral, OpenRouter, Ollama and Gemini releases
newer than this extension do report capabilities per
model, and those confirm to grey.

Use the :guilabel:`Confirm capabilities` row action
to ask the provider. It runs the same discovery the
wizard uses, records what came back, and refreshes
the confirmation date. A model the provider does not
list is reported as such — that is not a
confirmation, so nothing is stored.

Confirming never edits what you declared. A
capability you ticked that the provider does not
advertise stays on the model and simply stops
borrowing the provider's authority for it. That
matters most for a model created from the bundled
catalogue: nothing about it was ever checked against
the live API until you confirm it.

The three underlying fields — what was confirmed,
when, and whether the answer was live or from the
catalogue — are also visible read-only on the
:guilabel:`Capabilities` tab of the model record.

Routing does not use this. Eligibility below still
reads the declared capabilities, confirmed or not:
making it depend on confirmation would silently drop
every model an administrator declared by hand.

.. _administration-models-reasoning:

Reasoning models the extension does not know yet
================================================

Whether a model is a reasoning model, which efforts it accepts and where its
tool calls go are bundled with the extension for the OpenAI models it knows.
A newer model works without a release once its record says so: set
:guilabel:`Reasoning model`, :guilabel:`Accepted reasoning efforts` and
:guilabel:`Tool calls go through` on the :guilabel:`Capabilities` tab. A value
set there wins over every other source; see
:ref:`configuration-model-reasoning-profile` for the order.

:guilabel:`Confirm capabilities` also records what discovery stated:

*   For an OpenRouter provider, OpenRouter's model list states per model
    whether it reasons and which efforts it accepts. Both are stored with
    the source ``discovery``.
*   For an OpenAI provider, OpenAI's model list states nothing. With
    :ref:`discovery.thirdPartyReasoningCatalog <configuration-discovery>`
    switched on, the
    reasoning flag is read from OpenRouter's public list for
    ``openai/<model id>`` and stored with the source
    ``third_party_catalog``. The effort scale is never taken from it,
    because it was measured to differ from what OpenAI accepts.

A run that states nothing for the model stores nothing, so an earlier answer
stays and nothing turns into "no". The model list shows, below the
capabilities of each OpenAI-compatible model, whether it is sent as a
reasoning model and which source decided it.

.. _administration-models-routing:

Which model a criteria-mode configuration picks
===============================================

A configuration set to *Dynamic (Criteria)* names no
model. One is chosen per call from the models that
are active, in two steps that never mix.

**Eligibility** is a hard yes or no. A model is
considered only if it declares every capability the
criteria require, uses a permitted adapter type,
meets the minimum context length, stays within the
cost ceiling, and — for the operation being run —
does not declare capabilities that exclude it. A
model refused here cannot come back: nothing about
its speed, price or quality is even looked at.

**Ranking** orders what is left. The provider
:guilabel:`Priority` you set decides first, always.
Below it, the extension configuration option
:guilabel:`Routing policy` (category *routing*)
chooses what else counts:

Provider priority
   The default. Nothing beyond your priority, the
   default-model flag and the sorting order, plus
   cost where the criteria set *prefer lowest cost*.
   This is what the extension has always done.

Balanced, Quality, Economy
   Add measured signals — evaluation quality scores
   and recent provider health — weighted differently.
   *Economy* also weighs cost when the criteria did
   not ask for it.

With ``routing.callHistory`` switched on in the
extension configuration, the measured modes also
rank by each model's recent calls of the same
operation: how long they took, and what they cost
per call. See :ref:`administration-governance-routing`.

The measured modes are opt-in on purpose: they change
which model serves a call, so an upgrade never
switches them on for you. Two rules make them safe to
try. Your provider priority is never overruled by a
measurement — a priority is an instruction, a score
is evidence. And a model nobody has measured is not
punished for it: an absent signal is skipped, not
counted as zero.

If a criteria-mode configuration selects nothing, the
cause is usually that every matching model declares
it cannot serve that operation. The extension says so
explicitly rather than letting the provider fail with
an opaque error.
