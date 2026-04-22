.. include:: /Includes.rst.txt

.. _adr-024:

==========================================
ADR-024: Dashboard Widgets
==========================================

:Status: Accepted
:Date: 2026-04
:Authors: Netresearch DTT GmbH

.. _adr-024-context:

Context
=======

``tx_nrllm_service_usage`` has tracked per-request cost and usage from day
one, but the data was only reachable through the backend module's report
views. Administrators wanted an at-a-glance view next to everything else
they already follow — scheduled tasks, indexing, form submissions — which
lives on TYPO3's dashboard.

.. _adr-024-decision:

Decision
========

Ship two widgets that reuse TYPO3's built-in widget classes and wire them
up with nr-llm-specific data providers:

* **AI cost this month** — :php:`NumberWithIconWidget` backed by
  :php:`MonthlyCostDataProvider`, which delegates to
  :php:`UsageTrackerService::getCurrentMonthCost()`. Returns dollars
  floored to an integer; the dashboard tile is a glance-value, not an
  accounting figure.
* **AI requests by provider (7d)** — :php:`BarChartWidget` backed by
  :php:`RequestsByProviderDataProvider`, which aggregates every service
  type (chat, vision, translation, speech, image) by ``service_provider``
  over the last seven days.

Both are registered in a dedicated :file:`Configuration/Services.Dashboard.yaml`
imported conditionally from :file:`Configuration/Services.php` when
:php:`TYPO3\\CMS\\Dashboard\\Widgets\\WidgetInterface` exists. Without that
guard, TYPO3 instances that do not have :code:`typo3/cms-dashboard` installed
would fail at container compile time on the unresolved widget class.

Classes/Widgets/* is excluded from the global auto-registration in
:file:`Services.yaml` for the same reason — the data provider classes
import dashboard interfaces and must not be loaded when dashboard is
absent.

.. _adr-024-tradeoffs:

Trade-offs
==========

* **+ Reuse core widget classes.** Two core TYPO3 widget types cover the
  useful shapes. Writing a custom widget buys nothing.
* **+ Optional dependency.** :code:`typo3/cms-dashboard` is a ``suggest``,
  not a hard ``require``. Installs without dashboard lose the widgets but
  pay no runtime cost and see no container errors.
* **- Two data-shape spots.** The row-shaping logic on
  :php:`RequestsByProviderDataProvider::shapeChartData()` is static for
  unit-testability, but the SQL lives in an instance method bound to
  :code:`ConnectionPool`. The trade-off keeps unit tests honest and
  functional coverage narrow.
* **- Flooring the cost.** Displaying :code:`$12.97` as :code:`12` is
  jarring for cost-sensitive users but the widget API returns :code:`int`.
  Follow-up: a custom template could render the subtitle with fractional
  digits once we have one.

.. _adr-024-alternatives:

Alternatives considered
=======================

* **Custom widget classes** implementing :php:`WidgetInterface` directly.
  Rejected — duplicates what the core widgets already do.
* **Per-day time series** instead of per-provider aggregate. Interesting
  but the current 7-day window is short enough that the distribution is
  the more useful glance value.
* **One combined widget** with cost + count + top provider in a single
  tile. Rejected — mixes two summary numbers into one, and forcing both
  to share the :php:`NumberWithIconWidget` shape cripples both.
