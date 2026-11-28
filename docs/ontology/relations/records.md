---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Relation
title: "records"
description: "The source is a log of what the target did."
x-nr-propagates: none
status: draft
timestamp: 2026-11-27
---

# Definition

The source is a log of what the target did. The source holds past facts and does not control the target.

# Propagation

No propagation. A change to the target changes the next records, never the old ones.

# Example

A usage record records a call through the middleware pipeline.
