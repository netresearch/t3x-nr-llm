.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _routing-reference:

=======================
Model routing reference
=======================

Fixed configurations name a model. Criteria configurations resolve an eligible
model for the requested operation. Capability, provider activity, context and
price criteria exclude candidates before ranking. An operator's provider
priority is compared before measured scores; a measured score cannot overturn
that explicit priority.

.. list-table:: Raw signal weights
   :header-rows: 1

   * - Mode
     - Quality
     - Health
     - Cost
     - Latency
   * - ``providerPriority`` (default)
     - 0
     - 0
     - 0
     - 0
   * - ``balanced``
     - 0.4
     - 0.4
     - 0.2
     - 0.1
   * - ``quality``
     - 0.7
     - 0.3
     - 0
     - 0.05
   * - ``economy``
     - 0.1
     - 0.3
     - 0.6
     - 0.05

These are weights, not percentages of all requests. Absolute signals with no
measurement are skipped and the score is normalized over available weights.
Relative call-history signals use a neutral midpoint for missing data. Cost
participates in economy mode and where criteria request it; quality mode's
zero cost weight leaves cost preference as a tie-breaker.

.. _routing-reference-history:

Call history and quality selection
==================================

``routing.callHistory`` defaults to off. When enabled, known per-operation
latency and call cost can affect measured modes. Cost history replaces listed
cost for the decision when applicable; it is not added a second time. Relative
signals need a meaningful difference between candidate observations. They
reflect the traffic each model received, not an unbiased comparison trial.

Quality routing requires a deterministic completion benchmark and explicit
``routing.qualitySet`` and ``routing.qualityFingerprint`` selections. Run the
benchmark, inspect its printed fingerprint and results, then configure that
exact set/revision. Decision-graded runs retain scores and reported grader
series, but currently have no verified comparable completion-benchmark
revision. Re-evaluation alone does not select a routing benchmark.

The readout's **signals used** means a positively weighted measured signal
contributed to scoring. It does not show that the winner would change without
that signal. Structural complexity, payload shape and token estimates remain
diagnostic; they do not adapt model choice by themselves.

Criteria-mode pricing uses the model actually resolved for the call, without
mutating the stored configuration. Unknown cost is distinct from a provider
reported zero. See :ref:`administration-analytics` and
:ref:`developer-quality-evaluation` for measurement and evaluation limits.
