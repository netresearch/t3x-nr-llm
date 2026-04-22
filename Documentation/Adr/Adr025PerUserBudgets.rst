.. include:: /Includes.rst.txt

.. _adr-025:

==========================================
ADR-025: Per-User AI Budgets
==========================================

:Status: Accepted
:Date: 2026-04
:Authors: Netresearch DTT GmbH

.. _adr-025-context:

Context
=======

:php:`LlmConfiguration` already exposes ``max_requests_per_day``,
``max_tokens_per_day`` and ``max_cost_per_day`` — but those limits are
**per configuration**, not per editor. Two editors sharing the same
preset burn through the same bucket. Administrators asked for a separate
dimension: cap editor A's spending independently of editor B's, regardless
of which configuration they pick.

.. _adr-025-decision:

Decision
========

Ship a new :code:`tx_nrllm_user_budget` table keyed uniquely on
``be_user``. Each row carries six independent ceilings: requests / tokens
/ cost, times daily / monthly. ``0`` on any axis means "unlimited on
that axis". The record is a **ceiling, not a counter** — actual usage is
aggregated on demand from :code:`tx_nrllm_service_usage`, the same table
the usage tracker already writes to, so there is no second write per
request and no opportunity for the two sources to drift.

:php:`BudgetService::check($beUserUid, $plannedCost)` is a pure
pre-flight. It does **not** increment anything. Callers invoke it before
dispatching to the provider, receive a :php:`BudgetCheckResult` that says
allowed / denied + which bucket was tripped, and act accordingly.

.. _adr-025-rules:

Resolution rules
================

1. Uid :code:`<= 0` → allowed (CLI / scheduler / unauthenticated).
2. No budget record for the user → allowed.
3. Record exists but ``is_active == false`` → allowed.
4. Record exists but every limit is ``0`` → allowed.
5. Otherwise: evaluate the daily bucket, then the monthly bucket. The
   first to exceed wins and is reported; daily trips take precedence over
   monthly.
6. The incoming call adds ``+1`` to the request count and ``+plannedCost``
   to the cost figure **before** comparison, so a user at exactly the
   limit is still allowed one more call.

.. _adr-025-scope:

Scope
=====

Matches the pattern established for capability permissions (ADR-023):
this ADR ships the **table + model + repository + check primitive**.
Wiring :php:`BudgetService::check()` into individual feature services
(:php:`CompletionService`, :php:`VisionService`, ...) is a follow-up.

.. _adr-025-relation:

Relation to existing limits
===========================

:code:`tx_nrllm_configuration.max_*_per_day` remain in place and are
orthogonal:

* **Per-configuration daily limits** cap *a preset*. Useful to stop
  "expensive-model" presets from burning through budget even if many
  editors share them.
* **Per-user budgets** cap *a person* across every preset. Useful to
  stop a specific account from running away, whichever preset they pick.

Both checks must pass. Future consumers who want both will check both.

.. _adr-025-alternatives:

Alternatives considered
=======================

* **Counter-style table** (increment on every request). Rejected:
  duplicates :code:`tx_nrllm_service_usage`, introduces a second write per
  request, and adds the drift-between-counters failure mode we deliberately
  avoid.
* **Group-level budgets** via MM to be_groups. Rejected for v1 —
  individual-user budgets solve the common ask first. Group-level can
  layer on later.
* **Auto-throttling** (queue + retry when over budget). Rejected —
  silent throttling is worse UX than an explicit denial with a reason
  the caller can surface.
