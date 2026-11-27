---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Concept
title: "Guardrail"
description: "A check on the input or the output of a call that can redact, annotate or block it."
status: draft
generated: {by: claude-code/2.1.332, at: 2026-11-27T21:06:57Z}
---

# Definition

A guardrail is a check on the input or the output of a call. It can redact secrets, add a hint, or block the answer. A configuration names the guardrails it allows; a policy resolver decides which ones apply to a call.

# Relations

| Relation | Target | Evidence file | Evidence text | Status |
|---|---|---|---|---|
| `implementedBy` | `Classes/Service/Guardrail/GuardrailInterface.php` | `Classes/Service/Guardrail/GuardrailInterface.php` | `interface GuardrailInterface` | asserted |
| `implementedBy` | `Classes/Service/Guardrail/GuardrailPolicyResolver.php` | `Classes/Service/Guardrail/GuardrailPolicyResolver.php` | `class GuardrailPolicyResolver` | asserted |
