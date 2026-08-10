# OpenSSF Baseline Compliance

This document attests how `nr-llm` meets the
[OpenSSF Baseline](https://baseline.openssf.org/) requirements for open
source projects, in the spirit of the maintained Baseline criteria
catalogue. Each criterion below is mapped to its concrete artefact in
this repository.

Last verified: 2026-08-10

This file states what is enforced, not what is aimed at. A criterion that is a
goal rather than a gate says so in its own row, and anything not met is in
[Known gaps](#known-gaps) below. `Tests/Unit/BaselineConsistencyTest.php` fails
CI when the CI matrix claimed here drifts from `.github/workflows/ci.yml`.

## Vulnerability Management

| Criterion | Artefact |
|---|---|
| Vulnerability disclosure policy | [SECURITY.md](SECURITY.md) — GitHub Private Vulnerability Reporting + advisory link |
| Response SLA | Documented in `SECURITY.md` (Critical: 7 days, High: 30 days) |
| Coordinated disclosure | GitHub Security Advisories used; no public-issue-then-fix pattern |
| Dependency vulnerability scanning | `composer audit` runs in CI via `netresearch/typo3-ci-workflows` security workflow; Dependabot configured (`.github/dependabot.yml`) |

## Source Code Integrity

| Criterion | Artefact |
|---|---|
| Source under public version control | This repository on github.com/netresearch/t3x-nr-llm |
| Required signed commits | `git commit -S --signoff` enforced via `Build/captainhook.json` and branch protection (`required_signatures: enabled`) |
| Code review before merge | All changes via pull request; `pr-quality` reusable workflow plus Copilot/Gemini reviews. **Not enforced by branch protection** — `required_approving_review_count` is 0, so a human approval is convention, not a gate |
| Two-person rule | **Not met.** See the gap list below |

## Build Integrity

| Criterion | Artefact |
|---|---|
| Reproducible build configuration | Composer-based; `Build/Scripts/runTests.sh` Docker runner pins PHP versions explicitly |
| SBOM generation | Released archives include CycloneDX SBOM via the netresearch typo3-ci-workflows release workflow |
| Provenance attestation | SLSA Level 3 via `actions/attest-build-provenance` (centrally provided by the org reusable release workflow) |
| Artefact signing | Cosign keyless signing on releases (org reusable workflow) |

## Quality Gates

| Criterion | Artefact |
|---|---|
| Static analysis (SAST) | PHPStan **level 10** across the matrix (with 26 suppressed findings in `Build/phpstan-baseline.neon`), plus `security / SAST (Opengrep)`, `codeql` and SonarCloud |
| Test coverage | PHPUnit unit + integration + functional + fuzzy + E2E suites (`Tests/`), blocking on every PR |
| Mutation testing | Infection, **monitored not enforced**: the `fuzz-mutation` job runs on the weekly schedule only, its Infection step is `continue-on-error`, and the configured targets (MSI 70 / covered MSI 74) are goals the suite is still climbing toward |
| Multi-version CI | PHP 8.2–8.5 × TYPO3 `^13.4` / `^14.3` matrix in `.github/workflows/ci.yml` (merge-queue runs narrow to PHP 8.2 / 8.4) |
| Code style | PHP-CS-Fixer with `@PER-CS` ruleset enforced in CI |

## Project Governance

| Criterion | Artefact |
|---|---|
| LICENSE | [LICENSE](LICENSE) — GPL-2.0-or-later (SPDX-identified) |
| CONTRIBUTING guide | [CONTRIBUTING.md](CONTRIBUTING.md) — DCO, commit conventions, signing |
| Code of Conduct | [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) — Contributor Covenant 2.0 |
| Code owners | [.github/CODEOWNERS](.github/CODEOWNERS) — Netresearch TYPO3 team review assignment |
| Changelog | [CHANGELOG.md](CHANGELOG.md) — Keep-a-Changelog format |

## Build & Release Operations

| Criterion | Artefact |
|---|---|
| Pinned third-party actions | All third-party actions pinned to commit SHA (verified by `step-security/harden-runner`) |
| Reusable workflow centralisation | CI/security/release delegated to `netresearch/typo3-ci-workflows` and `netresearch/.github` reusable workflows |
| Workflow permissions | `permissions: {}` declared at workflow level; per-job grants only what's needed |
| Container hardening | `step-security/harden-runner` applied via the org reusable workflows |

## Supply-Chain Defenses

| Criterion | Artefact |
|---|---|
| Branch protection | `main` requires signed commits and dismisses stale reviews; it does **not** require an approving review or any status check — see the gap list |
| Dependency review | `actions/dependency-review-action` runs on every PR (via `netresearch/.github` reusable workflow) |
| Auto-merge gating | Auto-merge for Dependabot PRs gates on full CI green + Copilot review (no race condition) |
| Secret scanning | GitHub native secret scanning + Gitleaks in CI |

## Known gaps

- **Branch protection: required status checks are `null`.** No check is
  configured as required on the GitHub side; merges are gated by the
  merge queue and by convention, not by branch protection. Verify with
  `gh api repos/netresearch/t3x-nr-llm/branches/main/protection`.
- **Two-person rule not enforced.** `required_approving_review_count` is 0.
- **Mutation testing is not a gate.** Weekly, report-only,
  `continue-on-error`; MSI 70 / covered MSI 74 are targets.
- **No current full-scope security audit.** The last one is from 2026-01-05
  and is archived as historical; see [SECURITY_AUDIT.md](SECURITY_AUDIT.md)
  for what *is* continuously verified.

## How to verify

```bash
# Re-run the full assessment that produced this attestation
/assess          # interactive
# or:
bash ~/.claude/skills/automated-assessment/scripts/run-checkpoints.sh \
    --json ~/.agents/skills/enterprise-readiness/checkpoints.yaml .
```

## Reporting drift

If you notice a Baseline criterion that has slipped (e.g. an action no
longer SHA-pinned, missing SBOM in a release), please open a
[security advisory](https://github.com/netresearch/t3x-nr-llm/security/advisories/new)
or a regular issue tagged `compliance/baseline`.
