# OpenSSF Baseline Compliance

This document attests how `nr-llm` meets the
[OpenSSF Baseline](https://baseline.openssf.org/) requirements for open
source projects, in the spirit of the maintained Baseline criteria
catalogue. Each criterion below is mapped to its concrete artefact in
this repository.

Last verified: 2026-04-24

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
| Code review before merge | All changes via pull request; PR Quality Gates workflow + Copilot/gemini reviews |
| Two-person rule | PR review required; auto-approve only after Copilot review completes |

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
| Static analysis (SAST) | PHPStan **level 10** (clean baseline), Opengrep, CodeQL — all in CI matrix |
| Test coverage | PHPUnit unit + integration + functional + fuzzy + E2E suites (`Tests/`); minimum MSI 70% via Infection mutation testing |
| Multi-version CI | PHP 8.2–8.5 × TYPO3 13.4 / 14.0 matrix in `.github/workflows/ci.yml` |
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
| Branch protection | Main branch requires signed commits; review required; stale review dismissal enabled |
| Dependency review | `actions/dependency-review-action` runs on every PR (via `netresearch/.github` reusable workflow) |
| Auto-merge gating | Auto-merge for Dependabot PRs gates on full CI green + Copilot review (no race condition) |
| Secret scanning | GitHub native secret scanning + Gitleaks in CI |

## Known gaps / continuous improvement

- **Branch protection: required status checks** — currently `null` on
  the GitHub side. The PR Quality Gates workflow enforces equivalent
  blocks via auto-approve gating, but explicit required-checks
  configuration would be cleaner. Tracked for follow-up.

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
