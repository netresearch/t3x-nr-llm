---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Relation
title: "fallsBackTo"
description: "The source hands its work to the target when the source fails."
x-nr-propagates: to-source
status: draft
generated: {by: claude-code/2.1.332, at: 2026-11-27T21:06:57Z}
---

# Definition

The source hands its work to the target when the source cannot complete it. The target runs only on a failure of the source.

# Propagation

A change to the target changes what happens when the source fails. The normal path of the source stays the same.

# Example

A configuration falls back to the next configuration of its fallback chain.
