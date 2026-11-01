.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-240:

====================================================================
ADR-240: The text of a tool round is streamed
====================================================================

:Status: Accepted
:Date: 2026-11-01
:Amends: :ref:`ADR-062 <adr-062>` (a tool call can stream too)
:Authors: Netresearch DTT GmbH

Context
=======

A model that may call tools answers through
:php:`LlmServiceManager::chatWithToolsForConfiguration()`, and that call is
blocking: a reader sees nothing until the round is over. Over 224 calls on the
demo the time to the first token was recorded for none. The streaming path of
:ref:`ADR-062 <adr-062>` is no help. It runs a generator of text outside the
middleware pipeline, knows no tool calls, reports estimated tokens, and on OpenAI
it only reaches Chat Completions, where a GPT-6 model takes no tools with its
reasoning on (:ref:`ADR-203 <adr-203>`).

Decision
========

**The call stays blocking; the text leaves earlier.** A new contract,
:php:`ToolStreamingCapableInterface`, has one method beside
:php:`chatCompletionWithTools()`: the same arguments and a closure for the text,
returning the same :php:`CompletionResponse`. So the pipeline stays what it is.
Budget, fallback, usage with real token counts, telemetry and the output
guardrails all run on the complete response, and nothing downstream can tell the
two calls apart. On OpenAI's Responses transport, the event that ends the stream
carries the `response` object of the blocking call, and one parser reads both.

**A caller asks by passing an observer.** :php:`ToolOptions::withTextSink()` lives
for one call and is not part of :php:`toArray()`, so it is not stored with a
suspended run. :php:`ToolLoopService` sets it when the trace has a receiver, and
:php:`AgentRunExecutor` gives the trace one when the caller of
:php:`AgentRuntimeInterface::run()`, :php:`approve()` or :php:`submitInput()`
passes `onStep`. The observer then also receives steps of the kinds
:php:`RunStep::KIND_TEXT_DELTA` and :php:`RunStep::KIND_TEXT_RESTART`. They are no
records: they are not collected and not persisted, and
:php:`RunStep::kinds()` does not list them. The llm step keeps the round's text.

**Text passes the guardrails on the way.** :php:`StreamingDispatcher::toolTextRelay()`
decides per call. With a guardrail that holds a stream back
(:ref:`ADR-238 <adr-238>`, for example a term list on this configuration) the
round is not streamed at all: the caller gets the text after the verdict. Without
one, the text streams through the live redaction of
:ref:`ADR-088 <adr-088>`, with a smaller rescan block than a plain stream, because
a reader is waiting for it. Its window holds a run of text without whitespace back
until the run ends, so a bearer token quoted by the model is masked whole. The
output guardrails then screen the complete
response as always; one that refuses it fails the call after the text was seen,
as the end-of-stream audit of :ref:`ADR-086 <adr-086>` does for a plain stream.

**A second attempt says so.** The fallback chain, or a guardrail's retry, can send
a call twice in one pipeline run, blocking or streaming. The relay tells the sink
to drop what it holds before the second attempt, and the requested configuration
decides about the whole run, because its guardrails screen the response whatever
configuration of the chain serves it.

**Failures stay on the known path.** A connection or a secret that fails before the
stream has opened goes blocking, with the retries of :php:`sendRequest()`, as does
a provider that answers the request for a stream with an error, and so
does an overload the provider reports inside the stream before the first text
(`rate_limit_exceeded`, `server_error`, which arrive after HTTP 200 and would
otherwise be nothing the pipeline retries). A timeout is not sent again, as on the
blocking path. Anything after the stream opened stands, and the pipeline's fallback
restarts the round as above.

**The time to the first text is telemetry.** The relay measures it and it lands in
`time_to_first_token_ms`, which the Verlauf of a run shows as `ttftMs`.

Consequences
============

- ● The first text of a chat answer no longer waits for the end of the round.
- ● Usage on this path is what the provider reports, unlike the estimates of a
  plain stream.
- ◑ Only OpenAI's Responses transport implements the contract. Chat Completions
  and every other provider send blocking, as before; a provider joins by
  implementing the interface.
- ◑ The redaction window holds back 128 bytes, so the text a reader sees trails
  the model by that much, and an answer shorter than that arrives with the end of
  the round. Text without spaces, such as Japanese, arrives in larger pieces, line
  by line or every 4 KB, because a run without whitespace waits.
- ◑ A round whose answer a non-holding guardrail then refuses has been read. A
  guardrail that must prevent that holds the stream.
- ◑ A cache hit delivers no pieces; the text comes with the response.
- ◑ `openai-processing-ms` says nothing on a stream, because the header leaves
  before the model has written; a streamed round has no provider processing time.
