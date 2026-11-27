---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Relation
title: "verifiedBy"
description: "The target is a test that checks the source."
x-nr-propagates: none
status: draft
generated: {by: claude-code/2.1.332, at: 2026-11-27T21:06:57Z}
---

# Definition

The target is a test or a check that runs against the source. The target is a path in the repository.

# Propagation

No propagation in the graph. When the source is affected by a change, run the target again.

# Example

A fallback chain is verified by FallbackChainTest.
