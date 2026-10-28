<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->

# A monthly billing report in Analytics: cost with markup, active and entitled editors, results by kind, CSV export

Billing the AI features to a customer needs per calendar month: the estimated cost with the agency's markup, the editors who used the features, the editors who were allowed to, and the results by kind, as a file a spreadsheet imports. The Analytics module shows rolling ranges and has no export (ADR-231).

## Required behavior

1. `nrllm_analytics` gets the actions `billing` and `exportBilling`. Both refuse a non-administrator with 403 themselves, on top of the module's `access => admin`. The index page links to `billing`.
2. A month is a calendar month in the server timezone, from 00:00:00 on its first day to 23:59:59 on its last day, passed as `from`/`to` to the existing inclusive queries. `month=YYYY-MM` selects it; anything else, and a month that has not begun, falls back to the previous complete month, which is the default. The selector offers the running month and the twelve before it; the running month is marked as not complete.
3. Cost: requests, tokens, estimated cost, markup percent, markup amount, total. The markup is the extension setting `billing.markupPercent` (`int[0-1000]`, default 0), added once to the month total in micro-dollars, rounded half up; stored costs never change. Read defensively: clamped to the range, not a whole number reads 0, and the page says so.
4. Active editors: every backend user with at least one request in the month, from `getPerUserUsage()`, with cost, requests, tokens and today's account state (administrator, disabled, deleted). The `be_user = 0` row is shown separately and is no editor.
5. Entitled editors, as of today: users who can log in now, hold `tasks_use` through a group or subgroup, and have `nrllm_aitasks` through `userMods` or a group's `groupMods`, resolved by core's `GroupResolver`. Administrators are not counted; their number is stated. The page names the date of the state.
6. Results by kind: requests, tokens and cost grouped by `service_type`, `source_extension`, `task_uid`, through one new whitelisted multi-column grouping on `UsageAnalyticsServiceInterface`; task names read without restrictions.
7. CSV: one table with a `section` column, RFC 4180, CRLF, UTF-8 without BOM, dot decimals, amounts with 6 decimals and the currency in the column name, file name `nr-llm-billing-YYYY-MM.csv`. Text cells starting with `=`, `+`, `-`, `@`, tab or CR get a leading `'`.
8. Tables have captions and header cells; the export is a link with a text label; colours only through backend tokens. Labels in English and German.

## Not done

No per-site or per-tenant split (usage rows carry no site; no column, metadata key or migration is added). No change to stored costs, no history of permissions, no scheduled export, no currency other than USD.

## Tests

- Unit `BillingMonthTest`: first and last second in Europe/Berlin for a month with a DST change, a leap February and December; previous month; parameter parsing; selector.
- Unit `BillingAmountsTest`: 10 % of 100 is 110; half-up rounding to one micro-dollar; defensive reading of the setting.
- Unit `BillingReportCsvTest`: round trip of a comma, a quote, a newline; formula neutralisation for every trigger in every text column; numbers, header, sections, CRLF, no BOM.
- Functional `MonthlyBillingReportServiceTest`: month boundaries against rows on both sides; markup on the total; active editors including administrator, disabled, deleted and vanished users and the `be_user = 0` row; entitled editors with direct and subgroup grants, module through user or subgroup, and every exclusion; grouping by kind with a deleted task.
- Functional `AnalyticsControllerTest`: the page renders the numbers, captions and a tokenised export link; the export's headers and first row; 403 for a non-administrator on both actions.
- Functional `UsageAnalyticsServiceTest`: the multi-column grouping and its whitelist.
- Mutation proof: month boundary off by one, markup applied twice and not at all, an administrator counted as entitled, the CSV injection guard removed, the export without the admin check.
