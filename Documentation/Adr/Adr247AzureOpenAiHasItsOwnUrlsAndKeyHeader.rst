.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-247:

=================================================================
ADR-247: Azure OpenAI has its own URLs and key header
=================================================================

:Status: Accepted
:Date: 2026-11-03
:Authors: Netresearch DTT GmbH

Context
=======

The adapter type ``azure_openai`` mapped to :php:`OpenAiProvider`. It sent
the key as ``Authorization: Bearer <key>``, built every URL as
``<endpoint>/<operation>`` and sent ``OpenAI-Organization`` when one was set.
Azure OpenAI reads a key from the ``api-key`` header; a bearer token there
must be a Microsoft Entra token. And the classic Azure URL names the
deployment and the API version:
``/openai/deployments/<deployment>/chat/completions?api-version=…``. An
Azure provider record therefore worked only if the operator happened to put
a v1 endpoint into it and Azure accepted the bearer header for it, and the
setup wizard's connection test and configuration call had the same defect.

Microsoft's current documentation (the v1 API page, updated 2026-05) names
two shapes: the v1 API under ``/openai/v1/`` with the deployment name as the
body's ``model`` and no ``api-version``, and the deployment API with a dated
``api-version`` whose latest generally available version is ``2024-10-21``.
Both take the ``api-key`` header.

Decision
========

**Azure gets its own adapter class,** :php:`AzureOpenAiProvider`, **a
subclass of** :php:`OpenAiProvider`. The wire format, tool calls, streaming,
embeddings and parsing are OpenAI's and are inherited. :php:`OpenAiProvider`
is no longer ``final`` for that reason.

**The endpoint URL picks the shape.** :php:`AzureOpenAiEndpoint` decides:

- an endpoint ending in ``/openai/v1`` keeps the OpenAI paths below it;
- any other endpoint is a resource URL; a trailing ``/openai`` is dropped,
  and requests go to ``/openai/deployments/<deployment>/<operation>``
  with ``api-version``. The deployment is the call's model id, so an Azure
  model record holds the deployment name. ``models`` goes to
  ``/openai/models`` with ``api-version``; ``responses`` goes to
  ``/openai/v1/responses``, which the deployment API serves only in preview
  versions.

**The version is a provider option,** ``apiVersion`` in the record's options
JSON, default ``2024-10-21``. A value not shaped like ``YYYY-MM-DD`` with an
optional ``-preview`` falls back to the default, because it ends up in a URL.

**The key goes in** ``api-key``, placed by the nr-vault HTTP client
(``SecretPlacement::Header``), so the key never passes through nr-llm code.
``OpenAI-Organization`` is not sent.

**Every request path builds its URL in one place.**
:php:`AbstractProvider::endpointUrl()` is the default
``<endpoint>/<operation>``; blocking requests, both streamed paths of the
OpenAI adapter and the connection test go through it, and the Azure adapter
overrides it.

**The setup wizard follows.** Its connection test and its configuration call
use the same URLs and header for ``azure_openai``, and it recognises
``*.services.ai.azure.com`` and ``*.cognitiveservices.azure.com`` besides
``*.openai.azure.com``.

Alternatives
============

**Microsoft Entra ID tokens.** Rejected for now: a token has to be fetched
and refreshed against Entra, which needs a client credential flow nr-vault
does not provide. A key covers the deployments nr-llm serves.

**Only the v1 API.** Simpler, and Microsoft recommends it. Rejected as the
only shape because an existing resource URL and a pinned ``api-version`` are
what operators have, and the deployment path is what the ticket asks to be
correct; the endpoint URL lets an operator choose v1.

**A flag on** :php:`OpenAiProvider` **instead of a subclass.** Rejected: the
adapter would branch on its own adapter type in five places.

Consequences
============

- ●● An Azure OpenAI provider record works with a resource URL and an API
  key, as Azure documents them.
- ● The v1 API works by entering its URL.
- ◐ An Azure model record's model id must be the deployment name.
- ◑ :php:`OpenAiProvider` can be subclassed; nothing outside nr-llm relies
  on it being final.
- ◑ No live Azure call was made: no Azure OpenAI key was available. The
  requests are pinned as recorded requests in unit tests.

Net Score: +4
