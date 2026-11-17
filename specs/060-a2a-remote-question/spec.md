<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# A delegating run answers its remote agent's question

Governing decision: ADR-311 (amends ADR-281). A run that delegated a step to a
remote A2A agent waits for the remote task. When that task stopped in
`TASK_STATE_INPUT_REQUIRED`, the run went on with the question as the tool's
result (spec 051 A2A-R11): the model read it, and nothing could answer the
remote task, which stayed open on the remote side. This lets the person who
started the run answer, and sends that answer to exactly that remote task.

## Requirements

- **RQ1 The question becomes a pause of the run.** When the poller sees the
  remote task in `INPUT_REQUIRED`, the run is claimed like a dependency resume
  and suspended in `waiting_for_input`: the delegation call is the pending
  call and the input target, the input is one required string `answer` (1 to
  4000 characters, not only whitespace) whose title is the remote's status
  text marked as the remote agent's, with a marker of the question, so the
  turn digest of an earlier question with the same text does not match, and the dependency checkpoint stays beside it in the sealed
  state. No model call is made. The answer's limits (not only whitespace, at
  most 4000 characters) are checked by the coordinator before anything is
  claimed or stored, since the input path validates only types and required
  fields. A question read for one wait is not asked once that wait was
  answered and began again before the claim; the claim goes back. The delegation tool treats only a terminal
  task as its result; an interrupted one waits, so the poller is the one
  place that converts.
- **RQ2 The binding is durable and local.** The remote task id, context id,
  agent and owner come from the checkpoint and the original journal row only,
  never from the remote's later answers or from the person answering. The
  binding is kept in the sealed `suspended_state`, by every rewrite of the
  pause (the release after a refused answer included), and survives a restart
  of every process. A rewrite that cannot read the run again fails it rather
  than write an ordinary input pause. An answer is bound to the pause its checks read: when the
  run was answered and asked again before the answer's claim, even with the
  same question, the answer is refused and the run released unchanged.
- **RQ3 Who answers.** Only the run's initiator (`AiActorContext::isInitiatorOf()`:
  the backend user who started the run, not a service account). An
  administrator or a holder of the approval grant who is not the initiator is
  refused, in the request that takes the answer (`submitInput`, `storeInput`)
  and again in the worker that carries a stored answer out. Without an
  answerer the pause is refused before it is claimed, so it is never resumed
  as an ordinary input. The remote side never answers: a push callback, a
  remote status or a remote message decides nothing about the answer.
- **RQ4 Where the answer goes.** As `SendMessage` with the bound `taskId` and
  `contextId`, through the send journal as a new intent of the same owner that
  names the first one (`continues_send_uid`), the text passed through the
  shared secret redactor. Its message id is a UUIDv5 of the run, the
  checkpoint and the question's marker, never of the claim, so a repeated
  execution of the same stored answer gets the existing intent back and sends
  nothing, and another text under that id is not sent (outcome unknown); an existing accepted intent is read
  with GetTask, an intent found `intended` or `outcome_unknown` is never sent
  again. The pending call is never executed again.
- **RQ4a Only the endpoint that asked.** When the question becomes a pause,
  the checkpoint is bound to a digest of the record's stored card (card URL,
  interface URL, tenant, confirmed interface) of the record object whose
  `GetTask` saw the question. The answer is sent only while
  the stored card still has that digest and the record object the client
  sends with matches the stored row (`A2aRemoteAgentCard`, shared with the
  delegation tool); otherwise nothing is sent and the run goes on with an
  error result.
- **RQ5 The remote must answer about the same task.** A task, or a message
  naming a task, with another id in the response settles the answer's intent `outcome_unknown`
  (`INVALID_AGENT_RESPONSE`) and the run goes on with an error result; nothing
  is read of the other task.
- **RQ6 After the answer, and the deadline.** A terminal remote task answers
  the pending call as a dependency resume does. Any other state puts the run
  back into `waiting_for_dependency` on the same checkpoint (also when the
  answer was accepted but the task could not be read right after it), with a fresh
  deadline of the record's maximum wait from the send, and push is registered
  again when it is on; a further question becomes a further pause. While
  nobody answers, the delegation's deadline holds: the poller resumes the run
  with the timeout result and cancels the remote task once. It finds such runs
  through the journal, paged, so older pauses of any kind cannot keep a
  question from its deadline. A deadline read for one question does not
  expire a question that replaced it before the claim.
- **RQ7 Counting.** An answer is not a delegation: the record's delegations
  per run count first sends only. Its bytes count towards the run's total.
- **RQ8 AUTH_REQUIRED sends nothing.** A remote task in
  `TASK_STATE_AUTH_REQUIRED` is not a question for anyone here. The run keeps
  waiting, the poller keeps polling (GetTask only) until the task moves on or
  the deadline passes, and the timeout result says that the remote asked for
  authentication, which this installation does not give. No credential, token
  or message is sent in reply; the record's own credential travels as on every
  request.
- **RQ9 A question left open is cancelled.** A remote task whose last state
  is `INPUT_REQUIRED` and whose run ended any other way (cancelled, failed,
  gone) is cancelled once, by the next poller tick.
- **RQ10 Where the initiator answers.** In the backend, the run is a waiting
  run with an input form, like any input pause. A run started by an A2A client
  of this installation shows the question as `INPUT_REQUIRED`, and the client
  answers with a message to its task (A2A-MT1); see the limits.

## Out of scope

- Answering AUTH_REQUIRED, or passing any credential to a remote agent.
- Approving the answer again: it is the initiator's own text, not the model's.
- Streaming the remote task (the delegation polls, A2A-SSE6).
- Remote questions in a structured form (a data part, a schema of the
  remote): the question is the remote's status text, the answer one text part.

## Limits

- The delegation tools are admin-only, and an A2A client's technical user is
  usually not an administrator, so a run started by an A2A client usually
  cannot delegate. The A2A half of RQ10 follows from the existing input path
  and is not covered by a test of its own.
- The cancel of RQ9 waits for the next poller tick.
- An answer given in a backend request is sent in that request, and nr-vault
  reads the record's credential as the initiator. Where only the worker's
  technical user may read it, the send fails and the run goes on with that
  error; an answer stored for the worker is sent in its vault scope.
- For a run started by an A2A client, the question shown to that client names
  the remote agent record's title.
- A remote task that stays in AUTH_REQUIRED holds the run until the deadline;
  the initiator learns of it from the timeout result.

## Verification

All in functional `A2aDelegationTest`, each requirement also shown failing by a
mutation of the behaviour it covers.

| Requirement | Evidence |
| --- | --- |
| RQ1, RQ2, RQ4, RQ6, RQ7 | `theInitiatorsAnswerGoesToTheSameRemoteTask` (scripted transport: INPUT_REQUIRED, the answer with the same task id, COMPLETED) |
| RQ2 | `aStoredAnswerIsSentByAFreshWorkerToTheBoundTask`, `aRefusedAnswerKeepsTheBindingAndTwoQuestionsGetTwoAnswers` |
| RQ3 | `anotherUserCannotAnswerEvenAsAdministrator`, `anAnswerToAQuestionThatWasReplacedIsRefused` (the pause changes between the checks and the claim) |
| RQ4 | `aRepeatedExecutionSendsTheAnswerOnce`, `aRefusedAnswerKeepsTheBindingAndTwoQuestionsGetTwoAnswers` |
| RQ4a | `anAnswerIsNotSentToAnEndpointOtherThanTheOneThatAsked` |
| RQ4 (repeated execution of a stored answer) | `aReplayOfAStoredAnswerDoesNotSendItTwice` |
| RQ5 | `anAnswerAboutAnotherTaskIsNotFollowed`, `aMessageReplyAboutAnotherTaskIsNotFollowed` |
| RQ1, RQ6 (stale reads) | `aStaleQuestionIsNotAskedAgainAfterItWasAnswered`, `aDeadlineDoesNotExpireTheQuestionThatReplacedIt`, `aFailedReadAfterTheDeadlineClaimDoesNotExpireTheReplacement`, `aStaleRemoteStateDoesNotTakeAQuestionOffItsDeadline`, `anAcceptedAnswerWhoseTaskCannotBeReadKeepsTheWait` |
| RQ6 | `anUnansweredQuestionTimesOutAtTheDeadline`, `aQuestionBehindOlderPausesStillTimesOut` |
| RQ1, RQ2 (old form, blank answer, failed release) | `anOldFormDoesNotAnswerARepeatedQuestionAndBlankAnswersAreRefused`, `aReleaseThatCannotReadTheRunFailsClosed` |
| RQ7 | `anAnswerIsNotADelegation` |
| RQ8 | `authRequiredSendsNothingButGetTask` |
| RQ9 | `cancellingTheQuestionCancelsTheRemoteTask` |
| RQ10 | `theInitiatorsAnswerGoesToTheSameRemoteTask` (backend input path) |
