.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-276:

==============================================
ADR-276: A continuation streams its progress
==============================================

:Status: Accepted
:Date: 2026-11-10
:Amends: :ref:`ADR-109 <adr-109>` (its single response path: the inbox's decision
    actions also answer as a stream); :ref:`ADR-084 <adr-084>` (the playground's
    resume route also answers as a stream)
:Authors: Netresearch DTT GmbH

Context
=======

An approval, a denial or a submitted input continues a suspended run. The
Agent Runs inbox (:php:`AgentRunController::approveAction()` and
:php:`submitInputAction()`) and the playground
(:php:`ToolPlaygroundController::resumeAction()` and :php:`submitInputAction()`)
call :php:`AgentRuntimeInterface::approve()` or :php:`submitInput()` inside the
web request, which runs every remaining round before it returns. Neither
response sends a byte before that.

nginx's ``fastcgi_read_timeout`` and Caddy's ``read_timeout`` count the time
between two reads, not the whole response. On the demo, behind both at 120 s,
75 rounds of 24 chat runs took a median of 3.1 s, 7.5 s at the p90 and at most
25 s, from the run events of 2026-11-03/04. At the p90, 16 rounds fill 120 s,
and the ceiling ``agent.maxIterations`` allows 20. A longer continuation gets a
504; PHP finishes the run regardless, because ``max_execution_time`` does not
count network waits and the proxy does not stop PHP-FPM.

Two ways out were on the table: stream the continuation, as ``runAction()``
already streams a run, or record the decision and let the queue worker carry it
out, as the backend chat of nr_mcp_agent does.

Decision
========

**The continuation stays in the request and streams.** Each recorded step sends
a line, so no read of the proxy waits longer than one step.

**The inbox streams on request and sends counts only.** With JavaScript the
two decision forms post by ``fetch`` with ``Accept: application/x-ndjson``. The
action passes a step observer to the runtime; from the first step on, the
response is NDJSON, a ``progress`` line per step carrying the step count, and
``settled`` at the end. A text delta is no step, but it sends a line at most
every 10 s, so a round whose answer streams in keeps the connection alive. A
line carries nothing but the count: a holder of the approval grant may decide a
run whose detail page :ref:`ADR-153 <adr-153>` withholds from them. The outcome
is the flash message the action queues as before; the client loads the list
after ``settled`` and shows it there.

This is the content-negotiated second response path :ref:`ADR-109 <adr-109>`
ruled out. It is still a module route, so the module access and the route token
guard it as before, and the native POST gets the response it always got.

**The stream opens with the first step.** Every refusal the runtime raises —
stale turn, approver not permitted, four-eyes, process run, inactive
configuration, unreadable state, already resuming, not waiting, invalid input —
comes before anything executes, so before the first step, and keeps its 303 or
its 422. The client follows a 303 by loading the list, which still holds the
flash message because ``fetch`` did not follow the redirect. It answers a 422 by
submitting the same form natively, so the server renders its own error page; an
invalid input is refused before anything is claimed or recorded
(:ref:`ADR-105 <adr-105>`), so the second submission has no side effect.

**The playground's resume and submit-input routes stream on request.** With
``stream=1`` they answer as ``runAction()``'s stream does: a ``step`` event per
recorded step, then the terminal event for the outcome. The routes are
admin-only, as is the run's trace. A refusal keeps its JSON body and status.
Without ``stream=1`` they answer one JSON document, as before. The playground
page offers no decision form of its own and sends the operator to the inbox.

**A streamed response keeps PHP running when the client leaves.** Opening the
stream sets ``ignore_user_abort(true)``. Without it PHP stops at the first write
after the client has gone, possibly in the middle of an approved write. A
batch response never wrote before the end and never noticed. ``runAction()``'s
stream shares the transport and gets the same.

**Permissions and idempotency do not change.** The decision is authorised
against the person who decides; the tools run as the run's owner in the
workspace the run recorded (:ref:`ADR-083 <adr-083>`,
:ref:`ADR-260 <adr-260>`). A double click and a second tab meet the atomic claim
and the turn digest as before; the client also disables the form while its
request runs.

Consequences
============

✓ A continuation up to the ceiling is not cut off by a read timeout as long as
each step is shorter than it.

✓ The flash messages, the refusals and the no-JS path are unchanged.

✗ The request still lasts as long as the continuation. It holds a PHP-FPM
worker for that time, as before.

✗ A single step longer than the proxy's read timeout is still cut off. The
longest measured on the demo took 25 s.

✗ Without JavaScript the inbox waits for the whole continuation, as before,
and can end in a 504.

✗ Measured on 2026-11-10 behind Apache ``mod_proxy_fcgi`` only, with
``ProxyTimeout 8`` and a scripted provider taking 3 s a round: a native POST
got a 504 after 8 s, the streamed approval ran 27 s to its end and showed its
outcome. Behind the demo's nginx and Caddy, at the configured ceiling, it has
not been measured yet.

Alternatives considered
=======================

**Hand the decision to the queue worker.** Rejected for three measured reasons.
The playground shows the full trace and the answer, and at the default privacy
level ``metadata`` the stored steps carry no content
(:php:`RunStepPrivacyFilter`) and a run that went through an approval keeps no
answer (:php:`QueuedRunCoordinator::keepAnswer()`), so a worker could not show
the playground what it did. An installation that works the queue with
``nrllm:agent:work`` alone has no worker heartbeat (:ref:`ADR-234 <adr-234>`),
so the request cannot tell whether a recorded decision will ever be carried out;
one that waits for a worker that does not run is worse than a 504 after which
the run finished. And a recorded, unexecuted decision needs a state of its own,
which :php:`AgentRunStatus` does not have and nr_mcp_agent reads.

**A status endpoint the client polls.** It needs the worker, or a request that
outlives its response, which PHP-FPM gives only through
``fastcgi_finish_request()``, unavailable under other SAPIs.

**Stream the inbox's progress as content.** It would show a grant holder what
ADR-153 withholds from them.
