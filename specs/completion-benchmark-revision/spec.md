<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# Completion benchmark revisions

Generic completion evaluations currently compare a mutable set identifier, and
routing averages different tasks. Record the declared benchmark separately from
the already verified serving generator (ADR-220).

## Requirements and verification

1. Hash ordered prompt IDs, content, effective caller system prompts, assertion
   types/values, references, explicit generation options and versioned grading
   protocol. Preserve input bytes; exclude provider/model and display labels.
   Unit: content/option/protocol mutations change the hash; generator changes do
   not; identical inputs and arbitrary bytes remain deterministic.
   Fold in ordered digests of final adapter-bound messages/options after local
   configuration, skill injection, context fitting and input screening. Missing
   effective-request evidence means unknown; output redaction invalidates it.
   Unit: actual default configuration system/options changes yield new revisions
   with the same generator. Functional: unchanged cache replay preserves evidence,
   transformed output remains unknown. Custom service/old cache proof is not invented.
   Only deterministic grading has proven effective grading inputs. Decision grader
   scores/history remain available, but no comparable revision is emitted until
   effective judge request identity is available; a profile/model label alone is
   insufficient. CLI strict regression checks fail with an explicit reason.
2. Save a content-free fingerprint in the existing benchmark column. Retain
   legacy history without inventing missing identity. Functional: round trip at
   metadata privacy; legacy rows cannot be a revision-specific baseline.
3. Select baselines by set, fingerprint, grader and all generator dimensions
   before choosing the latest row. Unit/functional: newer incompatible rows do
   not hide an older matching baseline; exact string checks survive broad SQL
   collation matches. CLI prints identity and skips incapable custom repositories.
4. Routing selects one explicit set and fingerprint through extension settings.
   Missing selection/evidence supplies unknown quality, while zero stays zero.
   Unit/functional: configured model ranking only uses the selected benchmark.

## Compatibility, limits and boundaries

No existing @api signature changes. Add an optional internal repository capability;
old repository interfaces and history reads remain callable. Core quality routing
now needs explicit benchmark selection and re-evaluation, an intentional behavior
change with migration documentation. A fingerprint binds declared and observable
adapter-bound inputs, not provider-internal defaults, model training data or
an immutable model snapshot. Unsupported custom completions remain unknown.
Model and grader identities remain independently necessary. No raw prompts,
options or credentials enter the fingerprint column.
No inference calls occur in tests. Existing retrieval identity is unchanged.

## Completion

Targeted unit/SQLite functional tests, then the repository make gate and docs
render coordinated by the parent task. Documentation is verified by inspection
and the official renderer.
