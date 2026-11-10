<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# A continuation streams its progress

Governing decision: ADR-276. Issue: #1286.

An approval or an answered question in the Agent Runs inbox, and the
playground's resume and submit-input endpoints, continue the run inside the
web request and answer only when the continuation has ended. nginx
(`fastcgi_read_timeout`) and Caddy (`read_timeout`) count between two reads,
so a response that sends nothing for longer than the timeout is cut off. On
the demo a round took 7.5 s at the p90 and 25 s at most; at the p90, 16
rounds fill 120 s, and the ceiling allows 20.

This feature keeps the continuation in the request and sends a line per
recorded step, so no read waits longer than one step. The request is still
as long as the continuation; what changes is that it is never silent for
longer than a step.

## Why not the worker

The issue offers two ways out: stream, or hand the decision to the queue
worker as nr_mcp_agent's chat does. The worker was measured against three
facts and rejected:

- The playground shows the full trace and the answer. At the default
  privacy level `metadata`, `RunStepPrivacyFilter` strips every content
  field from the stored steps, and `QueuedRunCoordinator::keepAnswer()`
  keeps no answer for a run that went through an approval. A run continued
  in a worker could not show the playground what it did.
- An installation that works the queue with `nrllm:agent:work` alone has no
  worker heartbeat (ADR-234), so a request cannot tell whether a decision it
  queues will ever be carried out. A decision that waits for a worker that
  does not run is worse than today, where PHP finishes the run even when
  the proxy has already answered 504.
- A recorded but unexecuted decision needs a state of its own on the run,
  which `AgentRunStatus` does not have; nr_mcp_agent reads that enum.

## Requirements

- **R1 Inbox progress.** With JavaScript, the approval form and the input
  form submit by `fetch` with `Accept: application/x-ndjson`. The action
  continues the run in that request. From the first recorded step on, the
  response is NDJSON: one `{"event":"progress","steps":n}` line per step
  that is not a text delta, a text delta at most every 10 seconds, and a
  final `{"event":"settled"}` line. The client then loads the list, where
  the outcome is the flash message the no-JS path shows.
- **R2 No content in the inbox stream.** A progress line carries the event
  name and a step count, nothing else. A holder of the approval grant may
  decide a run whose detail page ADR-153 withholds from them; the stream
  must not tell them more than the detail page would.
- **R3 Refusals keep their responses.** The stream opens on the first
  recorded step. Every refusal the runtime raises before it executes
  anything — stale turn, approver not permitted, four-eyes, process run,
  inactive configuration, unreadable state, already resuming, not waiting,
  invalid input — answers as it does today: a 303 to the list with a flash
  message, or the 422 re-render of an invalid input. The client follows a
  redirect by loading the list, and answers a 422 by submitting the same
  form natively, which renders the server's 422 page. An invalid input is
  refused before anything is claimed or recorded (ADR-105), so the second
  submission has no side effect.
- **R4 Inbox without JavaScript.** A native form POST continues the run
  synchronously and answers with the redirect, as today. Behind a proxy it
  can still be cut off at the timeout. This is the fallback, and a known
  limit.
- **R5 Playground endpoints.** `resumeAction` and `submitInputAction` accept
  `stream=1` as `runAction` does. Streamed, they emit a `step` event per
  recorded step that is not a text delta, then the terminal event `runAction`'s
  stream emits for the same outcome (`done`, `awaiting_approval`,
  `awaiting_input`, `guardrail_blocked`, `guardrail_approval_required`,
  `cancelled`, `error`). A refusal before
  the first step answers with the JSON body and HTTP status it has today. Without
  `stream=1` both endpoints answer one JSON document, unchanged. The playground
  page itself offers no approval; it sends the operator to the inbox.
- **R6 A closed tab does not stop the run.** A streamed response sets
  `ignore_user_abort(true)` before its first line, so a client that goes
  away cannot abort PHP at the next write, in the middle of an approved
  write. This applies to the existing `runAction` stream too, which shares
  the plumbing.
- **R7 Permissions unchanged.** The decision is authorised against the
  person who decides, as before: run access, the stale-turn digest, the
  approver's own tool permission, the audit record, four-eyes. The tools of
  the continuation run as the run's owner, in the workspace the run
  recorded (ADR-083, ADR-260). Streaming changes neither.
- **R8 Idempotency unchanged.** A double click and a second tab reach the
  same atomic claim: one continues, the other is answered "already being
  processed" or "not waiting any more". The client disables the form's
  buttons while its request runs.
- **R9 Failure states.** A stream cut by the proxy or the browser leaves the
  run running to its end (R6). A client whose stream ended or broke before
  `settled` keeps the page and says, in the card's live region, that the run
  continues on the server, with a link to the list; the outcome's flash
  message appears on the next page load. A continuation that fails settles
  the run as today, and the flash message says so.

## Out of scope

- Continuing a decision in the queue worker (see "Why not the worker").
- A status endpoint polled by the client.
- A single step longer than the proxy timeout. It is cut off as before; the
  longest step measured on the demo took 25 s.
- The backend chat of nr_mcp_agent, which already continues decisions in
  its worker.
- The playground page offering an approval.

## Verification

| Requirement | Evidence |
| --- | --- |
| R1, R2 a line per step, counts only, then settled | `AgentRunControllerTest`: a fake runtime records N steps; the captured stream has N progress lines with only `event` and `steps`, then `settled`; the flash message is queued |
| R1 text deltas are throttled | `ContinuationProgressTest` with a fake clock |
| R3 refusals keep their responses | `AgentRunControllerTest`: a refusal with an NDJSON `Accept` still answers 303 and opens no stream; an invalid input still answers 422 |
| R4 no JavaScript | the existing `AgentRunControllerTest` cases, unchanged |
| R5 playground | `ToolPlaygroundControllerTest`: streamed resume emits a `step` per step and the terminal event; a refusal stays JSON with its status; the batch path is unchanged |
| R6 abort | `ContinuationProgressTest`: opening the stream sets `ignore_user_abort` |
| R7, R8 unchanged | the existing runtime and controller suites |
| R9 | client behaviour in `AgentRunInbox.js`; manual check in the backend |
| Proxy | measured on 2026-11-10 behind Apache `mod_proxy_fcgi` with `ProxyTimeout 8` and a scripted provider at 3 s a round: native POST 504 after 8 s, streamed approval 27 s to its outcome. Open: the demo's nginx and Caddy at the configured ceiling |
| Final checks | cgl, phpstan, rector `-n` (PHP 8.2), changelog check, PHPUnit on the touched classes; GitHub matrix |
