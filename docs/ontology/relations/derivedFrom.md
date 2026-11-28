---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Relation
title: "derivedFrom"
description: "The source is produced from the target."
x-nr-propagates: to-source
status: draft
timestamp: 2026-11-27
---

# Definition

The source is produced from the target by an import, a sync or a build. The source is a copy or a result, and the target is the origin.

# Propagation

A change to the target makes the source stale until the source is produced again.

# Example

A skill is derived from its skill source.
