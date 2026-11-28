---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Relation
title: "implementedBy"
description: "The target is the code that realizes the source."
x-nr-propagates: to-source
status: draft
timestamp: 2026-11-27
---

# Definition

The target is the code (a class, a table definition or a file) that realizes the source concept. The target is a path in the repository.

# Propagation

A change to the target can change what the source means. Read the concept again after a change to its code.

# Example

A provider is implemented by the class Provider.
