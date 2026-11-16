<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# A working A2A task says what it waits for

Governing decision: ADR-310. A task of the A2A server role whose run waits for
an approval in the backend or for a remote agent is `WORKING`, the state it had
while the run was running (spec 051 A2A-SV10). Until now the client saw nothing
else: `WORKING` without a status message, whether the run was computing or had
stopped for a person who might answer days later. This adds a fixed status
text for those two waits and removes it when the run goes on.

## Requirements

- **WS1 Text.** While the run of a task is `waiting_for_approval`, the task is
  `WORKING` with the status message `The task is waiting for an approval in
  TYPO3.`; while it is `waiting_for_dependency`, `WORKING` with `The task is
  waiting for a remote agent.` One text part, role `ROLE_AGENT`. The text is
  fixed: no run uuid, task id, record, workspace, person, agent title or
  remote task id, and no link into the backend.
- **WS2 End of the wait.** The transition that ends the wait removes the text:
  the resume after an approval, the resume after a dependency, and the queued
  continuation of a stored decision (ADR-281). The task stays `WORKING`
  without a status message.
- **WS3 Only a change is written.** A transition whose state and text equal
  the task's current ones writes nothing: no revision, no event, no push job.
  A change of the text alone is one revision with one event, like a change of
  the state.
- **WS4 The client's question stays separate.** A wait for the client's input
  stays `INPUT_REQUIRED` with its question (A2A-MT1). An approval never becomes
  `INPUT_REQUIRED`, and the client cannot answer it.
- **WS5 GetTask and ListTasks** show the text in `status.message`.
- **WS6 Stream.** `SendStreamingMessage` and `SubscribeToTask` send the change
  as a `statusUpdate` with state `WORKING` and the text, and the end of the
  wait as a `statusUpdate` with state `WORKING` and no message. A
  subscription that starts during the wait gets the text in its snapshot.
- **WS7 Push.** The change is a new revision, so it is pushed like any other
  (A2A-PUSH3), and the pushed task carries the text.

## Out of scope

- Which approval or which remote agent: the text names neither. Showing who
  decides needs a check against the viewer that this change does not make.
- A remote agent's own question to a delegating run (spec 051 A2A-R11): that
  is a separate change.
- A new task state. A2A 1.0 has none for a wait inside the agent; `WORKING`
  with a message is what the protocol offers.
- Keeping the text under an answer level that stores no content: a
  tightening to `metadata` or `none` removes the status message of the task
  and its events (ADR-282), this text included, as it removes the fixed
  `FAILED` text. The next transition writes it again.

## Verification

| Requirement | Evidence |
| --- | --- |
| WS1, WS2, WS3 | functional `A2aTaskMirrorTest::aWaitTheInstallationDecidesNamesWhatTheTaskWaitsFor` (exact revisions and events), `aStoredDecisionEndsTheWaitText`, and the revision counts of `aTerminalTransitionOfABoundRunReachesTheTaskView` |
| WS4 | functional `A2aTaskMirrorTest::aWaitForTheClientKeepsItsQuestion` |
| WS5 | functional `A2aTaskMethodsTest::getTaskAndListTasksSayWhatAWorkingTaskWaitsFor` |
| WS6 | functional `A2aSseTest::aStreamSaysWhatAWorkingTaskWaitsFor`, `aSubscriptionDuringAWaitStartsWithTheText` |
| WS7 | functional `A2aPushSenderTest::aWaitIsPushedWithItsText` |
