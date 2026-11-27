---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Relation
title: "approves"
description: "The source records the consent of a person to one version of the target."
x-nr-propagates: to-source
status: draft
timestamp: 2026-11-27
---

# Definition

The source records that a person consented to exactly one version of the target. The consent covers that version only.

# Propagation

A new version of the target is not covered by the earlier approval. The approval must be given again for the new version.

# Example

A skill approval approves one version digest of a skill.
