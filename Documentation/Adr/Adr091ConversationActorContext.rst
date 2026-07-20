.. include:: /Includes.rst.txt

.. _adr-091:

============================================================================
ADR-091: Sessions are owned — an explicit actor context on every turn
============================================================================

:Status: Accepted
:Date: 2026-07-20
:Authors: Netresearch DTT GmbH

.. _adr-091-context:

Context
=======

ADR-083 introduced conversation sessions and stated that a session is owned by
a backend user. The implementation never enforced it. ``ConversationService::send()``
loaded a session by uuid and used it; the repository filtered by uuid alone. Any
caller holding a uuid could read another user's conversation history and continue
it — including through the ``public: true`` service any downstream extension can
reach.

Two further gaps came from the same root, an implicit caller:

- **The bound configuration was decorative.** ``startSession()`` stored an
  ``LlmConfiguration`` identifier that ``send()`` never read; the turn went
  through ``LlmServiceManager::chat()``, which resolves the *installation
  default*. A session opened against a locally hosted, tool-free configuration
  silently continued against whatever default the installation had — a different
  model, a different budget and a different guardrail set than the conversation
  started with.
- **Sequence numbers were derived from a read-modify-write.** ``$session->messageCount``
  was read, used as the next sequence, and written back. Two concurrent turns
  produced two rows with the same sequence, and the index on
  ``(session, sequence)`` was not unique, so the database accepted them and the
  replay order became undefined.

.. _adr-091-decision:

Decision
========

**Every stateful entry point takes an explicit** ``AiActorContext``.

.. code-block:: php

   $actor = AiActorContext::backendUser($uid, $isAdmin, $groupIds);
   $actor = AiActorContext::serviceAccount('nrllm-worker');   // CLI, scheduler, queue
   $actor = AiActorContext::anonymous();                      // owns nothing, may do nothing

   $session = $conversations->startSession($actor, 'Teaser für Seite 17', $config);
   $reply   = $conversations->send($actor, $session->uuid, 'Kürzer, bitte.');

The actor is passed, not inferred from ``$GLOBALS['BE_USER']``. A queue consumer
can therefore act for the user who queued the work instead of inheriting whoever
happens to be logged in, and the decision is testable without a backend
bootstrap.

``send()`` enforces three rules in order:

1. **Ownership.** The actor must own the session, be an administrator, or be a
   service account. A uuid is an identifier, never an authorisation.
2. **Configuration binding.** The session's configuration identifier is resolved
   on **every** turn through ``ConfigurationResolver::getActiveByIdentifierForActor()``,
   which applies the activity and BE-group guards against the *actor* rather than
   the ambient user. A configuration that was deactivated, deleted or newly
   restricted stops the conversation with an access error instead of quietly
   falling back to the default. Turns then run through the new
   ``LlmServiceManager::chatForConfiguration()``, the message-list counterpart of
   ``completeForConfiguration()``.
3. **Attribution.** The turn is attributed to the acting backend user unless the
   caller set an explicit owner, so per-user budgets apply to conversations
   exactly as they do to one-shot completions.

**Sequence allocation moves into the repository, and the database decides.**
``(session, sequence)`` is now a UNIQUE key; ``appendMessageAtNextSequence()``
reads the next free slot, tries to take it, and retries on a unique-constraint
violation. ``touch()`` advances ``last_activity`` unconditionally but raises
``message_count`` only when it grows, so a slower concurrent turn cannot report
the session back down. ``uuid`` is unique as well.

Resolving the identifier per turn was chosen over freezing a configuration
snapshot at ``startSession()``: a tightened budget, a narrowed tool set or a new
guardrail policy must take effect on running conversations, not only on new ones.

.. _adr-091-consequences:

Consequences
============

- **Breaking change for downstream consumers.** Both ``ConversationServiceInterface``
  methods gained a leading ``AiActorContext`` parameter. In a 0.x line this is
  accepted rather than papered over with an implicit fallback, because the
  implicit path is exactly the defect: a caller that forgets the context would
  otherwise silently keep the old, unauthenticated behaviour.
- A session opened **without** a configuration keeps the generic path and the
  installation default — the pre-ADR-083 behaviour for callers that never chose
  one.
- ``ConfigurationResolver`` gains an actor-aware sibling to its user-less
  ``getActiveByIdentifier()``. The two coexist: user-less callers (CLI resolving
  by identifier with no actor at all) keep the stricter refusal of restricted
  configurations.
- The UNIQUE key on ``(session, sequence)`` is added to an existing table. An
  installation that already produced colliding rows through the race must
  resolve those duplicates before the database analyzer can apply the index.
- Ownership is enforced in the service, not in the repository query. The
  repository keeps a uuid lookup; the service is the single place that decides
  entitlement, which keeps the rule visible and testable in one location.
- Denying access raises ``AccessDeniedException``. Session uuids are random v4
  values, so distinguishing "unknown" from "not yours" leaks nothing an attacker
  could enumerate, and the distinction is what an operator needs in a log.

This ADR supersedes the ownership and configuration paragraphs of :ref:`ADR-083
<adr-083>`; the session model, retention story and system-prompt handling
described there are unchanged.
