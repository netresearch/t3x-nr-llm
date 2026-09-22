# OpenSSF Baseline assessment

This document maps `nr-llm` to
[OSPS Baseline v2026.08.28](https://baseline.openssf.org/versions/2026-08-28).
The assessment target is **Level 1**. Further practices below are supporting
evidence, not a claim that Level 2 or Level 3 is achieved.

The [live Baseline entry](https://www.bestpractices.dev/en/projects/11697/baseline-1)
and its badge show the status recorded on bestpractices.dev. This document
does not award a badge. The separate Passing / Silver / Gold series does
not establish Baseline compliance; both series are project self-assessments,
not independent security certifications.

Last verified: 2026-09-21

Scope: source/configuration at
[`cadc97812ff180a2a242c86c0eb3f4f48cd04500`](https://github.com/netresearch/t3x-nr-llm/tree/cadc97812ff180a2a242c86c0eb3f4f48cd04500),
the Python-cache cleanup documented below, live organisation/repository settings
and branch rules, and the v0.35.0 release-asset inventory. The v0.35.0 ZIP's
licence contents and provenance were verified as described below. Standalone
Sigstore bundles, complete pipeline trust boundaries and the application as a
whole were not independently audited. Reusable workflows follow `@main` and need
to be checked again at their resolved revision when reassessing.

`Tests/Unit/BaselineConsistencyTest.php` checks the stated TYPO3 matrix,
mutation wording and verification date. It does not validate remote settings,
every control, human reviews or certification levels.

## Level 1 control mapping

**Evidence present** means the cited source or setting supports the control;
**review needed** means the evidence is insufficient to mark it met. These
are local assessment labels, not values already submitted to the badge
service. Do not submit unresolved rows or unmerged remediations as met.

| Control | Requirement summary | Evidence and assessment |
|---|---|---|
| OSPS-AC-01.01 | MFA for sensitive repository access | **Evidence present:** [organisation settings](https://api.github.com/orgs/netresearch) report `two_factor_requirement_enabled: true` when read with an authorised account. |
| OSPS-AC-02.01 | Manual permissions or lowest-privilege defaults for new collaborators | **Review needed:** organisation `default_repository_permission` is `write`; verify the manual permission-assignment/onboarding process before claiming this control. |
| OSPS-AC-03.01 | Prevent direct commits to the primary branch | **Evidence present:** the active `t3x-pull-request` ruleset requires pull requests; its administrator/integration exceptions use `bypass_mode: pull_request`. Human approval is a separate limitation below. |
| OSPS-AC-03.02 | Protect deletion of the primary branch | **Evidence present:** effective rules include `deletion`; classic protection also disables deletion. |
| OSPS-BR-01.01 | Sanitize and validate untrusted pipeline metadata | **Review needed:** most processing is delegated to organisation reusable workflows; their complete metadata handling has not been reviewed at a fixed revision. |
| OSPS-BR-01.03 | Isolate untrusted code from privileged CI credentials/assets | **Review needed:** assess checkout, code execution and credential boundaries in the resolved reusable workflows, including privileged PR automation and the Codecov secret passed by [ci.yml](.github/workflows/ci.yml). |
| OSPS-BR-03.01 | Encrypt official project channels | **Evidence present:** the [repository](https://github.com/netresearch/t3x-nr-llm), [documentation](https://docs.typo3.org/p/netresearch/nr-llm/main/en-us/), issue tracker and security-reporting channel use HTTPS. |
| OSPS-BR-03.02 | Authenticate distribution channels | **Evidence present:** [GitHub releases](https://github.com/netresearch/t3x-nr-llm/releases), [Packagist](https://packagist.org/packages/netresearch/nr-llm) and [TER](https://extensions.typo3.org/extension/nr_llm) use HTTPS. |
| OSPS-BR-07.01 | Prevent accidental storage of unencrypted sensitive data | **Evidence present:** [.gitignore](.gitignore) excludes local environment files; GitHub secret scanning and push protection are enabled; [checks.yml](.github/workflows/checks.yml) runs secret scanning. This does not prove the entire history is secret-free. |
| OSPS-DO-01.01 | Document basic functionality | **Evidence present:** [README.md](README.md), [installation](Documentation/Installation/Index.rst), [configuration](Documentation/Configuration/Index.rst) and [administration](Documentation/Administration/Index.rst) guides. |
| OSPS-DO-02.01 | Document defect reporting | **Evidence present:** the [bug-report template](.github/ISSUE_TEMPLATE/bug_report.yml) requests reproduction steps, expected behaviour and TYPO3/PHP versions through the [issue tracker](https://github.com/netresearch/t3x-nr-llm/issues/new?template=bug_report.yml). |
| OSPS-GV-02.01 | Public discussion of changes and usage obstacles | **Evidence present:** [issues](https://github.com/netresearch/t3x-nr-llm/issues), [pull requests](https://github.com/netresearch/t3x-nr-llm/pulls) and [discussions](https://github.com/netresearch/t3x-nr-llm/discussions). |
| OSPS-GV-03.01 | Explain contribution process | **Evidence present:** [CONTRIBUTING.md](CONTRIBUTING.md) covers development, testing and pull requests. |
| OSPS-LE-02.01 | Free/open-source source licence | **Evidence present:** [composer.json](composer.json) declares `GPL-2.0-or-later`; [LICENSE](LICENSE) contains GPL version 2 terms. |
| OSPS-LE-02.02 | Free/open-source released-asset licence | **Evidence present:** the downloaded [v0.35.0 ZIP](https://github.com/netresearch/t3x-nr-llm/releases/download/v0.35.0/nr-llm-0.35.0.zip) contains GPL version 2 terms and `composer.json` declaring `GPL-2.0-or-later`. The release workflow uses `git archive` for the ZIP and TAR archives. |
| OSPS-LE-03.01 | Licence in the source repository | **Evidence present:** [LICENSE](LICENSE) is tracked at the root. |
| OSPS-LE-03.02 | Licence included with released software | **Evidence present:** the downloaded v0.35.0 ZIP contains `nr-llm/LICENSE`. Its SHA-256 matches GitHub's asset digest, `5f2537d7c34f94d8c55bf04959f5acae0ca9a8a25ae883bf5ac0660690f208b0`. This inspection covers that ZIP; [.gitattributes](.gitattributes) also retains the licence in other `git archive` packages. |
| OSPS-QA-01.01 | Public source at a stable URL | **Evidence present:** [netresearch/t3x-nr-llm](https://github.com/netresearch/t3x-nr-llm) is the authoritative public repository. |
| OSPS-QA-01.02 | Public history of changes, authors and dates | **Evidence present:** the [Git history](https://github.com/netresearch/t3x-nr-llm/commits/main/) records commits and retained pull-request merges. |
| OSPS-QA-02.01 | List direct language dependencies | **Evidence present:** [composer.json](composer.json), [package.json](package.json) and [reranker requirements](Build/reranker/requirements.txt). |
| OSPS-QA-04.01 | List codebases for a project spanning multiple repositories | **Not applicable:** [ADR-090](Documentation/Adr/Adr090SingleExtensionUntil10.rst) and [ADR-159](Documentation/Adr/Adr159OneExtensionConfirmedAtTheFreeze.rst) define one extension from one repository; the release archives this repository. Host-installed nr-vault is an external Composer dependency, not a project subrepository incorporated into the archive. |
| OSPS-QA-05.01 | No generated executable artefacts in version control | **Evidence present:** generated caches for [reranker/app.py](Build/reranker/app.py) and [check_accessibility.py](landingpage/build/check_accessibility.py) were removed; their reviewable sources remain. [.gitignore](.gitignore) excludes caches and bytecode. Re-check the tracked-file inventory before submission. |
| OSPS-QA-05.02 | No unreviewable binary artefacts in version control | **Evidence present:** the same compiled Python caches were removed. Ordinary media assets are excluded from this control. Re-check the tracked-file inventory before submission. |
| OSPS-VM-02.01 | Publish security contacts | **Evidence present:** [SECURITY.md](SECURITY.md) points to [private vulnerability reporting](https://github.com/netresearch/t3x-nr-llm/security/advisories/new). |

## Branch-rule evidence

Branch rules come from **two sources that coexist and compose**, and GitHub
applies the most restrictive: classic branch protection *and* rulesets. Read
both — `gh api repos/netresearch/t3x-nr-llm/rules/branches/main` for the
rulesets, `…/branches/main/protection` for the classic settings.

Reading only the classic endpoint gives a false all-clear. It omits
`required_status_checks` entirely (`null`) when a ruleset supplies them, and
its `required_approving_review_count: 0` is the classic setting, not the
effective one — a ruleset requires 1. An earlier revision of this file read
that endpoint alone and attested that neither reviews nor checks were required.

## Vulnerability Management

| Criterion | Artefact |
|---|---|
| Vulnerability disclosure policy | [SECURITY.md](SECURITY.md) — GitHub Private Vulnerability Reporting + advisory link |
| Response commitments | `SECURITY.md`: initial response within 48 hours, update within 7 days, fixes critical ASAP / high 2 weeks / medium 1 month. These are policy commitments, not measured response performance |
| Coordinated disclosure | `SECURITY.md` directs reporters to GitHub Private Vulnerability Reporting rather than public issues |
| Dependency vulnerability scanning | `composer audit` runs in CI via the `netresearch/typo3-ci-workflows` security workflow; [renovate.json](renovate.json) configures dependency updates |

## Source Code Integrity

| Criterion | Artefact |
|---|---|
| Source under public version control | This repository on github.com/netresearch/t3x-nr-llm |
| Signed commits and sign-off | Signed commits are required by branch rules; `dco / DCO` is a required check. `git commit -S --signoff` is the documented contributor command; local hooks alone do not enforce repository policy |
| Approval before merge | Ruleset `t3x-pull-request` requires 1 approval, resolved review threads and stale-review dismissal, with exceptions below. An approval can come from automation |
| Human two-person rule | **Not established.** [PR #947](https://github.com/netresearch/t3x-nr-llm/pull/947#pullrequestreview-5246953353) has an approval from `github-actions[bot]`. AI reviews and bot approvals do not satisfy Level 3 OSPS-QA-07.01's non-author human approval requirement |

## Build Integrity

| Criterion | Artefact |
|---|---|
| Build configuration | Composer-based; `Build/Scripts/runTests.sh` delegates to the shared Docker runner. Selecting a PHP version does not by itself prove reproducible builds |
| SBOM generation | [v0.35.0](https://github.com/netresearch/t3x-nr-llm/releases/tag/v0.35.0) has separate CycloneDX and SPDX SBOM assets |
| Provenance attestation | The v0.35.0 ZIP's SLSA provenance/v1 attestation passed `gh attestation verify` restricted to the [central release workflow](https://github.com/netresearch/typo3-ci-workflows/blob/main/.github/workflows/release-typo3-extension.yml). This verifies that archive's provenance and signer, not independent conformance of the build architecture to SLSA Level 3 |
| Artefact signing | Cosign keyless signing is configured centrally; v0.35.0 has signature bundles for archives, SBOMs and checksums. Bundle presence is not cryptographic verification |

## Quality Gates

| Criterion | Artefact |
|---|---|
| Static analysis (SAST) | PHPStan **level 10** across the matrix, with 26 suppression entries accounting for 40 findings in `Build/phpstan-baseline.neon`; required PHPStan contexts cover PHP 8.4 with both TYPO3 majors. Opengrep and CodeQL run through the security workflow; SonarCloud reports separately |
| Test suites | Unit, integration, functional, fuzzy and E2E suites exist under `Tests/`. Selected unit/functional matrix cells, fuzzy and E2E contexts are required; this does not establish a coverage percentage or make every suite/cell a merge gate |
| Mutation testing | Infection, **monitored not enforced**: `fuzz-mutation` runs fuzzy tests on each configured event, but enables `run-mutation-tests` on the weekly schedule only. Its Infection step is `continue-on-error`; MSI 70 / covered MSI 74 are configured targets |
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
| Action pinning | Local third-party action references are SHA-pinned; organisation reusable workflows follow `@main` and need assessment at their resolved revision. [Build/reranker/Dockerfile](Build/reranker/Dockerfile) uses the mutable base image `python:3.14-slim` without a digest. `step-security/harden-runner` does not certify dependency pinning |
| Reusable workflow centralisation | CI/security/release delegated to `netresearch/typo3-ci-workflows` and `netresearch/.github` reusable workflows |
| Workflow permissions | `permissions: {}` at workflow level in 14 of the 15 workflows; `ci.yml` declares `contents: read`. Reusable calls grant explicit per-job permissions; verifying least privilege also requires inspecting the called workflow |
| CI runner monitoring/hardening | `step-security/harden-runner` applied via the organisation reusable workflows; this is not evidence of application-container hardening |

## Supply-Chain Defenses

| Criterion | Artefact |
|---|---|
| Branch protection | Rulesets on `main`, all `enforcement: active`: `main-branch-rules` (16 required status contexts, merge queue), `t3x-pull-request` (1 approval, thread resolution, stale-review dismissal), `require-signed-commits`, `Copilot review for default branch` (which also carries the no-deletion and no-force-push rules). Classic protection adds signed commits, conversation resolution, and denies force-push and deletion |
| Dependency review | `actions/dependency-review-action` runs on every PR (via `netresearch/.github` reusable workflow) |
| Dependency auto-merge | [.github/workflows/auto-merge-deps.yml](.github/workflows/auto-merge-deps.yml) delegates to the organisation workflow. Actual merge enforcement follows the live required contexts and bypass settings; configuration alone does not prove race-free behaviour |
| Secret scanning | GitHub native secret scanning + Gitleaks in CI |

## Known gaps

- **Level 1 evidence remains incomplete.** Resolve the review-needed rows
  above before completing the platform assessment. Submit evidence only
  after verifying that the assessed state is on the default branch.
- **A repository role bypasses both rulesets.** `main-branch-rules` grants
  `RepositoryRole` id 5 `bypass_mode: always`, and `t3x-pull-request` grants the
  same role plus two GitHub App integrations `bypass_mode: pull_request`. That
  holder can merge red and unreviewed; the rules hold for everyone else.
  Classic protection's `enforce_admins` is likewise `false`.
- **Human review is not guaranteed.** Bot approvals and bypass exceptions
  prevent treating the approval setting as proof of OSPS-QA-07.01.
- **Several checks report without blocking.** The required contexts omit
  `ci / All CI checks`, ESLint and SonarCloud. `All security checks` covers
  the jobs in `checks.yml`, not every workflow or every CI matrix cell.
- **Mutation testing is not a gate.** Weekly, report-only,
  `continue-on-error`; MSI 70 / covered MSI 74 are targets.
- **No current full-scope security audit.** The last one is from 2026-01-05
  and is archived as historical; see [SECURITY_AUDIT.md](SECURITY_AUDIT.md)
  for the scope of continuous and later targeted verification. Its claim
  that SonarCloud is the only non-blocking check is also stale.
- **Security policy needs updating.** `SECURITY.md` still lists only 0.13.x
  as supported and suggests API-key storage that does not describe today's
  nr-vault integration. This review does not invent a new support policy;
  Level 3 support-lifecycle claims need current evidence.

## How to verify

```bash
# Read the recorded Baseline status (separate from Passing / Silver / Gold)
curl -fsSL https://www.bestpractices.dev/projects/11697.json |
  jq '{baseline_tiered_percentage, badge_percentage_baseline_1,
       badge_percentage_baseline_2, badge_percentage_baseline_3}'
gh api orgs/netresearch \
  --jq '{two_factor_requirement_enabled, default_repository_permission}'
gh api repos/netresearch/t3x-nr-llm/rules/branches/main
gh api repos/netresearch/t3x-nr-llm/branches/main/protection
gh api repos/netresearch/t3x-nr-llm/rulesets/11581773
gh api repos/netresearch/t3x-nr-llm/rulesets/20547646
git ls-files '*__pycache__*' '*.pyc' '*.pyo'
./Build/Scripts/runTests.sh -s unit -- --filter BaselineConsistencyTest

# Verify a downloaded release ZIP against the expected reusable signer
gh attestation verify nr-llm-0.35.0.zip \
  --repo netresearch/t3x-nr-llm \
  --signer-workflow netresearch/typo3-ci-workflows/.github/workflows/release-typo3-extension.yml
```

The generic `tiered_percentage` JSON field belongs to Passing / Silver /
Gold; `baseline_tiered_percentage` belongs to Baseline. Re-check each control
against the official version and its evidence before updating the platform.

## Reporting drift

If you notice a Baseline criterion that has slipped (e.g. an action no
longer SHA-pinned, missing SBOM in a release), please open a
[security advisory](https://github.com/netresearch/t3x-nr-llm/security/advisories/new)
or a regular issue tagged `compliance/baseline`.
