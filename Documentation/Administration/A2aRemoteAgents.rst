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
client: a record per remote agent, a PHP API, the commands, agent runs
that delegate a step to a remote agent and wait for it
(:ref:`ADR-281 <adr-281>`, see :ref:`administration-a2a-delegation`), and
push notifications that shorten the wait.

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
   On by default. A run that calls this agent waits for a person's approval
   first (:ref:`ADR-084 <adr-084>`).

Enabled
   Off by default. Nothing is sent to a disabled agent.

Limits
   Maximum response size (default 1 MiB; a larger response is not parsed.
   The transfer stops at the limit when the nr-vault client streams, which
   needs PHP's ``curl_multi_*`` functions; otherwise the whole body is
   received and the request timeout bounds the transfer), request timeout
   (default 30 s), connect timeout
   (default 5 s; see :ref:`administration-a2a-remote-agents-limits`), and the
   limits of a delegating run: maximum wait (default 3600 s), minimum and
   maximum poll interval (5 s and 300 s) and delegations per run (3).

Nothing the remote agent's card says sets the data class, the enabled flag or
the approval flag: the remote does not decide its own trust.

Import the agent card
=====================

..  code-block:: bash
    :caption: Import or re-import the agent card

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
    :caption: Send a message, then read the task

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
   credential is missing, a redirect, an invalid response with a status
   other than 2xx or 5xx.

``outcome_unknown``
   The request may have reached the remote agent and no response was read: a
   timeout after sending, a lost connection, a response larger than the
   record's limit (``RESPONSE_TOO_LARGE``), a 5xx without a JSON-RPC body, a
   2xx that is not a valid response (``INVALID_AGENT_RESPONSE``), a response
   of a kind A2A 1.0 does not know.

**An unknown outcome is never sent again**, by the client or anything else.
The remote may well have created a task, but no task id came back, so
``nrllm:a2a:get`` and ``listTasks()`` cannot find it: they know only tasks
with a local handle. Check on the remote agent's side, with its operator or
its own tools, whether the message id created a task before sending again;
a resend is a new message with a new message id.

The same owner sending the same request under the same message id again gets
the first intent back and sends nothing. The same message id with a different
request is refused before anything is sent.

PHP API
=======

``Netresearch\NrLlm\Service\Agent\A2a\Client\A2aClient`` is a public service:

..  code-block:: php
    :caption: Send a message and read the task from PHP

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

..  _administration-a2a-delegation:

Delegating a step of an agent run
=================================

Each enabled remote agent with an imported card offers one tool per skill on
its card, named ``a2a_<identifier>_<skill id>``. Like an MCP tool it is off
until an administrator enables it on a configuration, it carries the record's
data class (:ref:`ADR-094 <adr-094>`), it asks for approval when the record
says so, and it goes through the egress group ``a2a_agent``. Listing the tools
opens no connection. Approval, data class and the enabled switch are read from
the record when the tool is called, so a change reaches a running worker.

A model turn that calls such a tool together with any other tool is refused
before anything of it runs; the model is told to call the delegation alone.
At most one delegation is pending per run segment.

The tool sends the message with ``returnImmediately``. A message answer, or a
task the remote agent already finished, is the tool's result and the run goes
on. Otherwise the run is suspended in the status ``waiting_for_dependency``:
it holds no lease and no worker, the inbox shows it in the section
:guilabel:`Waiting for a remote agent`, never as a decision, and
``nrllm:agent:status`` counts it.

Schedule the poller like ``nrllm:agent:work``, at least as often as the
shortest minimum poll interval of the records:

..  code-block:: bash

    vendor/bin/typo3 nrllm:a2a:poll

It looks at each waiting run's remote task first at the record's minimum poll
interval after the send, then at twice the last interval up to the maximum,
with up to a quarter of jitter. A task that finished, failed or asks for input
continues the run with the remote's answer as the tool's result, as the run's
owner and in its workspace. A request that fails on the way counts as no news.
After the record's maximum wait the run continues with a "timed out" result
and the remote task is cancelled once. The poller acts as
``worker.technicalBeUserUid`` towards nr-vault, like the commands.

Cancelling a waiting run (``nrllm:agent:cancel``) cancels its remote task once,
as the owner of the delegation, and the run ends cancelled whatever the remote
answers; the answer is kept in the journal (``cancel_outcome``).

The limits of a run (its round cap, token budget, and the delegations it made
to each agent) carry over every wait. The delegation limit counts only what
this installation sends; a remote agent that delegates further does so outside
nr-llm's control.

..  important::

    While nr_mcp_agent is installed, no delegation tool is offered to any run.
    nr_mcp_agent's chat cannot be told apart from other runs and does not yet
    understand a run that waits for a remote agent; no nr_mcp_agent release
    does so far.

..  _administration-a2a-remote-agents-push:

Push notifications
==================

Push is off by default. With :guilabel:`Receive A2A push notifications`
(``a2a.client.push``) on, a delegation asks the remote agent to post the
changes of its task to this installation, so the run does not wait for the
next poll (:ref:`ADR-285 <adr-285>`). Polling stays: a callback only brings
the next ``GetTask`` forward.

-  **Endpoint.** :guilabel:`Site of the A2A push endpoint`
   (``a2a.client.pushSite``) names the site whose A2A base URL (site setting
   ``nrllm.a2a.baseUrl``, see :ref:`administration-a2a-server`) carries
   ``POST <base>/push``. The worker that registers a callback has no site of
   its own, so the site is named here. The endpoint answers whether or not
   the server role is on. With push off, or without such a site, it does not
   exist: the request passes through to TYPO3 like any other URL, as the A2A
   paths do while the server role is off.
-  **Registration.** Only a remote whose card declares
   ``pushNotifications`` is asked. After the remote accepted the task, nr-llm
   writes a local registration with a new random callback credential, sends
   ``CreateTaskPushNotificationConfig`` with the callback URL and the
   credential as ``authentication`` (scheme ``Bearer``, no ``token``), stores
   the config id the remote answers, and then reads the task once with
   ``GetTask``, so a change between the send and the registration is not
   missed. A task settled by then is the tool's result at once. The
   credential is shown to the remote only; nr-llm stores its public prefix
   and its SHA-256 hash, as for the tokens of A2A clients
   (:ref:`ADR-280 <adr-280>`). A refused create leaves the run to polling. A
   create whose outcome is unknown (a timeout after it was sent) is never
   sent again; the credential stays valid, because the remote may hold the
   config.
-  **Callbacks.** A callback needs ``Authorization: Bearer`` with the
   credential of an open registration; anything else is 401 with
   ``WWW-Authenticate: Bearer realm="a2a-push"`` and stores nothing. The
   answer is the same for a missing, unknown or wrong credential and for a
   closed registration. An ``X-A2A-Notification-Token`` header is ignored:
   it neither authenticates nor refuses. ``A2A-Version`` and a content type
   are not required. Failed attempts are logged with the source address,
   never with the credential, and limited per address by the rate limiter
   ``nrllm-a2a-push-auth`` (10 per 15 minutes; override it in
   ``$GLOBALS['TYPO3_CONF_VARS']['SYS']['rateLimiter']['nrllm-a2a-push-auth']``).
-  **Inbox.** A body above ``a2a.client.maxPushBytes`` (default 64 KiB) is
   413, and a declared ``Content-Length`` above it is refused before the body
   is read. A body that is not a JSON object is 400. A valid callback is
   stored sealed, like the run state, and only then answered 202, which means
   received and nothing more. The inbox holds at most
   ``a2a.client.maxPushInboxPerTask`` (default 8) callbacks per delegation
   and ``a2a.client.maxPushInbox`` (default 1000) in total that the worker
   has not looked at yet; one more is 429 with ``Retry-After``.
-  **What a callback does.** ``nrllm:a2a:poll`` looks at a delegation with a
   stored callback at the record's minimum poll interval after its last look
   instead of at the scheduled time, never earlier. The look is ``GetTask`` as
   the run's owner; the state the run continues with is what that answers,
   never the callback body. A look marks every callback received until then
   as reconciled. When the run continues, its registration and its callbacks
   are deleted; a later callback is 401, and a duplicate never continues a
   run twice.
-  **Cleanup.** Registrations and callbacks are deleted when the run
   continues, and with their run by ``nrllm:privacy:purge``. The remote's
   config is not deleted; a sender that keeps posting gets 401.

..  _administration-a2a-remote-agents-limits:

Known limits
============

-  **Connect timeout.** nr-vault takes one timeout per request. The record's
   request timeout bounds the whole request; the connect timeout is bounded by
   it and by TYPO3's ``HTTP.connect_timeout``, not on its own.
-  **Protocol.** A2A 1.0 over JSON-RPC only. A card that offers only gRPC,
   HTTP+JSON or protocol 0.3 cannot be imported. Fields of later protocol
   versions are ignored; an unknown task state never counts as completed.
-  **Streaming is for the PHP API and the command line, not for runs.** A
   run that delegates sends ``SendMessage`` and is resumed by polling, so it
   never holds a worker for the remote's work (:ref:`ADR-281 <adr-281>`).
   ``A2aClient::sendStreamingMessage()``, ``A2aClient::subscribeToTask()``
   and ``nrllm:a2a:send --stream`` read an agent's stream when its card
   declares ``streaming``: the remote task is stored with the first event
   that names it, a stream that breaks before that is an unknown outcome and
   is never sent again, and a stream that ends before the task finished
   leaves it to ``GetTask`` (``nrllm:a2a:get``). A stream lasts at most the
   record's maximum wait, at least its request timeout; one event may be at
   most the record's maximum response size.
-  **Push without an embedded config.** nr-llm registers a callback with
   ``CreateTaskPushNotificationConfig`` after the remote accepted the task,
   never inside ``SendMessage``. A callback the remote sends before that
   request is impossible, because the remote does not know the URL yet.
-  **The total inbox bound** is checked in the transaction that stores the
   callback, under a lock on the registration only. Callbacks of different
   delegations arriving at the same moment can exceed it by their number;
   the bound per delegation is exact.
-  **An interrupted remote task** (``TASK_STATE_INPUT_REQUIRED``,
   ``TASK_STATE_AUTH_REQUIRED``) is handed to the model as such; nothing
   answers the remote agent yet.
-  **A run that cannot be suspended** after its message was accepted fails;
   the remote task stays in the journal as ``accepted`` and is not sent again.
