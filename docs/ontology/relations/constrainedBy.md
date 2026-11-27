---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Relation
title: "constrainedBy"
description: "The target limits or checks what the source may do."
x-nr-propagates: to-source
status: draft
generated: {by: claude-code/2.1.332, at: 2026-11-27T21:06:57Z}
---

# Definition

The target limits or checks what the source may do: a budget, a guardrail, a permission or a rule. The source can do its work without the target, but the target decides what is allowed.

# Propagation

A change to the target changes what the source is allowed to do or what the source returns. The source is a candidate for a check.

# Example

A call through the middleware pipeline is constrained by the budget of the backend user.
