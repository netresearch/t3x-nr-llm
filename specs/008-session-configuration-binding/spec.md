# Every conversation has a configuration (#894)

ADR-151 bound a conversation to the configuration it was opened with, and
`ConversationService` states that binding in its own class docblock. The binding
has a hole exactly one turn wide: `startSession()` accepts a null configuration
and writes an empty `configuration_identifier`, and everything downstream reads
that emptiness as "no binding".

## What actually happens today, in order

1. `resolveTurnConfiguration()` returns null for an empty identifier.
2. The ADR-121 context-window fit is therefore skipped — turn 1 is budgeted
   against nothing at all.
3. `dispatch()` falls through to the generic `chat()`, where `LlmServiceManager`
   resolves the installation default itself.
4. That configuration's skill block is injected by the manager into a transcript
   this service already sized without it. `skillBlockFor()` documents this as a
   known limit in its own docblock.

So the conversation does run against a configuration. It runs against one nobody
wrote down, chosen a layer lower, a turn later, and re-chosen on every turn.

## What it must do

- `startSession()` ends with a concrete configuration: the caller's if given,
  otherwise the installation default resolved at that moment, and the identifier
  is persisted with the row.
- When neither resolves, the session is refused and **no row is written**.
- A session opened before this rule binds itself once, on its next turn, with
  the actor in hand.
- The binding write is conditional on the row still being unbound, so two
  concurrent turns cannot re-point a conversation the first already bound — and
  the turn that loses that race follows the row rather than its own read.
- A criteria-mode default is usable: it carries no model by design, and the
  resolution every later turn goes through does not ask for one either.

## What it explicitly does not do

- **No upgrade wizard.** A wizard writes the default as it stands when the
  wizard runs — for a conversation nobody continues that is a decision made for
  nothing, and for one that is continued it is the same decision the next turn
  makes, one turn earlier and without the actor. A restricted default would then
  be guessed at rather than evaluated.
- **No new column, no schema change.** `configuration_identifier` already
  exists; what changes is that it is never left empty.
- **No change to what happens when a bound configuration goes away.** ADR-151
  already decided that: the conversation stops rather than drifting onto the
  default. This change only means it now applies to every session.
- **No fallback for a legacy session with no usable default.** It keeps running
  unbound. An improvement that cannot be made is not a reason to end a
  conversation someone is in the middle of.

## The one decision that had a real alternative

Refusing to open a session when there is no usable default is a behaviour change
on a public method: `startSession()` now throws where it previously succeeded.
The alternative was to keep writing an empty identifier and treat it as a
soft state. That is the state this change exists to remove, and it merely moves
the failure one turn later, into a different error message, on a conversation
that has already been shown to a user. The signature does not move, so the
frozen surface is unchanged; the CHANGELOG announces the behaviour.

## Which suite proves each requirement

| Requirement | Proof |
| --- | --- |
| A session opened without a configuration is bound to the default | `ConversationServiceTest::aSessionOpenedWithoutAConfigurationIsBoundToTheInstallationDefault` |
| No row is written when there is no usable default | `ConversationServiceTest::aSessionCannotBeOpenedWhenTheInstallationHasNoUsableDefault` — asserts the exception code AND that the repository stayed empty |
| Changing the installation default does not re-route an existing conversation | `ConversationServiceTest::changingTheInstallationDefaultLeavesAnExistingConversationWhereItIs` — sends the second turn through a resolver whose default is a different configuration, and asserts the ORIGINAL one is used |
| A legacy session binds itself once | `ConversationServiceTest::aLegacySessionWithoutAConfigurationBindsItselfOnItsNextTurn` — two turns, one bind call |
| Turn 1 is fitted against the same configuration as turn 2 | `ConversationServiceTest::turnOneIsFittedAgainstTheSameConfigurationAsTurnTwo` — records the identifier the context window was handed on each turn |
| A criteria-mode default without a model is usable | `ConfigurationResolverTest::resolveDefaultForActorAcceptsACriteriaDefaultWithoutAModel` |
| A bind that loses the race follows the persisted configuration | `ConversationServiceTest::aLegacyBindThatLosesTheRaceFollowsThePersistedConfiguration` — the fixture plants the winner during the write, the way the conditional UPDATE does |
| A restricted default is evaluated, not refused | `ConfigurationResolverTest::resolveDefaultForActorAppliesARestrictedDefaultToAnEntitledActor` — both directions in one case |
| No usable default is null, not an exception | `ConfigurationResolverTest::resolveDefaultForActorReturnsNullWhenThereIsNoUsableDefault` — three ways of having none |

## Not in scope

`LlmServiceManager`'s own default resolution for callers that are not
conversations (`chat()`, `complete()`, the CLI worker) is unchanged. Those have
no actor, which is precisely why `resolveDefaultConfiguration()` refuses a
restricted default there and why it keeps doing so.
