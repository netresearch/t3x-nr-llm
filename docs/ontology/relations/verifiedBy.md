---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Relation
title: "verifiedBy"
description: "The target is a test that checks the source."
x-nr-propagates: none
status: draft
timestamp: 2026-11-27
---

# Definition

The target is a test or a check that runs against the source. The target is a path in the repository.

# Propagation

No propagation in the graph. When the source is affected by a change, run the target again.

# Example

A fallback chain is verified by FallbackChainTest.
