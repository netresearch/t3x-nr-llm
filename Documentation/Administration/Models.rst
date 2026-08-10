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

1. Navigate to :guilabel:`Admin Tools > LLM >
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

Instead of adding models manually, use the
:guilabel:`Fetch Models` action to query the
provider API and auto-populate the model list:

1. Ensure the provider is saved and the connection
   test passes.
2. On the model list or model edit form, click
   :guilabel:`Fetch Models`.
3. The extension queries the provider API and
   creates model records with capabilities and
   metadata pre-filled.

This is the recommended approach — it ensures model
IDs match the provider exactly and keeps your
catalogue current as providers release new models.

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
