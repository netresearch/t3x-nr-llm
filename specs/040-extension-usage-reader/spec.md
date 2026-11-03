<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# An extension reads its own usage

The cowriter and the AI chat each get an overview module that states what the
extension cost and how often its actions ran and failed. nr_llm records both
(`tx_nrllm_service_usage`, `tx_nrllm_telemetry`), but only through internal
services. Decision record: ADR-248.

## What it must do

- Offer `ExtensionUsageReaderInterface` as `@api`, obtainable from the DI
  container.
- `totals()`: sum cost, requests and tokens of the daily aggregate for one
  `source_extension`, in the period (inclusive), and for one backend user
  when given.
- `operations()`: per `source_operation` of that extension, count calls,
  failed calls (`success = 0`) and the newest call time, in the period, for
  one backend user when given; most calls first.
- `coverage()`: report the `telemetry.enabled` setting, the telemetry
  retention in days and the oldest stored telemetry row.

## What it explicitly does not do

- No per-user breakdown and no user names.
- No filtering by configuration, model or provider.
- No change to what is recorded.

## Which suite proves each requirement

| Requirement | Suite and test |
|---|---|
| Only the extension, only the period | functional `ExtensionUsageReaderTest::totalsSumOnlyTheExtensionsRowsInThePeriod`, `operationsCountCallsAndFailuresPerOperation` |
| One user when given | functional `totalsForAUserCountOnlyThatUser`, `operationsForAUserCountOnlyThatUser` |
| Coverage | functional `coverageNamesTheOldestStoredCall`, `coverageWithoutCallsHasNoOldestCall` |
| Public surface | unit `ApiSurfaceSnapshotTest` |
