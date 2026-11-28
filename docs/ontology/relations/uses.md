---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Relation
title: "uses"
description: "The source needs the target to do its work."
x-nr-propagates: to-source
status: draft
timestamp: 2026-11-27
---

# Definition

The source needs the target to do its work. Without the target, or with a changed target, the source can fail or behave differently.

# Propagation

A change to the target can affect the source. The source is a candidate for a check; whether it breaks is decided by tests.

# Example

A configuration uses a model.
