<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# A configuration may answer a repeated request from the cache

> Current contract: [L01](../current-contract.md#l01-cached-answers-need-an-explicit-policy-freshness-rule). These named amendments supersede conflicting historical increment wording below. [Inventory](../README.md).

A chat or completion asked again with the same request goes to the provider
again. A replay of 90 days of demo telemetry found an upper bound of 17 in 133
attributed plain calls with an equal-shaped twin within an hour, 33 within a
day. Decision record: ADR-249.

## What it must do

- Offer `answer_cache_ttl` on a configuration, in seconds, 0 (default) to 86400.
- Attach an answer-cache entry to a plain chat or completion of a configuration
  with a positive value, keyed over the configuration record and its last edit,
  the operation, the messages after skill injection, the per-call options and
  the site.
- Store a complete text answer (finish reason `stop`, no tool calls, not empty)
  and serve it again within the window, without usage, as a cache hit.
- Write and serve an entry only when the privacy level of the provider that
  served the answer, tightened from the global one, is `full`.

## What it explicitly does not do

- No entry for a call of an agent run (also one whose row could not be
  written), a call with injected context, a call bound to a response schema, a
  tool call or a streamed answer. In JSON mode only an answer that parses is
  kept.
- No caching for a caller that only sets its own cache key on a chat call.
- No per-call opt-in through `ChatOptions`.

## Which suite proves each requirement

| Requirement | Suite and test |
|---|---|
| Field and its range | unit `LlmConfigurationTest::answerCacheIsOffByDefault`, `setAnswerCacheTtlClampsToTheWindow` |
| Key covers what shapes the answer | unit `CallMetadataFactoryAnswerCacheTest` |
| Which calls carry the entry | unit `LlmServiceManagerAnswerCacheTest` |
| Store, replay, privacy, what is kept | unit `CacheMiddlewareAnswerTest` |
