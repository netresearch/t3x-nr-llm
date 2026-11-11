.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: /Includes.rst.txt

..  _administration-a2a-remote-agents:

=================
A2A remote agents
=================

nr-llm can hand a message to a remote agent that speaks the `Agent2Agent
protocol <https://a2a-protocol.org/v1.0.1/specification/>`__ (A2A) 1.0 over
JSON-RPC, and follow the task the remote agent creates. This page covers the
client as it ships now: a record per remote agent, a PHP API and two
commands. Agent runs do not delegate to remote agents yet; that is the next
stage of :ref:`ADR-278 <adr-278>`.

Register a remote agent
=======================

Create an :guilabel:`A2A Remote Agent` record (list module, any page or the
root). The record holds:

Identifier, title, description
   The identifier names the agent on the command line.

Agent card URL
   The URL of the remote agent card, usually ending in
   :file:`/.well-known/agent-card.json`.

Credential
   Stored in nr-vault, never in the record. nr-vault adds it as
   ``Authorization: Bearer`` to every request to this agent, and writes its
   audit row. Leave it empty for an agent without authentication. The card
   itself is fetched without the credential. A credential is only sent over
   https: a request with a credential to an http URL is refused before
   anything leaves (``CREDENTIAL_NEEDS_HTTPS``).

Data class
   What may be sent to this agent. There is no default: a record without a
   data class does nothing, the card import included.

Requires approval
   On by default. The agent runtime of the next stage reads it.

Enabled
   Off by default. Nothing is sent to a disabled agent.

Limits
   Maximum response size (default 1 MiB; the transfer stops at the limit and
   the response is not parsed), request timeout (default 30 s), connect timeout
   (default 5 s; see :ref:`administration-a2a-remote-agents-limits`), and the
   wait, poll and delegation limits the agent runtime will read.

Nothing the remote agent's card says sets the data class, the enabled flag or
the approval flag: the remote does not decide its own trust.

Import the agent card
=====================

..  code-block:: bash

    vendor/bin/typo3 nrllm:a2a:import <identifier>

The import fetches the card through the address guard, checks it against the
A2A 1.0 proto and selects the first interface with binding ``JSONRPC`` and
protocol version ``1.0``. A card that offers no such interface is refused, and
the message names what it offers instead. When the interface names a
``tenant``, every request carries it.

The interface URL is where the credential goes. When it is not on the card
URL's own scheme, host and port, the first import already marks the record
:guilabel:`Card changed, confirm it`: a card cannot move the credential to
another host, or from https to http, without an administrator seeing it.

Import again whenever the remote agent changes. If the new card has another
interface URL, another tenant or other capabilities, the record shows
:guilabel:`Card changed, confirm it`, and the client refuses the agent until an
administrator
has looked at the card, chosen :guilabel:`Confirm: <interface URL>` in
:guilabel:`Confirm the card` and saved. The choice names the card the form
showed: if an import changed the card while the form was open, the save
confirms nothing and the record log says to review the card again. Saving the
record without the choice confirms nothing, and the command-line user cannot
confirm. The confirmed interface URL is shown on the record; an interface off
the card URL's host is used only while it is that one.

Changing the card URL or the credential, including entering a new secret value
for the same credential, drops the imported card: import it again, and confirm
it if asked, before the agent is used. A secret replaced in the nr-vault
module, outside this record, is not noticed.

Only administrators can open or edit remote agent records.

Which hosts the client may reach
================================

Every request, the card import included, goes through the address guard of
:ref:`ADR-202 <adr-202>` under its own egress group ``a2a_agent``
(:ref:`ADR-279 <adr-279>`): http or https, no host of this installation, every
address the host resolves to public, the connection pinned to exactly the
addresses that were checked, no redirect followed, nothing retried.

The A2A client has host lists of its own in the extension configuration,
separate from those of the ``fetch_external_url`` tool:

``a2a.client.allowedHosts``
   Empty (default) allows every public host. A host on a port other than 80
   or 443 needs an entry with the port, for example
   ``agent.example.org:8443``.

``a2a.client.deniedHosts``
   Hosts and addresses that are always refused.

``a2a.client.allowViaProxy``
   Behind an HTTP proxy the address checks cannot reach past the proxy, so A2A
   requests are refused unless this is on and the allowlist is non-empty.

A remote agent on a private, loopback or link-local address cannot be reached;
no list entry opens that.

..  note::

    The address pin is nr-vault's ``PinnedAddressHttpClientInterface``
    (nr-vault 1.5.0 and later, which nr-llm requires). If the vault's HTTP
    client does not implement it, the client refuses every request to a host
    name, with the reason ``PIN_UNSUPPORTED``, rather than send it unpinned. A
    URL with an IP address needs no pin.

Send a message from the command line
====================================

The commands act as the backend user in the extension setting
``worker.technicalBeUserUid``: nr-vault reads the credential as that user,
and the user owns the tasks the commands create. Without one configured the
commands refuse.

..  code-block:: bash

    vendor/bin/typo3 nrllm:a2a:send <identifier> "Summarise the release notes"
    # prints the remote task id and exits

    vendor/bin/typo3 nrllm:a2a:get <identifier> <task-id>
    # prints the task: state, status message, artifacts

``nrllm:a2a:send`` sends with ``returnImmediately``, so it returns as soon as
the remote agent has accepted the task. ``nrllm:a2a:get`` reads it later, as
often as needed. Only tasks this installation sent can be read. Text from the
remote agent is printed as plain text: console markup is shown literally, and
control characters (C0 and C1) and format characters such as bidi overrides
are removed, the line break excepted.

What happens to a message
=========================

Before a message leaves, the client writes an intent to the table
``tx_nrllm_a2a_send`` and commits it: owner, agent, message id and the sha256
of the request. The intent then ends in exactly one state:

``accepted``
   The remote agent created a task. Its task and context ids are stored, and
   the row is the local handle of the task.

``answered``
   The remote agent answered with a message and created no task.

``refused``
   The remote agent answered with a JSON-RPC error (code and reason are
   stored), or the client refused locally: the guard refused the address, the
   credential is missing, a redirect, an invalid response.

``outcome_unknown``
   The request may have reached the remote agent and no response was read: a
   timeout after sending, a lost connection, a response larger than the
   record's limit (``RESPONSE_TOO_LARGE``), a 5xx without a JSON-RPC body, a
   response of a kind A2A 1.0 does not know.

**An unknown outcome is never sent again**, by the client or anything else.
The remote may well have created a task. Look the task up before resending;
a resend is a new message with a new message id.

The same owner sending the same request under the same message id again gets
the first intent back and sends nothing. The same message id with a different
request is refused before anything is sent.

PHP API
=======

``Netresearch\NrLlm\Service\Agent\A2a\Client\A2aClient`` is a public service:

..  code-block:: php

    $outcome = $client->sendMessage(
        $agent,                       // A2aRemoteAgent record
        $backendUserUid,              // the local actor, owner of the task
        A2aMessage::userText(A2aClient::newMessageId(), 'Hello'),
        A2aSendMessageConfiguration::returningImmediately(),
    );

    if ($outcome->result?->isTask()) {
        $taskId = $outcome->intent->remoteTaskId;
        $task   = $client->getTask($agent, $backendUserUid, $taskId);
    }

``sendMessage()`` returns an ``A2aSendOutcome``: the journal row, whether
this call sent anything, and the result, which is a task **or** a message.
A caller has to handle both. ``getTask()`` and ``cancelTask()`` work only on
a task the same backend user created; another user's task reads as not
found, and the remote agent is not asked. ``listTasks()`` returns only the
caller's tasks, even when several users share one remote credential, and
creates no handle.

The classes are ``@internal`` while the A2A stages land; their names and
signatures may still change.

..  _administration-a2a-remote-agents-limits:

Known limits
============

-  **Connect timeout.** nr-vault takes one timeout per request. The record's
   request timeout bounds the whole request; the connect timeout is bounded by
   it and by TYPO3's ``HTTP.connect_timeout``, not on its own.
-  **Protocol.** A2A 1.0 over JSON-RPC only. A card that offers only gRPC,
   HTTP+JSON or protocol 0.3 cannot be imported. Fields of later protocol
   versions are ignored; an unknown task state never counts as completed.
-  **No streaming and no push** yet; a task is followed by asking for it.
