.. include:: /Includes.rst.txt

.. _adr-077:

============================================================================
ADR-077: Plain completion joins the named-configuration path
============================================================================

:Status: Accepted
:Date: 2026-07-17
:Authors: Netresearch DTT GmbH

.. _adr-077-context:

Context
=======

The three-tier model (Provider → Model → Configuration,
:ref:`ADR-001 <adr-001>`) reaches every chat-shaped and embedding
capability through a ``*ForConfiguration`` entry point:
``chatWithConfiguration()``, ``streamChatWithConfiguration()``,
``chatWithToolsForConfiguration()`` and — since
:ref:`ADR-055 <adr-055>` — ``embedForConfiguration()`` all resolve the
adapter from a DB-backed ``LlmConfiguration`` and run through the
middleware pipeline, so budgets are enforced and cost is attributed per
configuration.

The high-level ``CompletionService`` did not. Its ``complete()`` family
resolves only the *instance-default* configuration (``chat()`` picks the
active default, :ref:`ADR-034 <adr-034>`). A consumer that needs several
distinct named text configurations — a summariser, a classifier and a
chatbot in one extension — could not target them by identifier; every
plain completion went to the single default. The low-level
``LlmServiceManager::completeWithConfiguration()`` existed but routes
through the provider's raw ``complete`` operation (no system-prompt
shaping, no response-format normalisation, no per-user budget metadata),
so it is not a drop-in for the message-based ``complete()`` path.

.. _adr-077-decision:

Decision
========

**Plain completion joins the configuration path.**
``LlmServiceManager::completeForConfiguration(string $prompt,
LlmConfiguration $configuration, ?ChatOptions $options = null)`` mirrors
``chat()``'s default-configuration branch — it builds the system/user
``ChatMessage`` value objects, injects the configuration's skills, and
threads the per-user budget and idempotency metadata from the options —
but against the caller's chosen configuration instead of the resolved
default. A pinned provider on the options is irrelevant on the
configuration path and is dropped, exactly as ``chat()`` does. The method
takes a typed ``ChatOptions`` rather than the low-level
``completeWithConfiguration()`` metadata/override arrays, so budget and
idempotency parity is handled once inside the manager.

The high-level feature service follows.
``CompletionServiceInterface`` gains the named-configuration counterparts
of its whole family: ``completeForConfiguration()``,
``completeJsonForConfiguration()``, ``completeMarkdownForConfiguration()``,
``completeFactualForConfiguration()`` and
``completeCreativeForConfiguration()``. Each applies the *same* option
transforms as its instance-default twin (response-format normalisation,
Markdown system-prompt augmentation, factual/creative presets) before
delegating to the manager — the shared transforms are extracted into
private helpers so the two paths cannot drift.

Method-based, not a ``ChatOptions`` field. Targeting a configuration is
expressed as an explicit ``LlmConfiguration`` parameter, consistent with
the entire ``*ForConfiguration`` family, keeping ``ChatOptions`` a pure
scalar readonly DTO.

.. _adr-077-consequences:

Consequences
============

- Completion consumers select a backend-managed configuration by
  identifier instead of relying on the single instance default;
  per-configuration budgets and cost attribution apply to plain
  completion like to every other capability.
- Behaviour parity holds: the JSON/Markdown/factual/creative variants
  apply identical transforms on the configuration path as on the
  instance-default path, guaranteed by shared private helpers rather
  than duplicated logic.
- ``CompletionServiceInterface`` and ``LlmServiceManagerInterface``
  gained methods — implementers outside this repo must add them. In-repo
  the concrete services and the hand-written ``StaticCompletionService``
  test double are updated.
- The low-level ``completeWithConfiguration()`` (raw ``complete``
  operation) is unchanged and remains available for callers that
  deliberately want the non-chat completion endpoint.
