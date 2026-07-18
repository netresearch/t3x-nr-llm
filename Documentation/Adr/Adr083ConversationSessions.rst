.. include:: /Includes.rst.txt

.. _adr-083:

============================================================================
ADR-083: Conversation sessions and memory
============================================================================

:Status: Accepted
:Date: 2026-07-18
:Authors: Netresearch DTT GmbH

.. _adr-083-context:

Context
=======

Completion is stateless per call: `CompletionService::complete()` builds a fresh
``[system?, user]`` message array every time. Anything conversational — a backend
assistant, a multi-turn task — had to re-assemble and re-send the whole history
itself, and there was nowhere to persist it. Consumers were re-implementing
conversation memory badly and inconsistently.

.. _adr-083-decision:

Decision
========

**Add an explicit, persisted session model** in two UI-less log tables,
`tx_nrllm_ai_session` (one row per conversation) and
`tx_nrllm_ai_session_message` (one row per turn, ordered by ``sequence``), read
and written by a raw-SQL `AiSessionRepository` — the telemetry pattern
(:ref:`ADR-058 <adr-058>`), no Extbase and no TCA. The turns are read back as
`AiSession` / `AiSessionMessage` value objects.

**Add a `ConversationService`** (a public feature service beside
`CompletionService`) that turns the stateless path into a conversation:

- ``startSession()`` opens a session owned by the current backend user (resolved
  through the existing `BackendUserContextResolverInterface`, not a raw
  ``$GLOBALS`` read),
- ``send()`` loads the prior turns, replays them plus the new user message to the
  provider via the unchanged `LlmServiceManager::chat()`, and persists the user
  turn and the assistant reply (with the reply's model and token usage).

The provider call is untouched — this only assembles the message array and
records the turns around it. The user turn is persisted **before** the call, so a
provider failure still leaves an honest record of what the user asked.

**Retention is explicit and by inactivity.** `AiSessionRepository::purgeInactiveSince()`
deletes sessions (and their messages) whose ``last_activity`` predates a window,
driven by a ``nrllm:session:purge`` command that mirrors ``nrllm:telemetry:purge``.
There is **no** implicit, unbounded "the model remembers everything": memory is a
named session, scoped, purgeable, and cost-attributed (token counts per turn).

.. _adr-083-consequences:

Consequences
============

- Consumers get a conversation primitive instead of hand-rolling history. The
  stateless ``complete*()`` methods are unchanged for one-shot callers.
- Message rows store the conversation content (prompts and replies). That is the
  point (replayable memory), but it is privacy-relevant: retention is bounded by
  the purge command, and sessions are owned/attributed to a backend user. A
  scheduled purge task registration is a follow-up.
- The system prompt is prepended only on the first turn, so it is not duplicated
  once the persisted history already carries the conversation.
- Context-window management (summarising or truncating a long history before
  replay) is **not** in this change — the full history is replayed. A windowing
  strategy is a follow-up once real conversation lengths are observed.
- `ConversationService` depends on `AiSessionRepositoryInterface`, so it is
  unit-tested against a double; the raw SQL and schema are covered by a functional
  round-trip.
- **Public-service policy (ADR-028 count authority).** This change adds two
  ``public: true`` overrides — the ``ConversationService`` concrete and the
  ``ConversationServiceInterface`` alias — a Category-A documented downstream
  LLM-API feature pair, exactly like the Completion/Vision/Embedding services.
  The session repository stays private. The audited count therefore rises from
  **30 to 32** (Category A 17 → 19); ``PublicServicesPolicyTest`` is updated to
  match, and this ADR supersedes ADR-075 as the count authority.
