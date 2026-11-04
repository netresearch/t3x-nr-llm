<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# Routing may rank by the call history

The measured routing modes weigh quality, provider health and the list price.
Neither the latency of one model (health is per provider) nor what a call to it
actually costs reaches the ranking, although telemetry records both. On the
demo, two chat models behind one provider averaged 4,259 ms and 9,094 ms, and
$0.0067 and $0.0184 per call. Decision record: ADR-256.

## What it must do

- Offer `routing.callHistory`, off by default.
- With it on, in a measured mode and with a known operation, add a `latency`
  signal per candidate: the fastest candidate's mean latency over this one's.
- Rank cost by `callCost`, the cheapest candidate's mean cost per call over this
  one's, instead of the list price, when every candidate with any cost figure
  has a measured one; otherwise keep the list price for all.
- Count only calls of the same operation the model served itself: successful,
  no fallback, no cache hit, last seven days; at least five per figure.
- Record whether each signal moved the decision, show it on the Governance tab.
- Look provider health up by adapter type.

## What it explicitly does not do

- No history in Provider priority mode, on a fixed-mode configuration, or for a
  decision without an operation.
- No routing on request complexity (ADR-156 is untouched).
- No percentiles, no per-provider-record split of two providers with one
  adapter type.

## Which suite proves each requirement

| Requirement | Suite and test |
|---|---|
| Signals, bases, thresholds | unit `CandidateRankerTest` |
| Operation reaches the ranking | unit `ModelSelectionServiceExplainRoutingTest::theReadoutAndTheRealResolutionBothRankByTheCallHistoryOfTheirOperation` |
| Population and keys | functional `ModelCallHistoryRepositoryTest` |
| Setting, window, cache | functional `ModelCallHistoryTest` |
| Signals used | unit `RoutingSummaryFactoryTest`, functional `RoutingTelemetryTest` |
