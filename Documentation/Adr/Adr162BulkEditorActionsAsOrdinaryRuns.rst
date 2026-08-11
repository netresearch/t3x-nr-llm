.. include:: /Includes.rst.txt

.. _adr-162:

============================================================================
ADR-162: Bulk editor actions are N ordinary runs, not a bulk runtime
============================================================================

:Status: Accepted
:Date: 2026-08-11
:Amends: :ref:`ADR-158 <adr-158>` (its rejection of a bulk surface)
:Authors: Netresearch DTT GmbH

.. _adr-162-context:

Context
=======

:ref:`ADR-158 <adr-158>` gave an editor one action on one record. The next thing
an editor asks for is the same action on the twenty pages they just imported, and
both earlier records refused it.

:ref:`ADR-152 <adr-152>` declined a ``bulkCapability`` flag on the declaration.
:ref:`ADR-146 <adr-146>` put "what did the approver agree to" on its
``Revisit when`` list. ADR-158 rejected a bulk surface outright. All three cite
the same reason, and it is a good one: **the approval unit is a turn.** One
suspension produces one digest and one verdict for every pending call in it, and
:ref:`ADR-133 <adr-133>` explicitly refuses a per-call verdict — an approver
either takes the turn or refuses it. "Approve 200 writes" is not a decision a
card can carry. :ref:`ADR-141 <adr-141>`'s write fence stamps ONE pending effect
per run row, so a runtime that folded many records into one run would also have
to invent a second stamping rule.

.. _adr-162-decision:

Decision
========

   **A bulk editor action is N ordinary agent runs. There is no bulk runtime, no
   batch approval, no shared digest and no queue of its own.**

That single sentence is also the answer to the objection all three earlier
records raised. The objection is about one run holding many writes. It says
nothing about many runs:

* N runs are **N turns** — one suspension each.
* N turns are **N digests** and **N verdicts** — :ref:`ADR-133 <adr-133>` is
  satisfied without an exception, because no approver is ever asked to decide
  more than one turn at a time.
* N runs are **N run rows**, so :ref:`ADR-141 <adr-141>`'s one-pending-effect
  fence stamps once per row, unchanged.
* Budget, audit, routing, tool policy and idempotency see N indistinguishable
  single-record runs, because that is what they are.

Nothing about the approval unit changes, and that is the whole reason this is
buildable now while a ``bulkCapability`` flag still is not.

**Per record, the catalogue is asked again.**
:php:`EditorActionBatchPlanner` calls
:php:`EditorActionCatalogueInterface::runRequestFor()` once for each record and
collects the answers. A record the viewer may not act on comes back as null and
is recorded as skipped **with a reason an editor reads**, on the confirmation
page and again in the flash messages after the batch. It is never dropped
quietly, and the batch is never authorised once and applied to the rest.

The declaration used for the page heading IS resolved once for the batch — a
translatable name has to come from somewhere — and that is presentation only.
Authorisation stays at one ``runRequestFor()`` per record.

**Honest about what that check does today.** ``runRequestFor()`` authorises on
tool, table, configuration and viewer, and validates the record number only for
being a positive integer. Asking it twenty times therefore returns twenty
identical answers right now. It is still asked per record, because the catalogue
is the seam that owns the question: a per-record axis added there — a
record-level permission, a workspace state, a lock — must reach this surface
without anyone remembering to come back and change a loop.

**A bound of 20 records.** Two real constraints set the ceiling. The batch runs
its N runs **synchronously inside one backend request**, using the same
:php:`AgentRuntimeInterface::run()` the single-record path uses, and each run
costs at least one provider round trip; a batch that outlives
``max_execution_time`` is truncated mid-loop, which is the one failure mode that
leaves an operator unable to say what started. And each suspended run is its own
inbox card with its own verdict, so the number also bounds how many decisions one
press hands an approver. Twenty is a judgement inside those two bounds, not a
measurement. Raising it means moving the loop onto
:php:`AgentRuntimeInterface::enqueue()`, which is a separate decision with its own
failure modes; this record does not take it.

.. _adr-162-estimate:

The cost estimate, and how wrong it is
======================================

The confirmation page shows requests, tokens and a price range before anything
starts. Every number is derived from something the plan already holds. None is a
typical-case guess, because a made-up range is worse than no range.

.. list-table::
   :header-rows: 1

   * - Number
     - Where it comes from
   * - Runs
     - The count of records the catalogue offered — the plan's own entries.
   * - Provider requests
     - Runs × 2. Two is derived, not assumed: the first send decides the tool
       call and the declared write suspends (:ref:`ADR-134 <adr-134>`); the send
       after approval turns the tool result into the answer. It is a FLOOR, and
       the page says "at least".
   * - Input tokens
     - :php:`TranscriptEstimator` (:ref:`ADR-107 <adr-107>`) run over the ACTUAL
       messages of the first :php:`AgentRunRequest` the plan built, plus the JSON
       schema of the one tool the run may call, taken from the registry with the
       run's own allow-list. Every request in a batch differs only in a record
       number, so the first stands for all of them.
   * - Output ceiling
     - The configuration's own ``maxTokens``. What the provider is asked to
       respect, not a guess at answer length. A ``maxTokens`` of **0 means
       unbounded** on :php:`LlmConfiguration` — no ``max_tokens`` goes on the
       wire at all — so it is reported as "no ceiling", never as a ceiling of
       zero.
   * - Price range
     - :php:`Model::estimateCost()` on the model record's stored per-million
       prices. Low assumes no output at all; high assumes every request returns
       the full ceiling. Shown only when **both** rates are stored **and** a
       ceiling exists.

**How wrong it can be**, and the errors do not cancel:

* **Under, materially.** The loop's ``assemble()`` step prepends the
  configuration's system prompt and its skills before the first send. Those are
  not in the request the plan holds, so they are not counted. A long system
  prompt can be larger than the whole measured prompt.
* **Under.** The second send carries the first send's transcript plus the
  assistant tool call plus the tool result. The estimate charges it the initial
  transcript, which is strictly smaller.
* **Over.** :php:`TranscriptEstimator` errs high by construction
  (:ref:`ADR-107 <adr-107>`): a chars/3.5 prose divisor over UTF-8 bytes, plus
  per-message overhead.
* **Over, by a lot, on the high price** — whenever a range is shown at all.
  ``maxTokens`` for every request is a ceiling an editorial change never
  reaches. The upper bound is a ceiling, not a forecast.
* **Stale money.** Prices are what an administrator typed on the model record.
  Nothing in this extension refreshes them.
* **No range at all** in three cases, and the rule behind all three is the same:
  a bound of ``0.00`` reads as "free" when it means "unknown", so no bound is
  printed unless it can be computed. The configuration names no model. The model
  is priced on **one side only** — :php:`Model::hasPricing()` is true when
  either rate is set and :php:`estimateCost()` charges the missing one as zero,
  which would price a whole output ceiling at nothing, so both rates are
  required here. Or the configuration sets **no output ceiling**, which leaves
  the upper end unbounded and therefore unquotable.

The estimator's learned calibration factor is deliberately NOT applied: it
belongs to a live context window, and borrowing it would make the same batch
quote different numbers on different days for no reason an editor can see.

Historical per-action usage was considered as a source and rejected on the
facts: ``tx_nrllm_service_usage`` aggregates per day, service type and provider.
It has no per-tool and no per-editor-action dimension, so it cannot answer "what
did this action cost last time".

.. _adr-162-consequences:

Consequences
============

**A half-done batch is real, and it is visible.** The budget gate
(:php:`BudgetServiceInterface`) is hit once per run, which is correct — N runs are
N spends. An operator who starts twenty runs on an account with room for eleven
gets eleven. This record does not pretend otherwise:

* The loop **stops at the first run the budget refuses**. Continuing would
  produce nine more identical denials and nine more empty run rows.
* **A budget-denied run settles COMPLETED, not FAILED**, and the stop is
  detected on that shape. :php:`ToolLoopService` catches the denial itself and
  returns a truncated result carrying
  :php:`AgentRunTerminationReason::BUDGET_EXHAUSTED`
  (:ref:`ADR-092 <adr-092>`); the executor has no arm for that, so the run is
  settled as an ordinary completion with **no error on the result**. The batch
  therefore reads the loop result's termination reason. It also accepts a
  :php:`BudgetExceededException` on the result's error, because the loop's
  no-tools branch sends outside that catch and the exception does propagate
  there — but a guard that watched only the error would be dead code on the path
  an editor action actually takes.
* The record the budget refused is **not** among the never-started: it was run.
  The message says the batch stopped, and names the records the stop kept from
  starting only when there are any — a denial on the last record still reports
  the stop.
* Every other terminal outcome is per record and does **not** stop the batch: a
  model declining one page is not a reason to abandon the other nineteen. They
  are counted **by kind**, with the same partition the single-record path uses:
  a run that failed, one a guardrail stopped, one that was cancelled and one
  that simply proposed nothing are four different things to an editor, and a
  batch in which everything failed must not read like a batch in which nothing
  needed changing. ``SUSPEND_FAILED`` counts with the failures — an approval was
  required and could not be stored, so nothing can resume it.

The mitigation that makes a half-done batch survivable is the one the design
already relies on: because a declared write suspends before it touches anything
(:ref:`ADR-134 <adr-134>`), a batch that dies at run twelve has produced eleven
*proposals*, not eleven writes. Nothing is half-changed; some approvals exist and
some do not.

**The plan is rebuilt on the POST.** The confirmation page's plan is not carried
into the request that starts the batch — a plan carried across a request is a
permission carried across a request. ``startBatch`` re-plans from the same raw
inputs, so a POST naming records the GET never offered starts nothing.

**Duplicates and junk are reported, not absorbed.** A record number named twice
is planned once and the second mention is listed as skipped. Entries that are not
record numbers at all are counted and reported. Records beyond the cap are listed
as skipped rather than truncated away.

**The entry point takes record numbers, and does not pick them.** ADR-158
withheld a record picker because a picker is a read boundary
(:ref:`ADR-130 <adr-130>`), and that reasoning is unchanged: this surface
enumerates nothing and reads no record. It is handed a table and a list of
numbers — seeded from the record the context menu carried — and passes them on.
The consequence an editor sees is a text field of numbers rather than a list of
titles.

.. _adr-162-alternatives:

Alternatives considered
=======================

**One run holding N tool calls.** Rejected — this is the shape all three earlier
records refused, and refusing it is the premise of this one. It would need a
per-call verdict (:ref:`ADR-133 <adr-133>` says no), a digest format for many
writes, and a second write-fence rule for many pending effects on one row
(:ref:`ADR-141 <adr-141>`).

**A queue of its own.** Rejected: :php:`AgentRuntimeInterface` already has
:php:`enqueue()`, and a second one would be a second lifecycle. Not used either
— its transport is the operator's choice and defaults to synchronous, so it does
not remove the timeout the cap exists for, while adding an outcome shape this
surface would have to explain. The cap is the honest answer until asynchronous
execution is a decision someone takes deliberately.

**Continuing past a budget denial.** Rejected: every later run hits the same
exhausted bucket, so the only product is noise — nine more failed rows and nine
more messages saying the same thing.

**Reading the record list's clipboard for the selection.** Not built.
:php:`Clipboard::elFromTable()` answers for the CURRENT pad, and the "normal" pad
holds a single element, so what an editor gets from a tick-and-click depends on
pad state this record's authors did not verify. Shipping a selection source whose
behaviour is assumed would be worse than a field an editor fills in. The entry
point takes a plain list of numbers, so a verified clipboard reader can feed it
later without changing anything else.
