.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

===========================
Provider health diagnostics
===========================

..  _administration-analytics-health:

Provider health and circuits
============================

A table lists every provider that is either configured and active or named
by a run in the telemetry window, with its **health score**, the **number
of samples** the score is based on, the **window** those samples were taken
over, and the state of its **circuit breaker**.

Health and circuit state are both keyed by **adapter type**, not by
provider record — two provider records on the same adapter share one score
and one circuit, because it is the provider that is unhealthy, not the
record.

..  note::
    **Direct calls with a pinned provider are not in this table.**
    :php:`chat()`, :php:`complete()`, :php:`embed()` and their siblings
    called with a ``provider`` option skip the default configuration
    entirely and run against a transient one carrying no model. Such a run
    records no provider in the telemetry log, and its circuit is kept
    under the call's own identifier (``ad-hoc:chat:openai``, one per
    operation) rather than under ``openai``. An ``openai`` row here
    therefore reports the circuit of the configuration-backed calls only;
    a circuit opened for direct pinned calls is not shown anywhere on this
    page. Route traffic through a configuration if you need it covered.

..  list-table::
    :header-rows: 1
    :widths: 25 75

    * - Column
      - Meaning
    * - Score
      - A single 0.00–1.00 number combining success rate and mean
        latency, success rate weighted four times as heavily (see
        :ref:`adr-063`). Higher is healthier.
    * - Samples in window
      - How many runs the score was computed from. Read it before the
        score: 0.90 over two calls and 0.90 over two thousand are
        different statements.
    * - Success rate
      - Share of runs the provider served **itself**. A run a fallback
        rescued counts as a failure of the requested provider.
    * - Avg latency
      - Mean end-to-end time of the self-served runs. A provider whose runs
        in the window were **all** rescued by a fallback has no self-served
        run to measure: the cell says the latency was not measured instead
        of showing ``0 ms``. Its score and success rate are real — the
        first attempt did lose.
    * - Circuit
      - ``closed`` (normal), ``open`` (failing fast for the cooldown) or
        ``half-open`` (cooldown elapsed, one probe due), plus the current
        consecutive-failure streak.

Unlike the rest of this module the table ignores the date range selected
above. Scores come from a rolling telemetry window (15 minutes by
default, named on the page) and circuit state is live cache state — neither
can be re-cut to a 90-day report period.

..  important::
    **A score only changes something when you switched it on.**
    :guilabel:`Health-Aware Fallback Reorder` (``health.reorderFallback``
    in the extension configuration) is **off by default**. While it is
    off, the scores are diagnostic only: the fallback chain keeps the
    order you configured. The page states which of the two positions the
    switch is in, above the table. The circuit breaker
    (``circuitBreaker.enabled``) is on by default, and the page says so
    when it is not.

A provider with no telemetry in the window shows **no data** — not a score
of zero. It was not called; that is not the same as failing.
