<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->

# Editors see their own usage, budget and a cost estimate in AI Tasks

An editor in the AI Tasks module (ADR-131) learns only from a hint text that runs count against a personal budget. `BudgetService::check()` can evaluate a planned cost, and no surface uses it. The module shows the editor's own usage against the limits, and the run form shows what one run may cost and whether the next run passes the budget.

## Required behavior

1. One read model returns the current user's budget status: usage today and this month (requests, tokens, cost) and the limits of the user's budget record. The windows are the ones `BudgetService::check()` uses — today from 00:00, this month from the first at 00:00, both up to now — read through `BudgetUsageWindowsInterface::aggregate()`, so the numbers are the numbers enforcement compares. No new table.
2. A budget record counts as a personal limit only when enforcement applies it: active and with at least one limit above 0. Otherwise the panel says that no personal limit is set and still shows the usage. A limit of 0 on one axis reads "no limit".
3. The uid comes from the logged-in backend user only, never from a request value. A uid of 0 (no logged-in user) reads no usage: `be_user = 0` holds the unattributed CLI and scheduler usage of every caller.
4. `list` and `executeForm` of `nrllm_aitasks` show the panel instead of the budget hint. A limit renders a `<progress>` with a text equivalent ("~$3.20 of ~$10.00") referenced by `aria-describedby`.
5. `executeForm` shows a cost estimate for one run of the task with its effective configuration — the configuration the execution resolves (`LlmServiceManagerInterface::resolveEffectiveConfiguration()`). Input tokens come from `TranscriptEstimator` over the configuration's system prompt and the task prompt built with the input known on the server: the auto-fetched data for an automatic task, nothing for a manual task, which the page says. Low = input only; high = input plus the configuration's `maxTokens` as output. A range exists only when the model has an input price, an output price and the configuration a `maxTokens` above 0; otherwise the page says the cost cannot be estimated.
6. `executeForm` states whether the next run passes `check()`: once with planned cost 0 (what the execution enforces today) and once with the upper estimate. It names the limit that would be exceeded, without numbers: a configuration limit counts every user's usage, which is not the editor's data.
7. After a run the execute response carries the refreshed status and verdict; `TaskExecute.js` updates the panel. The admin task module, which loads the same script without the panel, is unaffected.
8. Labels in English and German; costs formatted as the Analytics module does (`~$` and two decimals).

## Not done

No AJAX re-estimate for typed input, no change to what the execution enforces (no planned cost is passed to `BudgetMiddleware`), no cowriter dialog, no reports, no per-site billing, no new table.

## Tests

- Unit `UserBudgetStatusServiceTest`: windows passed to `aggregate()`, inactive and limit-less budgets read as "no personal limit", uid 0 reads nothing.
- Unit `TaskCostEstimatorTest`: low and high bounds against a hand computation, no range for a one-sided price or no ceiling, manual input excluded.
- Unit value-object tests for percent and formatting.
- Functional `AiTaskControllerTest`: the panel renders the logged-in user's numbers and never another user's; the estimate renders on the run form.
- Functional `TaskExecutionControllerTest` (or equivalent): the execute response carries the status.
- Mutation proof: reading another uid, dropping the output term from the high bound, swapping the bounds, and treating an inactive budget as a limit each turn a test red.
