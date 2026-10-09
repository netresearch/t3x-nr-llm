<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# Retrieval evaluation provenance — ADR-215

ADR-215 is the decision. This specification extends the existing retrieval evaluator and result log; it does not introduce another registry, vector store or evaluation subsystem. Implementation follows the separate accepted ADR change.

## What it must do

1. **An optional capability declares the retrieval run.** Add `RetrievalProvenanceProviderInterface` and a readonly `RetrievalProvenance` value object as additive `@api` declarations. The interface exposes `getRetrievalProvenance(): RetrievalProvenance`. Its bounded scalar fields are `corpusRevision`, `modelRevision`, `chunkingIdentity`, `pipelineIdentity` and nullable `executionRevision`. Required identities must be nonempty; no arbitrary options, corpus content or credentials are accepted. Model/chunking may use a documented explicit not-applicable sentinel for lexical retrieval; missing capability or missing revision is unknown, never that sentinel. The existing `EvaluatableRetrieverInterface` and golden-set-provider interface stay byte-for-byte source-compatible.
2. **Labels are derived from the set.** A versioned digest covers question id, question text, form, hard class and sorted expected document ids, with questions sorted by id. Duplicate and empty ids remain refused by the existing constructors. Sort order, answer gist and presentation name/description do not change the digest. A scoring-label change does. Canonical encoding has explicit format and scoring-protocol versions and unambiguous boundaries, so concatenation cannot create a collision.
3. **Benchmark and variant identities have different meanings.** The benchmark fingerprint binds corpus revision, labels digest and the distinct-document top-1/top-3/no-result scoring version. The variant fingerprint binds model revision, chunking identity and pipeline identity. Execution revision is recorded independently. Missing provenance produces a null/empty stored fingerprint, distinguishable from every measured fingerprint and from an explicit not-applicable model. Changing only the execution revision does not change either fingerprint.
4. **The exact measurement survives conversion.** The result's persistable detail snapshot includes question id, form, hard class, top-1/top-3 verdicts, distinct document ids in the measured ranking and latency. The existing evaluator's top-3 depth, fourfold overfetch, deduplication, blank-id handling, multi-target and no-result semantics are unchanged. The preserved rank list is the measured distinct top three, not a claim to retain the full raw ranking.
5. **One row persists summary, identity and permitted details.** Extend `tx_nrllm_eval_result` additively with provenance and benchmark/variant identity metadata. Existing prompt-result construction/writes work with optional trailing values; old rows with default new columns read as unknown. Fingerprints and bounded content-free provenance metadata remain stored when privacy is metadata-only. Rankings, question text and other detailed content do not bypass `PrivacyPolicyInterface::filterContent()`; no new unrestricted detail sink is created. Existing purge removes all new values together with their row.
6. **One read returns the identity needed for comparison.** `EvaluationResultSummary` or the existing repository's internal read path carries the new fingerprints/provenance through a DB roundtrip. Existing prompt-evaluation callers and repository doubles remain valid. The repository continues ordering newest first by run date and uid and uses the same set/retriever/grader scope.
7. **A regression is a compatible comparison, not merely two numbers.** With a previous row, compare rates only if both benchmark fingerprints are known and equal. Known unequal fingerprints yield `mismatch`; either absent fingerprint yields `unknown`. Neither state computes or displays pass/score deltas or a no-regression assertion. The command records the current measurement in both cases. `--fail-on-regression` returns failure on either state; without it the measurement succeeds with an explicit skipped-comparison message. A known first run records a baseline; an unknown first run cannot pass the strict flag.
8. **Changing a treatment remains measurable.** Equal benchmark identity with different variant identity still compares the existing top-1/top-3 rates and reports that the model/chunking/pipeline treatment changed. A code execution revision alone never blocks comparison. No threshold or rate is silently reinterpreted, and the ordinary regression exit result is unchanged for compatible runs.
9. **The existing command and registry remain the consumer entrypoints.** `nrllm:eval:retrieval <set> <retriever>` continues finding providers via the existing DI registries and prints the same top-1/top-3/by-form/by-hard-class/latency information. It additionally reports declared provenance and baseline state without exposing secrets. No implicit LLM call or paid grader is added.
10. **The absence of proof is visible.** Documentation distinguishes consumer-declared corpus/model revisions from verified snapshots. A model alias alone does not prove fixed weights. Legacy rows are not retroactively assigned revisions. The CLI behavioral tightening and migration behavior are documented explicitly.

## What it must not do

- Add required members to either existing public extension interface, change top-k scoring, or make the core own a consumer's golden questions.
- Replace `EvaluationResultRepository`, the privacy model or retention with a parallel subsystem.
- Treat a changed model/pipeline as a changed benchmark; treat a Git commit change by itself as benchmark incompatibility; or call an unmeasured comparison successful.
- Persist raw options JSON, API keys, document bodies, tool arguments or endpoint credentials as provenance.
- Change the normal request pipeline, add remote calls to collect metadata, introduce MRR/nDCG, run benchmarks in a user request, or claim a quality improvement without a real measurement.

## Which suite proves what

| Requirement | Suite | Proof |
|---|---|---|
| Old retriever and provider implement the unchanged interfaces; new capability is additive | unit | Existing `EvaluatableRetrieverRegistryTest`, `GoldenQuestionSetRegistryTest`, `ApiSurfaceSnapshotTest`; new capability fixture |
| Bounded declarations, invalid values refused, unknown distinct from not applicable | unit, fuzzy | New `RetrievalProvenanceTest` and boundary/roundtrip property cases |
| Canonical label digest invariant to presentation/order; relevant labels change it; boundary-safe encoding | unit, fuzzy | New provenance/digest tests with permutations and concatenation twins |
| Benchmark changes for corpus/labels/scoring; variant changes only for model/chunking/pipeline; execution revision does not change either | unit | New identity tests |
| Ranking/form/hard class/latency/hits survive conversion; scoring remains distinct-document top-k | unit | `RetrievalSetEvaluationResultTest`, `RetrievalEvaluationServiceTest` |
| All new columns and permitted detail values roundtrip; old rows/default constructors remain readable | functional | `EvaluationResultRepositoryTest` with SQLite fixture, legacy-row and new-row cases |
| Metadata-only drops detail ids/text while keeping safe metadata; redacted/full keep their current semantics | functional | `EvaluationResultRepositoryTest` with privacy doubles/policies |
| Purge removes provenance/details with summary; prompt results stay unchanged | functional | `EvaluationResultRepositoryTest` |
| First baseline, compatible regression/no regression, corpus/label mismatch and unknown legacy/current metadata have defined output and exit status | unit | `RetrievalEvalRunCommandTest` |
| Variant difference compares and is reported; execution-only change compares normally | unit | `RetrievalEvalRunCommandTest` |
| Valid DI capability works through existing registry and command; old retriever still runs | functional, unit | Registry fixture and existing command tests |
| ADR links, additive API inventory, RST documentation and CHANGELOG are coherent | unit, documentation | ADR lifecycle/reference tests, API snapshot; documentation render and review |

## Implementation and validation

Change evaluation value objects, command and repository only where the facts cross their current boundaries, plus `ext_tables.sql`, the tests above, `Documentation/Developer/QualityEvaluation.rst`, an API contract page and the additive API snapshot. New PHP source and all existing PHP edits use the required `php-ast-edit` engine. No source edits occur in the shared ADR worktree.

Run focused unit/functional suites through `Build/Scripts/runTests.sh`, then `make gate`; never invoke PHPUnit, PHPStan or Rector directly. Follow `Tests/AGENTS.md` for the separate PHP-8.2 dependency resolution required by the Rector matrix cell and for the difference between one locally tested matrix cell and CI. Documentation is RST with 80-column lines and captioned code examples. The implementing PR records the measured checks and links this spec and ADR-215.
