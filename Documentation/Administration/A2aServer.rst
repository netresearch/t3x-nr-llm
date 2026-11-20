.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: /Includes.rst.txt

..  _administration-a2a-server:

==========
A2A server
==========

nr-llm can answer other agents that speak the `Agent2Agent protocol
<https://a2a-protocol.org/v1.0.1/specification/>`__ (A2A) 1.0 over JSON-RPC.
It publishes selected LLM configurations as skills, and a registered remote
client sends a message to one of them. The message becomes an agent run that a
worker executes; the client receives the result as an A2A task
(:ref:`ADR-280 <adr-280>`, :ref:`ADR-282 <adr-282>`, :ref:`ADR-283 <adr-283>`).

This page covers stages 3 to 5b of spec 051 (tasks, a client that answers a
task waiting for input, streams, and push notifications to a client's
webhook) and the files of stage 5c, switched off by default: files a client
sends, files from FAL a task sends, inline or as a protected download, and
a client's file taken into the profile's FAL inbox after a person approved
it (:ref:`Taking a client's file into FAL <administration-a2a-server-fal-adoption>`).

Switch it on
============

#. Set :guilabel:`Answer A2A requests (server role)`
   (``a2a.server.enabled``) in the extension configuration. Off, no site
   answers an A2A request, whatever its settings say.
#. Name the base URL in the settings of each site that should answer, for
   example in :file:`config/sites/<site>/settings.yaml`:

   ..  code-block:: yaml

       nrllm:
         a2a:
           baseUrl: 'https://www.example.org/a2a'

   Write the nested form shown here, or add the set
   :guilabel:`LLM Foundation` (``netresearch/nr-llm``) to the site's
   dependencies. On a site without the set, a dotted key such as
   ``nrllm.a2a.baseUrl: '…'`` is not read, and the site answers no A2A
   request (:ref:`administration-site-settings-setup`).

   The JSON-RPC endpoint is this URL; the agent card is published at
   :file:`https://www.example.org/a2a/.well-known/agent-card.json`. A site
   without the setting answers neither. Every URL in the card and in a
   response is built from this setting, never from the request's ``Host``
   header; a request to another host, scheme or port is not an A2A request
   and passes on to the site.
#. Run a worker. A task is accepted only while a worker can be shown to run:
   a ``messenger:consume`` consumer with a heartbeat, or a tick of
   ``nrllm:agent:work`` within the last 120 seconds. Schedule
   ``nrllm:agent:work`` every minute on an installation without a consumer.
   ``nrllm:agent:status --transport=sweep --minimum-workers=1`` checks the
   sweep. Without a worker, ``SendMessage`` answers HTTP 503 with
   ``WORKER_UNAVAILABLE`` and stores nothing.

Publish a skill
===============

Create an :guilabel:`A2A Publication Profile` record (administrators only).

Skill id, name, description, tags, examples
   What the agent card lists for the skill. The skill id is how a client
   names it.

Domain handler
   What turns the client's message into the run and the run's answer into
   the result. ``nr_llm.text`` sends the message text to the LLM
   configuration and returns the answer as one text artifact: a text check or
   a summary, with the instructions of the configuration's system prompt.
   ``nr_llm.page_metadata.v1`` writes the metadata of one page in one
   language as a draft, held to that page and language
   (:ref:`a2a-page-metadata-skill`).

   Two handlers are for editorial texts used outside TYPO3
   (:ref:`ADR-302 <adr-302>`). Their final answer is checked against the term
   lists and the rule-set judge of the configuration before it is delivered,
   whatever its length and also when the run only read. A broken term, a rule
   broken under a judge in block mode, a judge in block mode that cannot
   answer, or term lists that cannot be read stop the task as failed. A judge
   in report mode that cannot answer, and a configuration with no term list
   and no judge, deliver the text as not checked.

   - ``nr_llm.editorial_text`` writes the text from the client's message, with
     the read-only tools the profile allows. Writing tools and tools that ask
     the client a question, such as ``ask_choice``, are not offered: a
     question would reach the client before the check.
   - ``nr_llm.editorial_check`` checks the text the client sends, for example
     a draft the editor changed, without generating one: no model writes and
     no tool is called; only the judge of the configuration is asked.

   Their result has two artifacts (:ref:`ADR-301 <adr-301>`): the first holds
   only the text, as one text part, to be pasted as it is; the second,
   ``report``, holds one data part with the check, the digests of the checked
   and of the delivered text, the rule state, and an editorial state
   (``draft``, ``needs_revision``, ``needs_human_review``). A completed task is
   no approval to publish; the editorial state says what the text still needs.
   A text checked by ``nr_llm.editorial_check`` is a ``draft`` only at the
   answer level ``full``, because below it the check reads the redacted text.
   A stopped task carries only the report, and only where its answer level is
   ``full`` or ``redacted``; below that it carries none. A level tightened
   after the result was stored withholds the report's findings.

LLM configuration
   The configuration the runs use. If its answer level keeps no result
   (``metadata`` or ``none``, set on its provider), the profile cannot be
   public, and a call is refused before a task exists with
   ``RESULT_NOT_RETAINABLE`` (:ref:`ADR-282 <adr-282>`). A configuration that
   is switched off, or restricted to backend groups the client's technical
   user is not in, refuses a call with ``PROFILE_UNAVAILABLE``. The agent card
   lists a skill only while its handler is available and its configuration
   is switched on and keeps a result.

Tools
   Comma-separated tool names the run may use. Without :guilabel:`May write`,
   a profile offers only tools that declare that they only read: a list with
   any other tool is not stored, and an existing list stays as it was. Empty:
   no tools. A run can ask the client a question with
   ``ask_choice`` when the tool is listed here and enabled in the tool
   management; the task then waits for the client's answer (see below).

May write
   The run may use the profile's writing tools too. They write drafts only,
   into the pinned workspace of the calling client (:ref:`ADR-260 <adr-260>`);
   a writer that does not support workspaces refuses there. A client pinned
   to live cannot call a writing skill: the record is not saved that way, and
   a call is refused with ``-32004``. A write that needs an approval waits for
   a person of this installation, as in every run; the task stays
   ``WORKING`` meanwhile, with the status message ``The task is waiting for
   an approval in TYPO3.``. The result of a writing task carries a second
   artifact, ``writes``, that lists every write the run executed (table,
   record uid, workspace, kind), taken from the write events, not from the
   model's answer; a run that wrote nothing says so. Every other record a
   completed call wrote beside its announced write is listed in a block of
   its own, "Further records the run's completed tool calls wrote": records
   core wrote along with the write (the children of a copied record, for
   example), and what a listener of the write, such as AI Label, stored for
   the call, with the records core added to that listener's DataHandler run.
   Under the scope of the task's assignment such a listener write is held to
   the scope; a version of a translation in a language the scope does not
   admit is no longer made. Discarding the page's draft in
   the Workspaces module leaves such a version of a translation in place; it
   is discarded on its own row, or with :guilabel:`Discard` in the workspace
   preview of the page, which discards every other draft on the page too
   (:ref:`a2a-page-metadata-skill-check`). A tool call that ended
   in an error announces no write, but what it changed before can stand: a
   call the scope of the assignment refused a part of keeps the rest
   (:ref:`ADR-295 <adr-295>`). Such records are listed in a further block,
   "Records written by tool calls that did not complete", to be checked, and
   a line says when that list may be incomplete; a call that was rolled back
   lists nothing.
   The one exception to "drafts only" is ``adopt_client_file``, which
   stores a client's file in the skill's inbox folder as a new FAL file. A
   FAL file has no draft; nothing uses it until an editor references it in
   a record of a workspace (see
   :ref:`Taking a client's file into FAL <administration-a2a-server-fal-adoption>`).

Send FAL files to clients
   The recipient approval for ``send_fal_file``: the clients of this skill
   may receive files from FAL that their technical user may read (see
   :ref:`Files from FAL <administration-a2a-server-fal-export>`). Off by
   default; off, the tool refuses every file. It is read at each call, so
   clearing it while a run is under way stops the next file.

Inbox folder for client files
   The one folder ``adopt_client_file`` stores a client's file in, as a
   combined identifier such as ``2:/a2a-inbox/`` (see
   :ref:`Taking a client's file into FAL <administration-a2a-server-fal-adoption>`).
   The folder must not be public: a folder of a storage marked public, of a
   ``Local`` storage whose base path lies in the web root, or of a storage
   that does not exist is not stored. Empty, the default: no file is taken
   into FAL.

Active task quota
   How many tasks of this skill, from all clients together, may be queued or
   running at once. ``0``: no limit of its own.

Public
   Listed on the card and callable. A profile that is not public cannot be
   called by any client.

Input modes
   The media types a client may send to the skill: ``text/plain`` and, while
   files are switched on (see :ref:`administration-a2a-server-files`),
   ``application/json``, ``application/pdf``, ``image/png`` and
   ``image/jpeg``. While files are off the card lists ``text/plain`` for
   every skill, whatever is ticked here.

Results are ``text/plain``, and the files a skill sends from FAL. While files
are on, a skill that lists ``send_fal_file`` and approves its clients as
recipients lists the five file types among its output modes on the card.

Register a client
=================

Create an :guilabel:`A2A Client` record (administrators only):

Technical backend user
   Every task of the client runs as this backend user, with exactly its
   permissions. It must be enabled and must not be an administrator; the
   record cannot be enabled otherwise, and a request is refused if the user
   changed since. Prepare a user for this purpose.

Workspace
   The workspace the client's runs act in; ``0`` is live. A client that may
   call a writing skill needs a draft workspace.

Skills it may call
   The publication profiles the client may use. A client allowed one skill
   needs to name none; a client allowed several names the skill in each
   message, as ``SendMessageRequest.metadata`` member
   ``https://nr-llm.netresearch.de/a2a/ext/profile/v1`` with the skill id as
   value. Otherwise the call is refused with ``PROFILE_REQUIRED``.

Task limit
   How many of the client's tasks may be queued or running at once.

Enabled, expires
   A disabled or expired client is refused like an unknown one.

Then issue its token:

..  code-block:: bash

    vendor/bin/typo3 nrllm:a2a:client:token <client uid>

The command prints the token once. The record keeps only its public prefix
and its SHA-256; nobody can read the token from the database. Running the
command again rotates the token: the old one stops working at once, and the
client keeps its tasks, which belong to the record, not to the token. The
client sends the token as ``Authorization: Bearer <token>``.

The command does not change the record's :guilabel:`Enabled` flag. For a
disabled record, a revoked one included, it prints the token and a warning
on stderr: the token is refused until the record is enabled again. The
backend module refuses a token for a disabled record unless the
administrator enables the record with it.

In the backend the module :guilabel:`AI > A2A tokens` does the same without a
shell: :guilabel:`Generate token` and :guilabel:`Rotate token` per record, and
:guilabel:`Revoke`. Editors can also issue tokens that act as their own
account, within limits that you set per backend group; see
:ref:`administration-a2a-tokens`.

What a client gets
==================

``SendMessage``
   Creates a task and a queued run. The request never executes the run.
   Without ``returnImmediately``, the request waits until the task is
   finished, failed, cancelled, rejected or waiting for input, at most
   ``a2a.server.blockingWaitSeconds`` (default 20, at most 100). After that
   it answers HTTP 504 with JSON-RPC ``-32603``, reason
   ``BLOCKING_DEADLINE_EXCEEDED`` and the task id; the task goes on and is
   read with ``GetTask``. A repeated ``messageId`` of the same client returns
   the task the first one created.

Answering a task (``SendMessage`` with ``taskId``)
   A task whose run asks a question is ``INPUT_REQUIRED``; its status
   message holds the question and, where the question offers a choice, the
   answers it accepts. The client answers with a message that carries the
   task's ``taskId`` (and its ``contextId``, or none: the task's is used).
   The text is the answer to a question with one free or chosen answer; a
   question with several values takes a JSON object. The answer is checked
   like one from the backend (:ref:`ADR-281 <adr-281>`): it must fit the
   question, and the client's technical user must be allowed to run the
   tool that asked. It is then stored, the run is handed to the worker and
   the task is ``WORKING``; nothing runs in the request. A task that does
   not wait for the client, or has ended, takes no message (``-32004``); a
   finished task is never reopened. Only the client's own task can be
   answered, in its own context and its own skill. Before the run goes on,
   and at every later resume, the client record, its technical user, its
   workspace and the skill are checked again; a client disabled or expired,
   a skill withdrawn from it or no longer public, a changed technical user or
   workspace, or a tool the run was given that the skill no longer grants
   (removed from it, or a writing tool after :guilabel:`May write` was
   cleared) fails the task. A repeated ``messageId`` returns the task.

   ``AUTH_REQUIRED`` is never set: no run of this installation waits for a
   credential that a client supplies out of band.

``SendStreamingMessage``, ``SubscribeToTask``
   Answer with Server-Sent Events (``text/event-stream``) while
   :guilabel:`Answer A2A streams` (``a2a.server.streaming``, on by default)
   is on; off, the card declares no ``streaming`` and both are refused with
   ``-32004``. ``SendStreamingMessage`` accepts the message like
   ``SendMessage`` without waiting, takes no waiting slot, and streams the
   task it created (or the message the skill answered with). Each event is one
   ``data:`` line holding a JSON-RPC response with the request's id. The
   first event is the task as ``GetTask`` returns it; after it come only
   changes that were stored later, in order: a ``statusUpdate`` per state,
   and an ``artifactUpdate`` per artifact, always whole (``lastChunk`` true),
   just before the status that announces it. No part of an answer leaves
   before the run has finished and its output checks have passed: a stream
   carries status progress until then, for every skill.

   A stream closes after a terminal state or ``INPUT_REQUIRED``, and after
   :guilabel:`Longest A2A stream` (``a2a.server.streamMaxSeconds``, default
   300); a comment line keeps it alive at least every 15 seconds. Closing a
   stream, or a client that goes away, never cancels the task. A client
   whose record is disabled, expires or loses its token while it reads is
   cut off within 10 seconds. ``SubscribeToTask`` on a finished task is
   ``-32004``; recovery after a lost stream is ``GetTask`` or a new
   subscription, and ``Last-Event-ID`` is ignored.

``GetTask``, ``ListTasks``, ``CancelTask``
   Only the client's own tasks. Another client's task and a task that does
   not exist are the same ``-32001``. ``ListTasks`` sorts by status time,
   newest first, pages with an opaque token bound to the client, its skills
   and the filter, and leaves out artifacts unless ``includeArtifacts`` is
   true.

Task states
   ``SUBMITTED`` while queued, ``WORKING`` while running (also while the run
   waits for an approval in the backend or for a remote agent, and after the
   client answered), ``INPUT_REQUIRED`` while the run waits for the client's
   answer, ``COMPLETED``
   with the answer, ``FAILED`` with a fixed message that names no internal
   detail, ``CANCELED``, and ``REJECTED`` when the input was refused before
   the run started. While the run waits for an approval, a ``WORKING`` task
   carries the status message ``The task is waiting for an approval in
   TYPO3.``; while it waits for a remote agent, ``The task is waiting for a
   remote agent.`` Neither names the approval, the agent or anything else of
   this installation, and both are gone once the run goes on
   (:ref:`ADR-310 <adr-310>`). ``GetTask``, ``ListTasks``, the stream and push
   carry the change like any other. The result is kept at the run's answer
   level and purged with the run (:ref:`ADR-282 <adr-282>`).

Push notifications
==================

Push is off by default. Switched on with :guilabel:`Send A2A push notifications`
(``a2a.server.push``), the agent card declares ``pushNotifications`` and a
client can register webhooks for its own tasks, with
``CreateTaskPushNotificationConfig`` or embedded in ``SendMessage``
(:ref:`ADR-285 <adr-285>`). Another client's task is not found.

- **Targets.** A webhook must be an https URL and pass the address guard when
  it is registered and again before every delivery: public addresses only, no
  host of this installation, and the host lists ``a2a.push.allowedHosts`` and
  ``a2a.push.deniedHosts``, which are separate from those of the remote
  agents. Through an HTTP proxy only with ``a2a.push.allowViaProxy`` and an
  allowlist.
- **Credentials.** The one supported scheme is a Bearer credential in
  ``authentication``; it is sent as ``Authorization: Bearer``. The optional
  ``token`` is stored and returned but never sent. Both are stored sealed,
  like the run state; ``Get`` and ``List`` answer the credential masked. A
  secret a client puts into the URL itself, in its query string, is not a
  credential: it is stored and shown like the rest of the URL.
- **No update.** A ``Create`` with the id of an existing config of the same
  task replaces it. ``Delete`` is idempotent. A task has at most
  ``a2a.server.maxPushConfigsPerTask`` (default 4) configs, a client
  ``a2a.server.maxPushConfigsPerClient`` (default 100).
- **Delivery.** Every stored change of a task writes one delivery job per
  config in the same transaction. ``nrllm:a2a:poll`` delivers them; schedule
  it at least every minute while push is on. No request ever calls a
  webhook. A delivery is an HTTP POST of ``{"task": …}`` with
  ``Content-Type: application/a2a+json``: the task as it is when it is sent,
  without history, so an older job not yet sent is superseded by the newest.
  The timeout is 15 seconds.
- **One pass, bounded.** A run of ``nrllm:a2a:poll`` claims no further
  delivery once 30 seconds have passed since its deliveries started, so it
  lasts at most that plus one attempt. It takes at most 10 deliveries per
  client, and the clients take turns: a client whose webhooks answer slowly
  does not hold back the deliveries of the others. What is left goes out in
  the next run.
- **Outcomes.** 2xx acknowledges. A timeout, a broken connection, 429, 502,
  503, 504 and a redirect are retried after 5, 10, 20 … seconds, at most
  eight attempts and within a day; then the job is abandoned and logged. The
  backoff and the attempts belong to the config, not to one change: a new
  change of the task while a delivery waits to be retried waits for the same
  backoff and continues the count. A change written while an attempt is on
  its way is normally not sent before that attempt ends. When the attempt
  succeeds, that change goes out at once with all eight attempts; when the
  eighth attempt fails, it is abandoned with it, and the next change starts
  over. An attempt counts once it is started, also when the worker fails
  before it records the answer. 401 and 403 pause the config
  (``auth_failed``); 404, 410 and a refused address
  stop it (``stopped``). A paused or stopped config sends nothing more; the
  client registers a new one. Any other answer fails that one delivery.
- **After a delete** no new attempt starts. A request already on its way may
  still arrive.
- **Revoked clients and new tokens.** Revoking a client, or issuing it a
  new token, stops its configs: a config belongs to the token it was created
  with. Before every attempt the sender checks again that the client still
  holds that token, is enabled and not expired, and that its technical
  backend user is usable; otherwise the config stops.
- **Switching push off** stops every delivery at once and writes no new
  jobs; the methods answer ``-32003``. Jobs already written wait and go out
  if push is switched on again within a day. ``nrllm:a2a:poll`` still
  abandons the jobs past that day and removes finished jobs after a week
  while push is off.
- **In the backend** the configs are records of the type
  :guilabel:`A2A Push Config` on the root page, read-only, with their state
  and the reason of the last refusal; the credential is not shown.

..  _administration-a2a-server-files:

Files
=====

Off by default. With :guilabel:`Accept A2A files (server role)`
(``a2a.files.enabled``) on, a client message may carry file parts besides its
text (:ref:`ADR-284 <adr-284>`). Off, every part other than ``text/plain``
text is refused with ``-32005``, as before. Switched off while a task with
files waits for the worker, that task fails before its files are fetched
or read.

What is accepted
   A ``raw`` part (the bytes, base64 in the JSON) that names its
   ``mediaType``, one of ``text/plain``, ``application/json``,
   ``application/pdf``, ``image/png`` and ``image/jpeg``, and that the skill
   lists among its :guilabel:`Input modes`. Each file is checked against its
   type before anything is stored: text must be UTF-8 without control
   characters and must not open with HTML, SVG or XML markup; JSON must parse;
   a PDF needs its header and cross-reference trailer, must not be encrypted
   and must not name JavaScript, launch actions, embedded files, rich media
   or XFA forms; an image must carry its type's signature, be at most 4096 x
   4096 pixels and decode with GD (without the GD extension no image is
   accepted). The images of one message may hold at most 16,777,216 pixels
   together, read from their headers before any is decoded (``-32602``).
   The content is checked only after the skill, its input modes and the
   client's limits allow the message. Archives, HTML, SVG and any other
   type, a part without a ``mediaType`` and a ``data`` part are refused with
   ``-32005``. A message that answers a waiting task carries text only.

File URLs
   A ``url`` part with the same ``mediaType`` rules is accepted only from a
   host listed in :guilabel:`Hosts A2A file URLs may come from`
   (``a2a.files.allowedHosts``). Unlike the host list of the A2A client, an
   empty list admits no file URL at all (``-32005``). The URL must be https
   and passes the address guard of :ref:`ADR-202 <adr-202>` when the task is
   accepted (``-32602`` otherwise): no private, loopback, link-local or
   metadata address, no credentials in the URL, no host of this
   installation, and :guilabel:`Hosts A2A file URLs never come from`
   (``a2a.files.deniedHosts``) is checked first. Nothing is fetched in the
   request. The worker fetches the file before the run starts, through the
   same guard again, pinned to the checked addresses, with a plain ``GET``
   that carries no credential of the client or of a remote agent and no
   cookie, following no redirect and reading at most the size limit. The
   fetched bytes pass the same type check and count against the message's
   limit together with its raw files; a fetched image counts against the
   message's pixel budget together with its other images, before it is
   decoded. A refusal (a redirect, a status that
   is not 2xx, the size, the content, the guard) is stored with the file
   and fails the task; a timeout, 408, 429 or 5xx leaves the file pending
   and the run is retried like any transient failure. Once stored, the copy
   is the input: the URL is never fetched again.

Sizes
   :guilabel:`Largest A2A file` (``a2a.maxRawPartBytes``, default 5 MiB, at
   most 8 MiB) bounds the decoded bytes of one file and of all files of one
   message together. Sealed, a file takes about 1.8 times its size in its
   database row, which must fit into one packet of the database
   (``max_allowed_packet``, 16 MiB by default on MariaDB). The base64 text
   is measured before it is decoded. Larger is ``-32602``. While files are
   on, the largest request (``a2a.server.maxRequestBytes``) grows by the
   base64 size of this limit.

Where a file goes
   Into a private store (``tx_nrllm_a2a_file``), not into FAL and not under
   any public path: one row per file with the client, the task, the origin,
   the real size, the checked type, the SHA-256, the check and approval
   state and an expiry, the bytes sealed in the row like a task's history.
   The task's history keeps a reference in place of the bytes (the part's
   ``raw`` is empty, its ``metadata`` under
   ``https://nr-llm.netresearch.de/a2a/ext/file/v1`` names the file id,
   SHA-256 and size). A ``url`` part keeps its URL in the history; in the
   store the URL is sealed like the bytes.

What the run sees
   Before the worker asks the model for the first time it checks the task's
   files against the list recorded when the task was accepted: each must be
   there, of the client, checked, approved, unexpired and unchanged.
   Otherwise the task fails and the model is never asked. The run then
   receives, after the client's text, one message per file, marked as data
   the client sent and not as instructions: text and JSON as text, an image
   as an image for a model that declares vision, and a PDF as a line naming
   it, because a run cannot read a PDF. A large text file goes into the
   prompt whole; set the size limit with the model's context in mind.

Files a task sends
   A file a task sends is one of the five types and passed the same check,
   and is stored in the same private store, with the direction ``out``.
   When the run completes, each file becomes an artifact of its own. Files
   go inline as ``raw`` while they fit together into
   :guilabel:`Largest inline A2A artifact` (``a2a.maxInlineArtifactBytes``,
   default 256 KiB, at most 512 KiB, so that they still fit into one event
   of a stream at the default frame size); every further file goes out as a
   ``url`` to ``<endpoint>/files/<file id>``, below the A2A endpoint the task
   was accepted at, as the site configures it. No artifact names a URL of
   the installation's own storage. The part's ``metadata`` names the file
   id, SHA-256 and size as for an inbound file. A run kept at the
   ``redacted`` level sends no file, because a file cannot be redacted; one
   text artifact says how many files were withheld. The files of FAL are
   the one source of such files (next section).

..  _administration-a2a-server-fal-export:

Files from FAL
   A run puts a file from FAL into its task's result with the tool
   ``send_fal_file`` (group ``files``), naming the file's ``sys_file`` uid.
   It ships disabled. A skill offers it when all three hold: the tool is
   switched on in the tool management, the skill lists it under
   :guilabel:`Tools`, and :guilabel:`Send FAL files to clients` is on. The
   tool changes no record, so a skill without :guilabel:`May write` can
   offer it.

   At each call the tool checks, in this order: the run is a task of the
   server role; files are on; the skill approves its clients as recipients;
   the task keeps its answer at the ``full`` level (below it no file is
   sent, so the tool refuses rather than claim a delivery). Then the file: the client's technical user must reach it through its
   file mounts and file permissions, read for this run
   (:php:`FalStorageGate::isFileAccessible()`). A file outside those mounts,
   a uid that no file carries and a run without a user get the same answer,
   ``Asset not found or not permitted.``, so a run cannot find out which
   files exist. A file the user may read is sent only when it is at most
   :guilabel:`Largest A2A file` large, is of one of the five types (by the
   type FAL recorded for it) and passes that type's check. The model reads
   the file's name, type, size and id, never its content. A second call for
   the same file adds nothing; a retried run does not send a file twice.
   The file goes out as described above: inline or as a download, never as a
   URL of the installation's own storage.

   The file mounts and file permissions are read anew for every execution
   of a run, also by a long-running worker (``messenger:consume``): a mount
   taken away from the technical user stops the next run from sending a
   file in it. Which storages a user reaches at all comes from TYPO3, which
   keeps the mount records it read for the life of the worker process: per
   user and workspace in TYPO3 14.3.11, one entry for all users in 13.4.30
   and 14.3.0. Each file is still checked against the mounts read for the
   run. Restart the worker after giving a technical user its first mount in
   a storage.

..  _administration-a2a-server-fal-adoption:

Taking a client's file into FAL
   A run stores a file the client sent with the task in FAL with the tool
   ``adopt_client_file`` (group ``files``), naming the file by its number,
   the N of the line "File N of M from the client" the run received
   (:ref:`ADR-305 <adr-305>`). It ships disabled. A skill offers it when the
   tool is switched on in the tool management, the skill lists it under
   :guilabel:`Tools`, :guilabel:`May write` is on (the tool writes, so the
   calling client is pinned to a draft workspace) and
   :guilabel:`Inbox folder for client files` names a folder.

   - **Where:** only into that folder. Neither the client nor the model
     chooses a folder or a storage. The folder must exist and must not be
     public: not in a storage marked public, not in a ``Local`` storage with
     a relative base path or with an absolute one inside the web root (as
     written or through a link), not linked into the web root itself, and not
     a processing folder (the storage's own, or one any storage assigns
     into it) or a ``_recycler_`` folder. Repeated slashes and ``.``
     segments are dropped before the check, and the folder is stored in
     that form. Give the inbox its own
     ``Local`` storage with an absolute base path outside the web root. The
     tool asks this again at each call, so a storage made public later stops
     the next adoption.
   - **Who writes:** the client's technical backend user, with its own file
     mounts and file permissions. It needs a file mount on the folder that is
     not read-only, and the permissions to read and write folders and to add
     files.
     The approving editor's rights do not count, also not an
     administrator's. The storage list the FAL tools may read from
     (storage 1 by default) does not limit the inbox.
   - **Approval:** every call waits for the approval of a person of this
     installation, in the approvals inbox, as every writing tool does; the
     task stays ``WORKING`` meanwhile. A call that cannot be written is
     refused before an approval is asked: no inbox folder, a public folder,
     no such file, a file the task no longer holds, a technical user who may
     not add a file to the folder, or files switched off. The card shows the
     client's file name, the name it will be stored as, its type, size and
     the start of its SHA-256 digest, and the inbox folder, to an
     administrator and to an editor whose file mounts reach the inbox folder
     the card names; anyone else sees that the preview is withheld. If any of that
     reads differently when the approved call runs (the folder was changed,
     the name was taken, the user lost the right), the file is not stored and
     the run asks for a new approval.
   - **Names:** the file keeps the name the client gave it, without any path
     and without Unicode format characters, with the extension of its
     checked type (``.txt``, ``.json``, ``.pdf``, ``.png``, ``.jpg``). A name
     already taken in the folder is kept: the new file gets another one
     (``report_01.txt``), and the existing file and its record stay as they
     are. The approved call writes into the folder and under the name the
     card showed and nowhere else: if two files of one name are adopted in
     one turn, or the skill's inbox folder changes after the approval, the
     call writes nothing and the file has to be adopted again with a new
     card. Two approved adoptions of the same name, carried out by two
     workers at the same instant, are not guarded against: core's Local
     driver could then replace the first file. If TYPO3 fails after it wrote the file, the answer says that a file
     of that name may remain in the inbox; it is not deleted.
   - **Effect:** nothing references the stored file. It takes effect only
     when an editor uses it in a record of a workspace, which is published
     like any other change. The ``writes`` artifact of the task names the new
     ``sys_file`` record in workspace 0: a FAL file has no draft.
   - **When:** a task's files are held until the task ends or the file
     expires (:guilabel:`Hours an A2A file is kept`), whichever comes first. An
     approval given later stores nothing, and the tool says that the file is
     no longer held.

   The cleanup of expired A2A files never deletes a file taken into FAL.

Downloading a file
   ``GET <endpoint>/files/<file id>`` with the client's bearer token returns
   the bytes with their type, ``Content-Disposition: attachment``,
   ``X-Content-Type-Options: nosniff`` and ``Cache-Control: no-store``. A
   file of another client, an unknown file, an expired one and one the
   task's artifacts no longer name all answer the same ``404``; a file is
   served only while the artifacts, read at the run's current answer level,
   still carry it, so a tightened privacy level withdraws the download too.
   ``POST <endpoint>/files/<file id>/capability`` with the bearer token
   returns ``{"url": ..., "expiresAt": ...}``: a URL that reads this one
   file of this one client without the bearer token for 15 minutes.

   ..  warning::

       A capability URL is a transferable bearer right: whoever holds it
       reads the file until it expires, without any other credential. It
       stops working when it expires, when the client gets a new token and
       when the client is disabled. Hand it on only where the file may go.
       The capability sits in the query string, so it can end up in the
       access log of a proxy or web server in front of the endpoint; keep
       those logs as you would keep the file.

   A wrong, expired or revoked capability answers ``401`` and is logged and
   counted per source address together with failed bearer tokens, under the
   same limit (``nrllm-a2a-auth``, 10 failures per 15 minutes); over it the
   answer is ``429``.

   Asking for a capability again issues a new URL and changes nothing of
   the task: its status, the artifact ids, the digest and the content stay
   as they are, and no event is written. With
   :guilabel:`Accept A2A files (server role)` off, the download paths are
   not answered.

Cleanup
   When a task ends (completed, failed, canceled or refused), its input
   files keep their record (id, size, SHA-256, check and approval state)
   but lose their bytes and URL in the same transaction, whatever the
   answer level: nothing reads them after a final state. A ``url`` part
   not fetched yet is refused (``task ended``), so a fetch still running
   stores nothing. The files a task
   sends stay until they expire, because they are its result. A task's
   files go with the run purge, like its history.
   ``nrllm:privacy:purge`` also deletes every file older than
   :guilabel:`Hours an A2A file is kept` (``a2a.files.retentionHours``,
   default 168); a queued task whose file is gone fails rather than running
   without it. Cleanup deletes rows of the store only: a file sent from FAL
   is a copy in the store, and the FAL file itself stays.

Limits and protection
=====================

- **Proxy timeouts.** A waiting request holds a PHP-FPM worker. Keep
  ``a2a.server.blockingWaitSeconds`` below the read timeout of every proxy in
  front of PHP-FPM; a proxy that cuts the request earlier leaves the client
  with a transport error and the task unchanged.
- **Waiting requests.** At most ``a2a.server.maxWaitingRequests`` (default 4)
  blocking requests wait at the same time on the installation; one more is
  refused with HTTP 503, ``WAITING_CAPACITY_EXHAUSTED``. A request with
  ``returnImmediately`` takes no waiting slot.
- **Streams.** A stream holds a PHP-FPM worker while it is open. At most
  ``a2a.server.maxStreamsPerTask`` (default 2) streams read one task,
  ``a2a.server.maxStreamsPerClient`` (default 4) belong to one client, and
  ``a2a.server.maxStreams`` (default 8) are open on the installation; one
  more is refused with HTTP 503, ``STREAM_CAPACITY_EXHAUSTED`` and
  ``Retry-After``, before anything is stored. A stream that dies without
  closing frees its slot within 30 seconds. An event larger than
  ``a2a.server.maxFrameBytes`` (default 1 MiB) is not sent; the stream ends
  and the client reads the task with ``GetTask``. The first event always
  names the task: a task too large for one frame is sent without its history
  and artifacts. An answer to a waiting task sent as a stream counts against
  that task's streams before it is taken in, so a refused answer is not
  stored either.
- **Proxies in front of a stream.** The response carries
  ``X-Accel-Buffering: no`` and ``Cache-Control: no-cache``, and pads each
  write to 4 KiB with a comment line, so a proxy that holds small chunks
  passes every event on when it is written; nginx in front of PHP-FPM with
  its FastCGI buffering on is tested this way in CI. Apache is not. A proxy
  whose read timeout is
  below 15 seconds cuts a quiet stream; one that buffers whole responses
  regardless of these headers delivers the events only when the stream
  closes. Check the proxy chain of the installation with a
  ``SubscribeToTask`` before relying on streams.
- **Request size.** A body above ``a2a.server.maxRequestBytes`` (default
  1 MiB, plus the base64 size of ``a2a.maxRawPartBytes`` while files are on)
  is refused with HTTP 413 before it is parsed; a message has at most
  16 parts. A task's history keeps the most recent 100 messages.
- **Failed logins.** A failed authentication is logged with the source
  address, never with the token, and counted per address: after 10 failures
  in 15 minutes a failed request from that address gets HTTP 429 until the
  window passes. A request with a valid token is not limited. The rate
  limiter is ``nrllm-a2a-auth``; override it like TYPO3's own limiters in
  ``$GLOBALS['TYPO3_CONF_VARS']['SYS']['rateLimiter']['nrllm-a2a-auth']``.
  Behind a reverse proxy, name it in
  ``$GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyIP']``; otherwise every
  client counts as the proxy's address and shares one limit.
- **Refusals** name their reason (``error.data`` holds a
  ``google.rpc.ErrorInfo``) and, where a retry may help, a ``Retry-After``
  header.
