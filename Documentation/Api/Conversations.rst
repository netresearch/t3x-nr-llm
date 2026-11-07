.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _api-conversations:

====================
Stored conversations
====================

Inject
:php:`Netresearch\NrLlm\Service\Feature\ConversationServiceInterface`
when each message should include the session's previous turns. It provides
``startSession()`` and ``send()``; persisted agent lifecycle operations belong
to :ref:`api-agent-runtime`.

.. _api-conversations-example:

Open and continue a session
===========================

.. code-block:: php
   :caption: Conversation under an authenticated backend user's identity

   use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;

   $actor = AiActorContext::fromBackendUser($backendUser);
   $session = $conversations->startSession(
       $actor,
       'Article review',
       $configuration,
   );
   $reply = $conversations->send(
       $actor,
       $session->uuid,
       'Suggest a shorter introduction.',
   );

Resolve ``$configuration`` through the guarded resolver shown in
:ref:`integration-guide-step5`. Construct the actor from authenticated server
state, never from user-supplied identity or permission fields.

.. _api-conversations-contract:

Method contracts
================

``startSession(AiActorContext $actor, string $title = '',
?LlmConfiguration $configuration = null): AiSession``
    Binds the session to a configuration. Omitting it resolves and binds the
    actor's usable default at creation time. Changing the installation's
    default later does not switch this session. Throws
    :php:`AccessDeniedException` for an unauthenticated actor or
    :php:`ConfigurationNotFoundException` when no usable default exists.

``send(AiActorContext $actor, string $sessionUuid, string $userMessage,
?ChatOptions $options = null): CompletionResponse``
    Replays the stored history, sends the new message and stores the user and
    assistant turns. Throws :php:`InvalidArgumentException` for an unknown
    UUID and :php:`AccessDeniedException` when the actor lacks access or the
    bound configuration is no longer usable.

Knowing a session UUID grants no access. Every call checks the actor against
the session owner. CLI and queue callers use an explicitly scoped service
account rather than assuming user zero or inheriting a global backend user.

Conversations persist message content. Apply the session retention policy and
schedule deletion as described in :ref:`administration-data-retention`.
