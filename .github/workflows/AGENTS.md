<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-03-14 -->

# AGENTS.md — workflows

<!-- AGENTS-GENERATED:START overview -->
## Overview
GitHub Actions workflows and CI/CD automation
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
| `labeler.yml` | PR auto-labelling |
| `pages.yml` | Landing page (GitHub Pages) |
| `release.yml` | Release with SBOM, Cosign signing, SLSA attestation; publishes TER + Packagist |
| `republish.yml` | Re-publish an existing tag (`workflow_dispatch` fallback) |
<!-- AGENTS-GENERATED:END filemap -->

<!-- AGENTS-GENERATED:START golden-samples -->
## Workflow files
- One file per concern; the Key Files table above lists all of them.
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
  workflows/
    ci.yml              → Main CI workflow (lint, test, build)
    checks.yml          → Security, licence, CodeQL, scorecard, PR quality
    release.yml         → Release/deploy workflow
    republish.yml       → Re-publish an existing tag (manual)
    e2e.yml             → Playwright E2E tests
    docs.yml            → Documentation rendering
    pages.yml           → Landing page (GitHub Pages)
    dco.yml             → DCO sign-off
    labeler.yml         → PR auto-labelling
    check-template-drift.yml → Template drift
    auto-merge-deps.yml → Auto-merge dependency PRs
    community.yml       → Community health
```
<!-- AGENTS-GENERATED:END structure -->

<!-- AGENTS-GENERATED:START code-style -->
## Workflow conventions
- **Pin action versions** with full SHA, not tags (`uses: actions/checkout@abc123...`)
- **Minimal permissions**: Use `permissions:` block, never use `permissions: write-all`
- **Reusable workflows**: Extract common patterns to `.github/workflows/reusable-*.yml`
- **Job dependencies**: Use `needs:` to express dependencies
- **Caching**: Use `actions/cache` for dependencies (npm, composer, go)

### Naming conventions
| Type | Convention | Example |
|------|------------|---------|
| Workflow file | `<purpose>.yml` | `ci.yml`, `release.yml` |
| Workflow name | Title Case | `CI Pipeline`, `Release` |
| Job ID | kebab-case | `build-and-test`, `deploy-staging` |
| Step name | Sentence case | `Install dependencies` |
| Secret | SCREAMING_SNAKE | `DEPLOY_TOKEN`, `NPM_TOKEN` |
<!-- AGENTS-GENERATED:END code-style -->

<!-- AGENTS-GENERATED:START patterns -->
## Common patterns

### Basic CI workflow
```yaml
name: CI
on:
  push:
    branches: [main]
  pull_request:
    branches: [main]

permissions:
  contents: read

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@11bd71901bbe5b1630ceea73d27597364c9af683 # v4.2.2
      - uses: actions/setup-node@39370e3970a6d050c480ffad4ff0ed4d3fdee5af # v4.1.0
        with:
          node-version: '20'
          cache: 'npm'
      - run: npm ci
      - run: npm test
```

### Matrix builds
```yaml
jobs:
  test:
    strategy:
      matrix:
        os: [ubuntu-latest, macos-latest]
        node: ['18', '20', '22']
    runs-on: ${{ matrix.os }}
    steps:
      - uses: actions/setup-node@39370e3970a6d050c480ffad4ff0ed4d3fdee5af # v4.1.0
        with:
          node-version: ${{ matrix.node }}
```

### Reusable workflow
```yaml
# .github/workflows/reusable-test.yml
on:
  workflow_call:
    inputs:
      node-version:
        type: string
        default: '20'

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: ${{ inputs.node-version }}
```

### Conditional deployment
```yaml
jobs:
  deploy:
    if: github.ref == 'refs/heads/main' && github.event_name == 'push'
    needs: [test, build]
    environment: production
    steps:
      - name: Deploy
        run: ./deploy.sh
```
<!-- AGENTS-GENERATED:END patterns -->

<!-- AGENTS-GENERATED:START security -->
## Security & safety
- **NEVER** expose secrets in logs: use `::add-mask::` for dynamic secrets
- **Pin actions** to full commit SHA, not mutable tags
- **Minimal permissions**: Start with `contents: read`, add only what's needed
- **Environment protection**: Use environments with required reviewers for deploys
- **Secret scanning**: Enable in repository settings
- **Dependency review**: Use `actions/dependency-review-action` for PRs
- **OIDC**: Prefer OIDC over long-lived secrets for cloud providers
<!-- AGENTS-GENERATED:END security -->

<!-- AGENTS-GENERATED:START checklist -->
## PR/commit checklist
- [ ] Actions pinned to full SHA (not tags)
- [ ] Permissions block uses minimal required permissions
- [ ] Secrets are not exposed in logs
- [ ] Workflow syntax valid: `actionlint` or GitHub UI validation
- [ ] Matrix strategy covers required versions/platforms
- [ ] Caching configured for dependencies
<!-- AGENTS-GENERATED:END checklist -->

<!-- AGENTS-GENERATED:START examples -->
## Patterns to Follow
> **Prefer looking at real code in this repo over generic examples.**
> See **Golden Samples** section above for files that demonstrate correct patterns.
<!-- AGENTS-GENERATED:END examples -->

<!-- AGENTS-GENERATED:START help -->
## When stuck
- GitHub Actions docs: https://docs.github.com/en/actions
- Workflow syntax: https://docs.github.com/en/actions/reference/workflow-syntax-for-github-actions
- Action marketplace: https://github.com/marketplace?type=actions
- Use `act` for local testing: https://github.com/nektos/act
- Check existing workflows in this repo for patterns
<!-- AGENTS-GENERATED:END help -->

## Org-owned reusables track `@main`

The "pin to a full SHA, not a tag" rule above covers **third-party** actions only. Every `uses: netresearch/…` reference stays on `@main` — not a tag, not a SHA.

Netresearch reusables are deliberately mutable so an upstream fix reaches all consumers at once. A pin freezes this repo on an old copy and turns each upstream fix into a Renovate bump PR — `republish.yml` was pinned in April 2026 and had already collected one such PR the following day.

The `githubactions:S7637` hotspot this leaves in SonarCloud is accepted. If a quality gate blocks on it, mark the hotspot Safe — do not resolve it by pinning.
