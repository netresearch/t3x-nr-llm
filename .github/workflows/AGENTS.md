<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-08-19 -->

# AGENTS.md — workflows

<!-- AGENTS-GENERATED:START overview -->
## Overview
GitHub Actions workflows and CI/CD automation. **This repository defines no workflow jobs of its own**: every workflow is a thin caller of a shared `netresearch/*` reusable workflow, so action pinning, runner hardening and security review happen once there — for all callers. `composer ci:test:workflows` (pre-commit and CI) refuses a job with local steps; the single exception is the aggregate `gate` job in `checks.yml`, which evaluates the other jobs' results and therefore cannot be a reusable-workflow call.
<!-- AGENTS-GENERATED:END overview -->

<!-- AGENTS-GENERATED:START filemap -->
## Key Files
| File | Purpose |
|------|---------|
| `auto-merge-deps.yml` | Auto-merge dependency PRs |
| `check-template-drift.yml` | Template drift against the org's typo3-extension template |
| `checks.yml` | Security, gitleaks, zizmor, fuzz, licence audit, CodeQL, scorecard, dependency review, PR quality |
| `ci.yml` | Lint, PHPStan, unit/functional tests, Rector, fuzz + weekly mutation, docs |
| `community.yml` | Community health |
| `dco.yml` | DCO sign-off |
| `docs.yml` | Documentation rendering |
| `e2e.yml` | Playwright E2E tests |
| `harness-verify.yml` | Agent-harness consistency (`Build/Scripts/verify-harness.sh`) |
| `labeler.yml` | PR auto-labelling |
| `pages.yml` | Landing page (GitHub Pages) |
| `release.yml` | Release with SBOM, Cosign signing, SLSA attestation; publishes TER + Packagist |
| `republish.yml` | Re-publish an existing tag (`workflow_dispatch` fallback) |
<!-- AGENTS-GENERATED:END filemap -->

<!-- AGENTS-GENERATED:START golden-samples -->
## Workflow files
- One file per concern; the Key Files table above lists all of them.
- `ci.yml` is the intentional-drift file: the per-extension test matrix (PHP 8.2–8.5 × TYPO3 `^13.4`/`^14.3` on PRs, reduced to PHP 8.2/8.4 in the merge queue), `rector-php-version: '8.2'`, the MariaDB functional leg, and fuzz/mutation toggles all live in its `with:` block — read its comments before touching it.
<!-- AGENTS-GENERATED:END golden-samples -->

<!-- AGENTS-GENERATED:START structure -->
## Directory structure
```
.github/
  dependabot.yml        → Dependency updates
  labeler.yml           → PR auto-labeling
  CODEOWNERS            → Code ownership rules
  PULL_REQUEST_TEMPLATE.md
  SECURITY_CONTROLS.md
  ISSUE_TEMPLATE/
  workflows/            → One thin reusable-workflow caller per concern (see Key Files)
```
<!-- AGENTS-GENERATED:END structure -->

<!-- AGENTS-GENERATED:START code-style -->
## Workflow conventions
- **No local jobs.** A repo-specific check goes in the shared workflow's `repo-checks` job, not in a job here: set `run-repo-checks: true` in `ci.yml` and add the command to the `ci:test:repo` composer script. That script is also what pre-commit runs, so the local and CI halves are the same command.
- Why this is a rule and not a preference: a local job carries its own action pins, and a wrong pin does not fail like a normal check. Repo and org both set `sha_pinning_required`, so a tag ref kills the run at `Set up job` — before any step executes, so the log has no step output — and zizmor, Opengrep, CodeQL and SonarCloud then each flag the same lines. On 2026-08-11 that was six red checks for one mistake, none of them naming the cause.
- **Pin third-party actions** to a full commit SHA, never a mutable tag (the shared workflows do this centrally; it only becomes your problem in the exempted `gate` job).
- **Minimal permissions**: top-level `permissions:` block on every workflow; job-level `contents: read` on reusable calls.
<!-- AGENTS-GENERATED:END code-style -->

<!-- AGENTS-GENERATED:START patterns -->
## Common patterns

### Releasing (tag push publishes TER automatically)
Pushing an annotated signed tag `vX.Y.Z` on `main` triggers `release.yml`, which calls the reusable `netresearch/typo3-ci-workflows/.github/workflows/release-typo3-extension.yml` with `TYPO3_TER_ACCESS_TOKEN` wired in: it produces the SBOM / Cosign / SLSA artifacts, creates the GitHub release, **and publishes to TER + Packagist**. `republish.yml` is only a `workflow_dispatch` fallback that re-publishes an existing tag — not the primary path. Verify a release via Packagist (`repo.packagist.org/p2/netresearch/nr-llm.json`, tags `v`-prefixed), TER (`extensions.typo3.org/api/v1/extension/nr_llm/versions`) and the docs 0.X URL — do not assume TER is a separate manual step.

The `chore(release): X.Y.Z` commit bumps four files: `ext_emconf.php`, `composer.json` (`extra.typo3/cms.version`), `Documentation/guides.xml`, and `CHANGELOG.md` (not `Changelog.rst`). Version bumps belong to this release flow, never to a feature PR.

### Dependency PRs
Renovate/Dependabot PRs auto-merge via `auto-merge-deps.yml` — do not hand-merge them by default. Known gap (2026-07-24): it did **not** auto-merge #511/#509 (admin-merged to unblock 0.24.0); root cause (merge-queue interaction vs an unmet workflow condition) is not yet verified — investigate before relying on it for a release.

### Merge-queue stall nudge
A CLEAN PR with armed auto-merge that never enters the queue (`isInMergeQueue: false`) usually just needs a re-evaluation — `gh pr merge --disable-auto` followed by `gh pr merge --auto`. Diagnose before escalating; admin-bypassing the queue is not the fix.
<!-- AGENTS-GENERATED:END patterns -->

<!-- AGENTS-GENERATED:START security -->
## Security & safety
- **NEVER** expose secrets in logs: use `::add-mask::` for dynamic secrets
- **Minimal permissions**: start with `contents: read`, add only what's needed
- **Secret scanning + dependency review** run via `checks.yml` (shared workflow)
- Secrets are wired into reusable calls via the `secrets:` block (`CODECOV_TOKEN`, `TYPO3_TER_ACCESS_TOKEN`), never echoed in `with:` inputs
<!-- AGENTS-GENERATED:END security -->

<!-- AGENTS-GENERATED:START checklist -->
## PR/commit checklist
- [ ] No job with local `steps:` (except the exempted `gate` in `checks.yml`) — `composer ci:test:workflows` enforces
- [ ] Repo-specific checks routed through `ci:test:repo` + `run-repo-checks: true`, not a new job
- [ ] `netresearch/*` reusables referenced `@main` (see below); third-party actions SHA-pinned
- [ ] Permissions blocks minimal
- [ ] Matrix changes mirrored where required checks are configured (reduced merge-queue matrix is intentional)
<!-- AGENTS-GENERATED:END checklist -->

<!-- AGENTS-GENERATED:START examples -->
## Patterns to Follow
> **Prefer looking at real workflows in this repo over generic examples.**
> `ci.yml` (reusable call with per-repo matrix and heavily commented trade-offs) and `checks.yml` (aggregate gate exception) demonstrate the two shapes that exist here.
<!-- AGENTS-GENERATED:END examples -->

<!-- AGENTS-GENERATED:START help -->
## When stuck
- GitHub Actions docs: https://docs.github.com/en/actions
- The shared reusable workflows live in `netresearch/typo3-ci-workflows` and `netresearch/.github` — read the called workflow before changing a `with:` input
- `composer ci:test:workflows` locally reproduces the CI workflow-ownership check
- Check existing workflows in this repo for patterns
<!-- AGENTS-GENERATED:END help -->

## Org-owned reusables track `@main`

The "pin to a full SHA, not a tag" rule above covers **third-party** actions only. Every `uses: netresearch/…` reference stays on `@main` — not a tag, not a SHA.

Netresearch reusables are deliberately mutable so an upstream fix reaches all consumers at once. A pin freezes this repo on an old copy and turns each upstream fix into a Renovate bump PR — `republish.yml` was pinned in April 2026 and had already collected one such PR the following day.

The `githubactions:S7637` hotspot this leaves in SonarCloud is accepted. If a quality gate blocks on it, mark the hotspot Safe — do not resolve it by pinning.
